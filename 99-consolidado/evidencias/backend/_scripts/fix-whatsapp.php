<?php
/**
 * task-67 — o WhatsAppConsumer passa a RESPEITAR o retorno do envio.
 *
 * Antes: os 8 `case` chamavam o servico e descartavam o array de retorno; o `catch` so logava.
 * Consequencia: recusa da Z-API era gravada como COMPLETE na fila (28 mensagens, trials=0).
 *
 * Agora: helper unico `sendOrFail()` normaliza os 3 formatos de retorno do WhatsAppService
 * (folha, lista, aninhado), loga e LANCA. O framework
 * (Magento\Framework\MessageQueue\Consumer::getTransactionCallback) pega a excecao e chama
 * Queue::reject($msg, false, $msg) -> MysqlMq Driver/Queue::reject() -> MESSAGE_STATUS_ERROR (6),
 * que e TERMINAL (ResourceModel/Queue.php:180 so consome NEW=2 e RETRY_REQUIRED=5).
 *
 * Nenhum construtor muda => sem setup:di:compile.
 * Uso: php fix-whatsapp.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/B2B/Model/Notification/WhatsAppConsumer.php';
$apply = in_array('--apply', $argv, true);
$bakDir = $root . '/_backups/whatsapp-success-' . gmdate('Ymd\THis\Z');

$anchor = <<<'TXT'
        try {
            switch ($type) {
                case 'new_registration':
                    $this->whatsAppService->notifyNewB2BRegistration($data);
                    break;
                case 'new_quote':
                    $this->whatsAppService->notifyNewQuote($data);
                    break;
                case 'new_order':
                    $this->whatsAppService->notifyNewOrder($data);
                    break;
                case 'customer_approved':
                    $this->whatsAppService->notifyCustomerApproval($data);
                    break;
                case 'customer_rejected':
                    $this->whatsAppService->notifyCustomerRejection($data);
                    break;
                case 'quote_response':
                    $this->whatsAppService->notifyQuoteResponse($data);
                    break;
                case 'order_status':
                    $this->whatsAppService->notifyOrderStatusUpdate($data);
                    break;
                case 'send_text':
                    $this->whatsAppService->sendText($data['phone'] ?? '', $data['message_text'] ?? '');
                    break;
                default:
                    $this->logger->warning('[B2B WhatsAppConsumer] Unknown message type: ' . $type, [
                        'type' => $type,
                        'payload' => $message->getPayload(),
                    ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[B2B WhatsAppConsumer] Failed to process message.', [
                'type' => $type,
                'error' => $e->getMessage(),
                'payload' => $message->getPayload(),
            ]);
        }
    }
}
TXT;

$novo = <<<'TXT'
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
TXT;

$src = file_get_contents($target);
if (substr_count($src, $anchor) !== 1) {
    $a2 = str_replace("\n", "\r\n", $anchor);
    if (substr_count($src, $a2) === 1) {
        $anchor = $a2;
        $novo = str_replace("\n", "\r\n", $novo);
    }
}
$n = substr_count($src, $anchor);
echo "ancoras exatas = $n\n";
echo 'construtores antes = ' . substr_count($src, 'function __construct') . "\n";
if ($n !== 1) {
    fwrite(STDERR, "ABORTA: esperava 1 ancora, achei $n\n");
    exit(2);
}

$new = str_replace($anchor, $novo, $src);
file_put_contents('/tmp/wa.new.php', $new);
file_put_contents('/tmp/wa.old.php', $src);

$out = [];
exec('php -l /tmp/wa.new.php 2>&1', $out, $rc);
echo 'php -l (novo) => rc=' . $rc . ' :: ' . implode(' | ', $out) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: nao passa no lint; nada escrito\n");
    exit(3);
}
echo 'construtores depois = ' . substr_count($new, 'function __construct') . "\n";

echo "\n--- DIFF (resumo) ---\n";
system('diff -u /tmp/wa.old.php /tmp/wa.new.php | head -50');

if (!$apply) {
    echo "\nDRY-RUN: nada escrito.\n";
    exit(0);
}

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
    fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
    exit(4);
}
copy($target, $bakDir . '/WhatsAppConsumer.php.orig');
file_put_contents($bakDir . '/ANTES.txt', sprintf(
    "arquivo: %s\nsha256: %s\nbytes: %d\ndono: %d\ngrupo: %d\nmodo: %o\nmotivo: task-67 retorno do envio descartado\n",
    $target, hash_file('sha256', $target), filesize($target), fileowner($target), filegroup($target), fileperms($target) & 0777
));

file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nBACKUP = $bakDir\n";
echo 'APLICADO: sha256 ' . hash_file('sha256', $target) . ' | bytes=' . filesize($target)
    . ' | dono/grupo/modo=' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
