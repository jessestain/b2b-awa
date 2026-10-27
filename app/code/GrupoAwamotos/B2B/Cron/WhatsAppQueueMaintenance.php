<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Cron;

use GrupoAwamotos\LogMonitoring\Service\NotificationService;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * A2 (19/10/2026): manutencao da fila de WhatsApp B2B.
 *  - ALERTA quando ha mensagens ERROR paradas (via LogMonitoring NotificationService);
 *  - EXPURGO automatico de ERROR com mais de 30 dias (+ mensagens orfas do topico).
 * Mensagens ERROR recentes NUNCA sao reenviadas automaticamente (decisao manual do negocio).
 */
class WhatsAppQueueMaintenance
{
    private const TOPIC = 'grupoawamotos.b2b.whatsapp.send';
    private const ERROR_STATUS = 6;
    private const PURGE_OLDER_THAN_SECONDS = 2592000; // 30 dias

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $statusTable = $this->resourceConnection->getTableName('queue_message_status');
        $messageTable = $this->resourceConnection->getTableName('queue_message');

        $errorCount = (int) $connection->fetchOne(
            "SELECT COUNT(*)
             FROM {$statusTable} s
             JOIN {$messageTable} q ON q.id = s.message_id
             WHERE q.topic_name = :topic AND s.status = :status",
            ['topic' => self::TOPIC, 'status' => self::ERROR_STATUS]
        );

        if ($errorCount > 0) {
            $this->logger->critical(sprintf(
                '[WhatsAppQueueMaintenance] %d mensagens ERROR paradas na fila %s (sem retry/DLQ).',
                $errorCount,
                self::TOPIC
            ));

            try {
                $this->notificationService->sendAlert([
                    'type' => 'whatsapp_queue',
                    'severity' => 'error',
                    'title' => sprintf('Fila WhatsApp: %d mensagens ERROR paradas', $errorCount),
                    'message' => sprintf(
                        'A fila %s tem %d mensagens em estado ERROR (6) sem retry automatico. '
                        . 'Nao reenvie sem avaliar (o dado pode estar desatualizado).',
                        self::TOPIC,
                        $errorCount
                    ),
                    'source' => 'GrupoAwamotos_B2B::WhatsAppQueueMaintenance',
                    'context_data' => [
                        'topic' => self::TOPIC,
                        'error_count' => $errorCount,
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error(
                    '[WhatsAppQueueMaintenance] Falha ao enviar alerta: ' . $e->getMessage()
                );
            }
        }

        // Expurgo: somente ERROR do topico com mais de 30 dias.
        $purged = (int) $connection->delete(
            $statusTable,
            [
                'status = ?' => self::ERROR_STATUS,
                'updated_at < ?' => gmdate(
                    'Y-m-d H:i:s',
                    time() - self::PURGE_OLDER_THAN_SECONDS
                ),
            ]
        );

        // Remove mensagens orfas do topico (sem linha de status restante).
        $orphaned = (int) $connection->query(
            "DELETE FROM {$messageTable}
             WHERE topic_name = :topic
               AND id NOT IN (SELECT message_id FROM {$statusTable})",
            ['topic' => self::TOPIC]
        )->rowCount();

        // Fila por tabela (canal de status do ERP — PARADO por design; ver A4):
        // conta o volume parado diariamente e expurga linhas com mais de 30 dias,
        // para que a fila nunca volte a acumular centenas de mensagens velhas.
        $tableQueue = $this->resourceConnection->getTableName('smart_suggestions_whatsapp_queue');
        try {
            $parkedTotal = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$tableQueue} WHERE status IN ('pending', 'failed')"
            );
            $tablePurged = (int) $connection->delete(
                $tableQueue,
                [
                    'created_at < ?' => gmdate(
                        'Y-m-d H:i:s',
                        time() - self::PURGE_OLDER_THAN_SECONDS
                    ),
                ]
            );
            $this->logger->info(sprintf(
                '[WhatsAppQueueMaintenance] fila_tabela_parada=%d expurgados_tabela_30d=%d',
                $parkedTotal,
                $tablePurged
            ));
        } catch (\Throwable $e) {
            $this->logger->error(
                '[WhatsAppQueueMaintenance] Falha na manutencao da fila por tabela: ' . $e->getMessage()
            );
        }

        $this->logger->info(sprintf(
            '[WhatsAppQueueMaintenance] error=%d expurgados_30d=%d orfas=%d',
            $errorCount,
            $purged,
            $orphaned
        ));
    }
}
