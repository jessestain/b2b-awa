<?php

declare(strict_types=1);

namespace GrupoAwamotos\SmartSuggestions\Cron;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\SmartSuggestions\Helper\Config;
use GrupoAwamotos\SmartSuggestions\Model\ResourceModel\WhatsappQueue as WhatsappQueueResource;
use GrupoAwamotos\SmartSuggestions\Model\WhatsappQueueFactory;
use GrupoAwamotos\SmartSuggestions\Model\ResourceModel\SuggestionHistory as SuggestionHistoryResource;
use GrupoAwamotos\SmartSuggestions\Model\SuggestionHistoryFactory;
use Psr\Log\LoggerInterface;

/**
 * Cron job to process WhatsApp message queue
 *
 * Runs every 15 minutes during business hours (8h-18h)
 * Processes pending messages in batches with retry logic
 */
class ProcessWhatsappQueue
{
    private const BATCH_SIZE = 50;
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MINUTES = [5, 15, 60]; // Exponential backoff

    private Config $config;
    private LoggerInterface $logger;
    private WhatsappQueueResource $queueResource;
    private WhatsappQueueFactory $queueFactory;
    private WhatsappSenderInterface $whatsappSender;
    private SuggestionHistoryResource $historyResource;
    private SuggestionHistoryFactory $historyFactory;

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        WhatsappQueueResource $queueResource,
        WhatsappQueueFactory $queueFactory,
        WhatsappSenderInterface $whatsappSender,
        SuggestionHistoryResource $historyResource,
        SuggestionHistoryFactory $historyFactory
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->queueResource = $queueResource;
        $this->queueFactory = $queueFactory;
        $this->whatsappSender = $whatsappSender;
        $this->historyResource = $historyResource;
        $this->historyFactory = $historyFactory;
    }

    /**
     * Execute WhatsApp queue processing
     */
    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        if (!$this->config->isWhatsappEnabled()) {
            return;
        }

        // Check if within business hours
        if (!$this->isWithinBusinessHours()) {
            $this->logger->debug('SmartSuggestions: WhatsApp queue processing skipped - outside business hours');
            return;
        }

        try {
            $startTime = microtime(true);

            // Reset stuck messages first
            $this->resetStuckMessages();

            // Get pending messages
            $pendingMessages = $this->queueResource->getPendingMessages(self::BATCH_SIZE);

            if (empty($pendingMessages)) {
                $this->logger->debug('SmartSuggestions: No pending WhatsApp messages');
                return;
            }

            $this->logger->info(sprintf(
                'SmartSuggestions: Processing %d WhatsApp messages',
                count($pendingMessages)
            ));

            // Claim each row conditionally; a stale batch must not resurrect cancellation.

            $sent = 0;
            $failed = 0;
            $retried = 0;

            foreach ($pendingMessages as $message) {
                try {
                    $claimed = $this->queueResource->getConnection()->update(
                        $this->queueResource->getMainTable(),
                        ['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')],
                        ['queue_id = ?' => (int) $message['queue_id'], 'status = ?' => 'pending']
                    );
                    if ($claimed !== 1) {
                        continue;
                    }
                    $result = $this->processMessage($message);
                    if (!empty($result['consent_blocked'])) {
                        $this->queueResource->getConnection()->update(
                            $this->queueResource->getMainTable(),
                            ['status' => 'consent_review', 'error_message' => $result['message'],
                             'updated_at' => date('Y-m-d H:i:s')],
                            ['queue_id = ?' => (int) $message['queue_id'], 'status = ?' => 'processing']
                        );
                        continue;
                    }

                    if ($result['success']) {
                        $sent++;
                        $this->markMessageSent((int) $message['queue_id'], $result['message_id'] ?? null);

                        // Update suggestion history if linked
                        if (!empty($message['history_id'])) {
                            $this->updateSuggestionHistory(
                                (int) $message['history_id'],
                                'sent',
                                $result['message_id'] ?? null
                            );
                        }
                    } else {
                        $retryCount = (int) ($message['retry_count'] ?? 0);

                        if ($retryCount < self::MAX_RETRIES) {
                            $retried++;
                            $this->scheduleRetry(
                                (int) $message['queue_id'],
                                $retryCount,
                                $result['message'] ?? 'Unknown error'
                            );
                        } else {
                            $failed++;
                            $this->markMessageFailed(
                                (int) $message['queue_id'],
                                $result['message'] ?? 'Max retries exceeded'
                            );

                            // Update suggestion history if linked
                            if (!empty($message['history_id'])) {
                                $this->updateSuggestionHistory(
                                    (int) $message['history_id'],
                                    'failed',
                                    null,
                                    $result['message'] ?? 'Max retries exceeded'
                                );
                            }
                        }
                    }

                    // Small delay between messages to avoid rate limiting
                    usleep(200000); // 200ms
                } catch (\Exception $e) {
                    $failed++;
                    $this->logger->error('SmartSuggestions: Error processing message', [
                        'queue_id' => $message['queue_id'],
                        'error' => $e->getMessage()
                    ]);

                    $this->markMessageFailed((int) $message['queue_id'], $e->getMessage());
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info(sprintf(
                'SmartSuggestions: WhatsApp queue processed. Sent: %d, Failed: %d, Retried: %d in %ss',
                $sent,
                $failed,
                $retried,
                $duration
            ));

            // Cleanup old messages periodically (every ~4 hours based on 15min cron)
            if (rand(1, 16) === 1) {
                $this->cleanupOldMessages();
            }
        } catch (\Exception $e) {
            $this->logger->error('SmartSuggestions: WhatsApp queue processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process a single message
     */
    private function processMessage(array $message): array
    {
        $phone = $message['phone'] ?? '';
        $content = $message['message_content'] ?? '';

        if (empty($phone) || empty($content)) {
            return [
                'success' => false,
                'message' => 'Invalid message data: missing phone or content'
            ];
        }

        // Resolve durable order identity and read the preference freshly for every dispatch.
        // Legacy rows without identity stay auditable and never become an automatic retry.
        $type = (string) ($message['message_type'] ?? '');
        if (!preg_match('/^order_status:([1-9][0-9]*)$/D', $type, $match)) {
            return ['success' => false, 'consent_blocked' => true,
                'message' => 'Consent review required: missing durable order identity'];
        }
        $connection = $this->queueResource->getConnection();
        $orderTable = $this->queueResource->getTable('sales_order');
        $customerTable = $this->queueResource->getTable('customer_entity');
        $attributeTable = $this->queueResource->getTable('eav_attribute');
        $entityTypeTable = $this->queueResource->getTable('eav_entity_type');
        $valueTable = $this->queueResource->getTable('customer_entity_int');
        $consent = $connection->fetchOne(
            "SELECT v.value FROM {$orderTable} o
             JOIN {$customerTable} c ON c.entity_id = o.customer_id
             JOIN {$entityTypeTable} t ON t.entity_type_code = 'customer'
             JOIN {$attributeTable} a ON a.entity_type_id = t.entity_type_id
               AND a.attribute_code = 'whatsapp_optin' AND a.backend_type = 'int'
             JOIN {$valueTable} v ON v.entity_id = c.entity_id AND v.attribute_id = a.attribute_id
             WHERE o.entity_id = :order_id",
            ['order_id' => (int) $match[1]]
        );
        if ((string) $consent !== '1') {
            return ['success' => false, 'consent_blocked' => true,
                'message' => 'Consent revoked, absent or customer unavailable before dispatch'];
        }
        $status = $connection->fetchOne(
            $connection->select()->from($this->queueResource->getMainTable(), 'status')
                ->where('queue_id = ?', (int) $message['queue_id'])
        );
        if ($status !== 'processing') {
            return ['success' => false, 'consent_blocked' => true,
                'message' => 'Queue state changed before dispatch'];
        }
        return $this->whatsappSender->sendMessage($phone, $content);
    }

    /**
     * Mark message as sent
     */
    private function markMessageSent(int $queueId, ?string $externalMessageId): void
    {
        $connection = $this->queueResource->getConnection();
        $connection->update(
            $this->queueResource->getMainTable(),
            [
                'status' => 'sent',
                'whatsapp_message_id' => $externalMessageId,
                'sent_at' => date('Y-m-d H:i:s'),
                'error_message' => null
            ],
            ['queue_id = ?' => $queueId, 'status = ?' => 'processing']
        );
    }

    /**
     * Mark message as failed
     */
    private function markMessageFailed(int $queueId, string $error): void
    {
        $connection = $this->queueResource->getConnection();
        $connection->update(
            $this->queueResource->getMainTable(),
            [
                'status' => 'failed',
                'error_message' => substr($error, 0, 1000)
            ],
            ['queue_id = ?' => $queueId, 'status = ?' => 'processing']
        );
    }

    /**
     * Schedule retry with exponential backoff
     */
    private function scheduleRetry(int $queueId, int $currentRetryCount, string $error): void
    {
        $delayMinutes = self::RETRY_DELAY_MINUTES[$currentRetryCount] ?? 60;
        $scheduledAt = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));

        $connection = $this->queueResource->getConnection();
        $connection->update(
            $this->queueResource->getMainTable(),
            [
                'status' => 'pending',
                'retry_count' => $currentRetryCount + 1,
                'scheduled_at' => $scheduledAt,
                'error_message' => substr($error, 0, 1000)
            ],
            ['queue_id = ?' => $queueId, 'status = ?' => 'processing']
        );

        $this->logger->info(sprintf(
            'SmartSuggestions: Message %d scheduled for retry at %s (attempt %d)',
            $queueId,
            $scheduledAt,
            $currentRetryCount + 1
        ));
    }

    /**
     * Update suggestion history status
     */
    private function updateSuggestionHistory(
        int $historyId,
        string $status,
        ?string $messageId = null,
        ?string $error = null
    ): void {
        try {
            $connection = $this->historyResource->getConnection();
            $data = [
                'status' => $status,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null
            ];

            if ($messageId) {
                $data['whatsapp_message_id'] = $messageId;
            }

            if ($error) {
                $data['error_message'] = substr($error, 0, 1000);
            }

            $connection->update(
                $this->historyResource->getMainTable(),
                $data,
                ['history_id = ?' => $historyId]
            );
        } catch (\Exception $e) {
            $this->logger->warning('SmartSuggestions: Failed to update history', [
                'history_id' => $historyId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset stuck processing messages
     */
    private function resetStuckMessages(): void
    {
        $reset = $this->queueResource->resetStuckMessages(30);
        if ($reset > 0) {
            $this->logger->info(sprintf('SmartSuggestions: Reset %d stuck messages', $reset));
        }
    }

    /**
     * Cleanup old processed messages
     */
    private function cleanupOldMessages(): void
    {
        $deleted = $this->queueResource->cleanupOldMessages(30);
        if ($deleted > 0) {
            $this->logger->info(sprintf('SmartSuggestions: Cleaned up %d old messages', $deleted));
        }
    }

    /**
     * Check if current time is within business hours
     */
    private function isWithinBusinessHours(): bool
    {
        $startHour = $this->config->getWhatsappStartHour();
        $endHour = $this->config->getWhatsappEndHour();

        $currentHour = (int) date('H');

        // Also skip weekends if configured
        $skipWeekends = $this->config->skipWeekendsForWhatsapp();
        if ($skipWeekends) {
            $dayOfWeek = (int) date('N');
            if ($dayOfWeek >= 6) { // Saturday = 6, Sunday = 7
                return false;
            }
        }

        return $currentHour >= $startHour && $currentHour < $endHour;
    }
}
