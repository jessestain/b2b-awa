<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Sectra;

use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\OrderRetry as OrderRetryResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Cancels legacy B2B orders stuck before ERP validation gate existed.
 */
class StuckOrderCleanup
{
    public const REASON_CUSTOMER_NOT_VALIDATED = 'customer_not_validated_in_erp';

    /**
     * Grace period for legacy statuses only. Normal B2B orders must stay available
     * because Sectra validates/imports the client during "Importar Pedidos".
     */
    private const MIN_ORDER_AGE_DAYS = 7;

    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly ValidatorChecker $validatorChecker,
        private readonly SectraSyncLogger $syncLogger,
        private readonly OrderRetryResource $orderRetryResource,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     cancelled: int,
     *     dry_run: bool,
     *     candidates: list<array{increment_id: string, customer_id: int, reason: string}>,
     *     skipped: list<array{increment_id: string, reason: string}>
     * }
     */
    public function cancelOrdersForUnvalidatedCustomers(bool $dryRun = false): array
    {
        $result = [
            'cancelled' => 0,
            'dry_run' => $dryRun,
            'candidates' => [],
            'skipped' => [],
        ];

        $connection = $this->resourceConnection->getConnection();
        $groupIn = B2bGroupIds::toSqlInList($this->resourceConnection);
        $rows = $connection->fetchAll(
            "SELECT so.entity_id, so.increment_id, so.customer_id, so.sectra_import_status,
                    so.state, so.total_paid
             FROM sales_order so
             INNER JOIN customer_entity ce ON ce.entity_id = so.customer_id
             WHERE ce.group_id IN ($groupIn)
               AND so.state NOT IN ('canceled', 'closed', 'complete')
               AND so.created_at < DATE_SUB(NOW(), INTERVAL " . self::MIN_ORDER_AGE_DAYS . " DAY)
              AND so.sectra_import_status IN (?, ?)",
            [
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                SectraImportStatus::ORDER_BLOCKED_CUSTOMER_NOT_VALIDATED,
            ]
        );

        foreach ($rows as $row) {
            $incrementId = (string) $row['increment_id'];
            $customerId = (int) $row['customer_id'];
            $orderId = (int) $row['entity_id'];

            $skipReason = $this->resolveSkipReason($orderId, $customerId, (float) ($row['total_paid'] ?? 0));
            if ($skipReason !== null) {
                $result['skipped'][] = ['increment_id' => $incrementId, 'reason' => $skipReason];
                continue;
            }

            if ($this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
                $result['skipped'][] = [
                    'increment_id' => $incrementId,
                    'reason' => 'customer_validated_in_erp',
                ];
                continue;
            }

            $result['candidates'][] = [
                'increment_id' => $incrementId,
                'customer_id' => $customerId,
                'reason' => self::REASON_CUSTOMER_NOT_VALIDATED,
            ];

            if ($dryRun) {
                continue;
            }

            if ($this->cancelByIncrementId($incrementId)) {
                $result['cancelled']++;
            }
        }

        if ($dryRun && $result['candidates'] !== []) {
            $this->logger->info('[B2B-Sectra] Dry-run cancel stuck orders', [
                'candidates' => count($result['candidates']),
                'skipped' => count($result['skipped']),
            ]);
        }

        return $result;
    }

    public function cancelByIncrementId(string $incrementId, string $reason = self::REASON_CUSTOMER_NOT_VALIDATED): bool
    {
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            $this->logger->warning('[B2B-Sectra] Pedido não encontrado para cancelamento: ' . $incrementId);
            return false;
        }

        return $this->cancelOrder($order, $reason);
    }

    public function cancelOrder(OrderInterface $order, string $reason = self::REASON_CUSTOMER_NOT_VALIDATED): bool
    {
        if (!$order instanceof Order || !$order->getEntityId()) {
            return false;
        }

        if ($order->isCanceled() || $order->getState() === Order::STATE_CLOSED) {
            $this->finalizeNonImportable((int) $order->getEntityId(), $order, $reason);
            return true;
        }

        $skipReason = $this->resolveSkipReason(
            (int) $order->getEntityId(),
            (int) ($order->getCustomerId() ?? 0),
            (float) $order->getTotalPaid()
        );
        if ($skipReason !== null) {
            $this->logger->info(sprintf(
                '[B2B-Sectra] Cancelamento ignorado pedido #%s — %s',
                $order->getIncrementId(),
                $skipReason
            ));
            return false;
        }

        try {
            if ($order->canCancel()) {
                $order->registerCancellation(
                    __('Cancelado antes da importação ERP: %1', $reason)
                );
            } else {
                $this->logger->warning(sprintf(
                    '[B2B-Sectra] Pedido #%s não pode ser cancelado (pago/processado)',
                    $order->getIncrementId()
                ));
                return false;
            }

            $order->addCommentToStatusHistory(
                __('Pedido cancelado — cliente não validado no ERP. Motivo: %1', $reason),
                false,
                false
            );
            $order->save();
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[B2B-Sectra] Falha ao cancelar pedido #%s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
            return false;
        }

        $this->finalizeNonImportable((int) $order->getEntityId(), $order, $reason);

        return true;
    }

    private function resolveSkipReason(int $orderId, int $customerId, float $totalPaid): ?string
    {
        if ($totalPaid > 0.0001) {
            return 'order_has_payment';
        }

        $connection = $this->resourceConnection->getConnection();
        $imported = $connection->fetchOne(
            'SELECT order_id FROM oc_order_imported WHERE order_id IN (?, ?)',
            [$orderId, $orderId + 200000]
        );
        if ($imported !== false) {
            return 'order_already_imported';
        }

        $sectraStatus = (string) $connection->fetchOne(
            'SELECT sectra_import_status FROM sales_order WHERE entity_id = ?',
            [$orderId]
        );
        if ($sectraStatus === SectraImportStatus::IMPORTED
            || $sectraStatus === SectraImportStatus::ORDER_CANCELLED_BEFORE_ERP_IMPORT
            || $sectraStatus === SectraImportStatus::READY_FOR_IMPORT
        ) {
            return 'sectra_status_' . $sectraStatus;
        }

        if ($customerId > 0 && $this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            return 'customer_validated_in_erp';
        }

        return null;
    }

    private function finalizeNonImportable(int $orderId, OrderInterface $order, string $reason): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            'sales_order',
            ['sectra_import_status' => SectraImportStatus::ORDER_CANCELLED_BEFORE_ERP_IMPORT],
            ['entity_id = ?' => $orderId]
        );

        $this->orderRetryResource->clearRetryCount($orderId);
        $connection->delete('grupoawamotos_erp_order_retry', ['order_id = ?' => $orderId]);

        $customerId = (int) ($order->getCustomerId() ?? 0);
        $sectraChave = $customerId > 0 ? $this->validatorChecker->resolveSectraChave($customerId) : null;

        $this->syncLogger->log(
            ProspectEvent::ORDER_CANCELLED_BEFORE_ERP_IMPORT,
            sprintf(
                'Pedido #%s cancelado e removido da fila ERP. reason=%s',
                $order->getIncrementId(),
                $reason
            ),
            $customerId > 0 ? $customerId : null,
            $orderId,
            null,
            $sectraChave,
            'info'
        );
    }
}
