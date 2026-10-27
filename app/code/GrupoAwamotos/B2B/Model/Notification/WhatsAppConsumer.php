<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Notification;

use GrupoAwamotos\B2B\Api\Data\WhatsAppMessageInterface;
use Psr\Log\LoggerInterface;

class WhatsAppConsumer
{
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [5, 60];

    public function __construct(
        private readonly WhatsAppService $whatsAppService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process a queued WhatsApp notification message.
     *
     * A2 (19/10/2026): retry com backoff limitado ANTES de desistir. Cada mensagem NOVA
     * (consumida da fila) tem ate MAX_ATTEMPTS tentativas (backoff 5s/60s). Se todas
     * falharem, a excecao e relancada e o framework marca ERROR (6) — terminal e visivel.
     * Mensagens ja em ERROR (as 11 atuais) NAO sao reentregues pelo consumer — continuam
     * aguardando decisao manual (o dado pode estar desatualizado).
     *
     * @param WhatsAppMessageInterface $message
     */
    public function process(WhatsAppMessageInterface $message): void
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $this->processOnce($message);
                return;
            } catch (\Throwable $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $this->logger->warning(sprintf(
                        '[B2B WhatsAppConsumer] Tentativa %d/%d falhou (%s) — nova tentativa em %ds.',
                        $attempt,
                        self::MAX_ATTEMPTS,
                        $e->getMessage(),
                        self::BACKOFF_SECONDS[$attempt - 1] ?? 5
                    ), [
                        'type' => $message->getType(),
                    ]);
                    sleep(self::BACKOFF_SECONDS[$attempt - 1] ?? 5);
                    continue;
                }

                $this->logger->error('[B2B WhatsAppConsumer] Failed to process message.', [
                    'type' => $message->getType(),
                    'error' => $e->getMessage(),
                    'payload' => $message->getPayload(),
                ]);

                /* NAO engolir: relancando, o Magento\Framework\MessageQueue\Consumer chama
                   Queue::reject($msg, false, $msg) e o status vira ERROR (6) — terminal.
                   \Throwable nao-Exception e reembrulhado para o catch do framework. */
                throw $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Executa UMA tentativa de processamento (logica original do consumer).
     *
     * @throws \RuntimeException quando o provedor recusa, quando nenhuma tentativa de envio
     *         foi feita ou quando o tipo de mensagem e desconhecido
     */
    private function processOnce(WhatsAppMessageInterface $message): void
    {
        $type = $message->getType();
        $data = json_decode($message->getPayload(), true) ?? [];

        try {
            /* task-67 (11/10/2026): TODOS os cases descartavam o retorno do envio. Uma recusa da
               Z-API (['success' => false]) era gravada como COMPLETE na fila — 28 mensagens
               COMPLETE com number_of_trials = 0, ou seja "concluido" sem ninguem ter recebido.
               Agora o retorno e conferido por sendOrFail() e a falha vira excecao, que o
               framework traduz em status ERROR (6) — terminal e visivel, nao COMPLETE.
               A semantica de negocio (o que a mensagem diz e para quem) NAO mudou. */
            switch ($type) {
                case 'new_registration':
                    $this->sendOrFail($type, $this->whatsAppService->notifyNewB2BRegistration($data), $data);
                    break;
                case 'new_quote':
                    $this->sendOrFail($type, $this->whatsAppService->notifyNewQuote($data), $data);
                    break;
                case 'new_order':
                    $this->sendOrFail($type, $this->whatsAppService->notifyNewOrder($data), $data);
                    break;
                case 'customer_approved':
                    $this->sendOrFail($type, $this->whatsAppService->notifyCustomerApproval($data), $data);
                    break;
                case 'customer_rejected':
                    $this->sendOrFail($type, $this->whatsAppService->notifyCustomerRejection($data), $data);
                    break;
                case 'quote_response':
                    $this->sendOrFail($type, $this->whatsAppService->notifyQuoteResponse($data), $data);
                    break;
                case 'order_status':
                    $this->sendOrFail($type, $this->whatsAppService->notifyOrderStatusUpdate($data), $data);
                    break;
                case 'send_text':
                    $this->sendOrFail(
                        $type,
                        $this->whatsAppService->sendText($data['phone'] ?? '', $data['message_text'] ?? ''),
                        $data
                    );
                    break;
                default:
                    /* Tipo desconhecido nunca vai ser entregue: marcar COMPLETE seria a mesma
                       falha silenciosa. Loga e lanca (o MQ marca ERROR, que e terminal). */
                    $this->logger->error('[B2B WhatsAppConsumer] Unknown message type: ' . $type, [
                        'type' => $type,
                        'payload' => $message->getPayload(),
                    ]);
                    throw new \RuntimeException(sprintf(
                        'WhatsAppConsumer: tipo de mensagem desconhecido "%s"',
                        $type
                    ));
            }
        } catch (\Throwable $e) {
            throw $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Confere o retorno de um envio e transforma falha silenciosa em excecao.
     *
     * O WhatsAppService devolve TRES formatos diferentes (lidos em WhatsAppService.php):
     *   1. folha ................ ['success' => bool, 'message' => string]
     *                             (sendText, notifyCustomerApproval/Rejection, notifyQuoteResponse,
     *                              notifyOrderStatusUpdate)
     *   2. lista ................ [ ['success' => bool, ...], ... ]
     *                             (notifyTeam, usado por notifyNewB2BRegistration e notifyNewQuote)
     *   3. aninhado ............. ['team' => [lista], 'customer' => folha|[]
     *                             (notifyNewOrder)
     * Por isso a conferencia e recursiva. Regra: existe envio bem-sucedido E nenhuma recusa.
     *
     * @param string $type   tipo da mensagem (para o log e a excecao)
     * @param array  $result retorno do servico
     * @param array  $payload payload da mensagem (para registrar o destinatario)
     * @throws \RuntimeException quando ha recusa explicita OU quando nenhuma tentativa foi feita
     */
    private function sendOrFail(string $type, array $result, array $payload): void
    {
        $falhas = [];
        $sucessos = $this->contarEnvios($result, $falhas);

        $destinatario = $payload['phone'] ?? $payload['customer_phone'] ?? null;

        if ($falhas !== []) {
            $this->logger->error(
                '[B2B WhatsAppConsumer] Envio RECUSADO pelo provedor; a mensagem NAO sera marcada como concluida.',
                [
                    'type' => $type,
                    'destinatario' => $destinatario,
                    'envios_ok' => $sucessos,
                    'motivos' => $falhas,
                ]
            );

            throw new \RuntimeException(sprintf(
                'WhatsApp "%s": %d envio(s) recusado(s) — %s',
                $type,
                count($falhas),
                implode(' | ', array_slice($falhas, 0, 3))
            ));
        }

        if ($sucessos === 0) {
            $this->logger->error(
                '[B2B WhatsAppConsumer] Nenhuma tentativa de envio foi feita (payload sem telefone ou nenhum numero configurado).',
                ['type' => $type, 'destinatario' => $destinatario]
            );

            throw new \RuntimeException(sprintf(
                'WhatsApp "%s": nenhuma tentativa de envio (destinatario ausente ou nao configurado)',
                $type
            ));
        }

        $this->logger->info('[B2B WhatsAppConsumer] Envio confirmado pelo provedor.', [
            'type' => $type,
            'envios_ok' => $sucessos,
        ]);
    }

    /**
     * Percorre o retorno (folha/lista/aninhado) e conta envios bem-sucedidos,
     * acumulando em $falhas os motivos das recusas.
     *
     * @param array $no
     * @param array $falhas
     * @return int quantidade de envios com success === true
     */
    private function contarEnvios(array $no, array &$falhas): int
    {
        if (array_key_exists('success', $no)) {
            if ($no['success'] === true) {
                return 1;
            }

            $motivo = $no['message'] ?? $no['error'] ?? 'sem motivo informado';
            $falhas[] = is_scalar($motivo) ? (string) $motivo : json_encode($motivo);

            return 0;
        }

        $sucessos = 0;
        foreach ($no as $filho) {
            if (is_array($filho)) {
                $sucessos += $this->contarEnvios($filho, $falhas);
            }
        }

        return $sucessos;
    }
}
