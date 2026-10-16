<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Notification;

use GrupoAwamotos\B2B\Api\Data\WhatsAppMessageInterface;
use Psr\Log\LoggerInterface;

class WhatsAppConsumer
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process a queued WhatsApp notification message.
     *
     * @param WhatsAppMessageInterface $message
     */
    public function process(WhatsAppMessageInterface $message): void
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
            $this->logger->error('[B2B WhatsAppConsumer] Failed to process message.', [
                'type' => $type,
                'error' => $e->getMessage(),
                'payload' => $message->getPayload(),
            ]);

            /* NAO engolir: antes este catch so logava e o MQ marcava COMPLETE. Relancando, o
               Magento\Framework\MessageQueue\Consumer chama Queue::reject($msg, false, $msg) e o
               status vira ERROR (6) — so NEW (2) e RETRY_REQUIRED (5) sao consumidos, entao nao
               ha laco de reenvio. \Throwable nao-Exception e reembrulhado para o catch do
               framework (que e catch (Exception)) sempre pegar. */
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
     *   3. aninhado ............. ['team' => [lista], 'customer' => folha|[]]
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
