<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Sectra;

use GrupoAwamotos\B2B\Helper\Config as B2bConfig;
use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface as ErpConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Exposes approved B2B orders only when Sectra can resolve the ERP client.
 */
class OrderImportGate
{
    private const OC_ORDER_ID_OFFSET = 200000;

    public function __construct(
        private readonly ValidatorChecker $validatorChecker,
        private readonly SectraSyncLogger $syncLogger,
        private readonly B2bConfig $b2bConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly ErpConnectionInterface $erpConnection,
        private readonly OrderPullInterface $orderPull,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Backfill sectra_import_status for orders placed before the gate existed.
     */
    public function backfillOrderImportStatus(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $groupIn = B2bGroupIds::toSqlInList($this->resourceConnection);
        $rows = $connection->fetchAll(
            "SELECT so.entity_id, so.customer_id, so.sectra_import_status
             FROM sales_order so
             INNER JOIN customer_entity ce ON ce.entity_id = so.customer_id
             WHERE ce.group_id IN ($groupIn)
               AND so.state NOT IN ('canceled', 'closed')
               AND (so.sectra_import_status IS NULL
                   OR so.sectra_import_status IN (?, ?, ?))",
            [
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                SectraImportStatus::READY_FOR_IMPORT,
                SectraImportStatus::ORDER_CANCELLED_BEFORE_ERP_IMPORT,
            ]
        );

        $updated = 0;
        $targetReady = 0;
        $targetAwaiting = 0;
        $alreadyImported = 0;
        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];
            $orderId = (int) $row['entity_id'];
            $current = (string) ($row['sectra_import_status'] ?? '');

            if ($this->isOrderAlreadyImported($orderId)) {
                $alreadyImported++;
                if ($current !== SectraImportStatus::IMPORTED) {
                    $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);
                    $updated++;
                }
                continue;
            }

            $targetStatus = $this->validatorChecker->isCustomerValidatedInSectra($customerId)
                ? SectraImportStatus::READY_FOR_IMPORT
                : SectraImportStatus::AWAITING_CUSTOMER_VALIDATION;
            if ($targetStatus === SectraImportStatus::READY_FOR_IMPORT) {
                $targetReady++;
            } else {
                $targetAwaiting++;
            }

            if ($current !== $targetStatus) {
                $this->setOrderImportStatus($orderId, $targetStatus);
                $updated++;
            }
        }

        $released = $this->releaseOrdersForValidatedCustomers();

        return $updated + $released;
    }

    /**
     * Release B2B orders held for customer validation once Sectra confirms Cadastro de Cliente.
     */
    public function releaseOrdersForValidatedCustomers(): int
    {
        $connection = $this->resourceConnection->getConnection();
        /** @var array<int, array{entity_id:string|int, customer_id:string|int}> $rows */
        $rows = $connection->fetchAll(
            "SELECT so.entity_id, so.customer_id
                 FROM sales_order so
                 INNER JOIN customer_entity ce ON ce.entity_id = so.customer_id
                 INNER JOIN oc_customer_id_map map ON map.magento_customer_id = ce.entity_id
                 LEFT JOIN customer_entity_varchar erp_attr
                     ON erp_attr.entity_id = ce.entity_id
                     AND erp_attr.attribute_id = (
                         SELECT attribute_id FROM eav_attribute
                         WHERE attribute_code = 'erp_code'
                           AND entity_type_id = (
                               SELECT entity_type_id FROM eav_entity_type
                               WHERE entity_type_code = 'customer'
                           )
                     )
                     AND erp_attr.value REGEXP '^[0-9]+$'
                 INNER JOIN oc_customer_b2b_confirmed confirmed
                     ON confirmed.customer_id = COALESCE(
                         NULLIF(CAST(erp_attr.value AS UNSIGNED), 0),
                         map.old_oc_customer_id
                     )
                 WHERE ce.group_id IN (" . B2bGroupIds::toSqlInList($this->resourceConnection) . ")
                   AND so.state IN ('new', 'pending_payment', 'processing')
                   AND so.sectra_import_status IN (?, ?, ?)
                   AND NOT EXISTS (
                       SELECT 1
                       FROM oc_order_imported oi
                       WHERE oi.order_id = so.entity_id + ?
                   )",
            [
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                SectraImportStatus::ORDER_BLOCKED_CUSTOMER_NOT_VALIDATED,
                SectraImportStatus::ORDER_CANCELLED_BEFORE_ERP_IMPORT,
                self::OC_ORDER_ID_OFFSET,
            ]
        );

        if ($rows === []) {
            return 0;
        }

        $releasableOrderIds = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $customerId = (int) $row['customer_id'];

            if ($customerId <= 0 || !$this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
                continue;
            }

            $releasableOrderIds[] = $orderId;
        }

        if ($releasableOrderIds === []) {
            return 0;
        }

        $connection->update(
            'sales_order',
            ['sectra_import_status' => SectraImportStatus::READY_FOR_IMPORT],
            ['entity_id IN (?)' => $releasableOrderIds]
        );

        foreach ($releasableOrderIds as $orderId) {
            $row = $connection->fetchRow(
                'SELECT increment_id, customer_id FROM sales_order WHERE entity_id = ?',
                [$orderId]
            );
            if ($row === false) {
                continue;
            }

            $customerId = (int) ($row['customer_id'] ?? 0);
            $this->syncLogger->log(
                ProspectEvent::ORDER_RELEASED_FOR_IMPORT,
                sprintf(
                    'Pedido #%s liberado após Cadastro de Cliente no Sectra.',
                    $row['increment_id'] ?? $orderId
                ),
                $customerId > 0 ? $customerId : null,
                $orderId,
                null,
                $customerId > 0 ? $this->validatorChecker->resolveSectraChave($customerId) : null,
                'success'
            );
        }

        $this->logger->info('[B2B-Sectra] Pedidos liberados após validação ERP', [
            'count' => count($releasableOrderIds),
            'order_ids' => $releasableOrderIds,
        ]);

        return count($releasableOrderIds);
    }

    private function isOrderAlreadyImported(int $orderId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $exists = $connection->fetchOne(
            'SELECT order_id FROM oc_order_imported WHERE order_id = ?',
            [$orderId + self::OC_ORDER_ID_OFFSET]
        );

        return $exists !== false;
    }

    public function applyOnOrderPlace(OrderInterface $order): void
    {
        if (!$this->b2bConfig->isEnabled()) {
            return;
        }

        $customerId = (int) $order->getCustomerId();
        if ($customerId <= 0) {
            return;
        }

        if (!$this->isB2bCustomer($customerId)) {
            $this->setOrderImportStatus((int) $order->getEntityId(), SectraImportStatus::NOT_APPLICABLE);
            return;
        }

        $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
        if (!$this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            $this->setOrderImportStatus((int) $order->getEntityId(), SectraImportStatus::AWAITING_CUSTOMER_VALIDATION);
            $this->syncLogger->log(
                ProspectEvent::ORDER_AWAITING_CUSTOMER,
                sprintf(
                    'Pedido #%s aguardando cadastro do cliente no B2B do Sectra.',
                    $order->getIncrementId()
                ),
                $customerId,
                (int) $order->getEntityId(),
                null,
                $sectraChave
            );
            return;
        }

        $this->setOrderImportStatus((int) $order->getEntityId(), SectraImportStatus::READY_FOR_IMPORT);

        $this->syncLogger->log(
            ProspectEvent::ORDER_RELEASED_FOR_IMPORT,
            sprintf(
                'Pedido #%s liberado para Importar Pedidos no Sectra.',
                $order->getIncrementId()
            ),
            $customerId,
            (int) $order->getEntityId(),
            null,
            $sectraChave,
            'success'
        );
    }

    /**
     * Release held orders after customer validation in Sectra.
     */
    public function releaseOrdersForCustomer(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $orderIds = $connection->fetchCol(
            'SELECT entity_id FROM sales_order
             WHERE customer_id = ?
               AND sectra_import_status = ?
               AND state NOT IN (?, ?)',
            [
                $customerId,
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                Order::STATE_CANCELED,
                Order::STATE_CLOSED,
            ]
        );

        if ($orderIds === []) {
            return 0;
        }

        $connection->update(
            'sales_order',
            ['sectra_import_status' => SectraImportStatus::READY_FOR_IMPORT],
            [
                'entity_id IN (?)' => $orderIds,
            ]
        );

        $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
        foreach ($orderIds as $orderId) {
            $incrementId = $connection->fetchOne(
                'SELECT increment_id FROM sales_order WHERE entity_id = ?',
                [(int) $orderId]
            );
            $this->syncLogger->log(
                ProspectEvent::ORDER_RELEASED_FOR_IMPORT,
                sprintf(
                    'Pedido #%s liberado após validação do cliente no Sectra.',
                    $incrementId ?: $orderId
                ),
                $customerId,
                (int) $orderId,
                null,
                $sectraChave,
                'success'
            );
        }

        return count($orderIds);
    }

    /**
     * Mark orders imported by Sectra (oc_order_imported ack).
     */
    public function syncImportedOrderFlags(): int
    {
        $reconciled = $this->reconcileOrdersAlreadyInErp();
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            'SELECT oi.order_id, so.entity_id, so.increment_id, so.customer_id
             FROM oc_order_imported oi
             INNER JOIN sales_order so ON so.entity_id + ? = oi.order_id
             WHERE so.sectra_import_status IS NULL
                OR so.sectra_import_status IN (?, ?)',
            [
                self::OC_ORDER_ID_OFFSET,
                SectraImportStatus::READY_FOR_IMPORT,
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
            ]
        );

        $updated = 0;
        $importedOrderIds = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);
            $importedOrderIds[] = $orderId;
            $this->syncLogger->log(
                ProspectEvent::ORDER_IMPORTED_SUCCESS,
                sprintf('Pedido #%s importado com sucesso no ERP Sectra.', $row['increment_id']),
                (int) ($row['customer_id'] ?? 0) ?: null,
                $orderId,
                null,
                null,
                'success'
            );
            $updated++;
        }

        /* task-55 (11/10/2026) — CORRECAO DE RAIZ.
           Este laco marcava sectra_import_status='imported' e NAO escrevia o mapa
           grupoawamotos_erp_entity_map. Resultado medido: 29 pedidos 'imported' e apenas
           6 com linha no mapa (23 sem). Como o OrderSync::getPendingOrdersForSync() le
           SOMENTE o mapa, esses pedidos nunca entravam no sync de status - o log repetia
           "Syncing status for 0 orders" (82x) e eles ficavam presos em 'processing'.
           Agora o mapa e gravado JUNTO com o flag, no mesmo caminho (produtor). */
        if ($importedOrderIds !== []) {
            $this->syncOrderEntityMap($importedOrderIds);
        }

        return $reconciled + $updated;
    }

    /**
     * Grava o mapa de pedido (entity_type='order') dos pedidos marcados como importados.
     *
     * Le o CODIGO do ERP pela CONEXAO DE LEITURA (nenhuma escrita no ERP) e faz upsert
     * em grupoawamotos_erp_entity_map, com a mesma semantica de SyncLog::setEntityMap().
     * Nunca lanca: o flag 'imported' e a obrigacao principal deste caminho, e uma falha
     * do ERP nao pode derrubar o sync de status.
     *
     * @param int[] $orderIds
     * @return int quantos pedidos ficaram mapeados
     */
    private function syncOrderEntityMap(array $orderIds): int
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return 0;
        }

        try {
            $webIds = array_map(
                static fn (int $id): string => (string) ($id + self::OC_ORDER_ID_OFFSET),
                $orderIds
            );
            $placeholders = implode(',', array_fill(0, count($webIds), '?'));
            $erpRows = $this->erpConnection->query(
                "SELECT p.CODIGO, p.PEDIDOWEB FROM VE_PEDIDO p WHERE p.PEDIDOWEB IN ({$placeholders})",
                $webIds
            );

            $erpCodeByWebId = [];
            foreach ($erpRows as $erpRow) {
                $webId = trim((string) ($erpRow['PEDIDOWEB'] ?? ''));
                $codigo = trim((string) ($erpRow['CODIGO'] ?? ''));
                if ($webId !== '' && $codigo !== '' && ctype_digit($codigo) && (int) $codigo > 0) {
                    $erpCodeByWebId[$webId] = $codigo;
                }
            }

            if ($erpCodeByWebId === []) {
                $this->logger->warning(
                    '[B2B-Sectra] Nenhum CODIGO devolvido pelo ERP; mapa de pedidos nao gravado',
                    ['orders' => $orderIds]
                );
                return 0;
            }

            $connection = $this->resourceConnection->getConnection();
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $mapped = 0;

            foreach ($orderIds as $orderId) {
                $webId = (string) ($orderId + self::OC_ORDER_ID_OFFSET);
                if (!isset($erpCodeByWebId[$webId])) {
                    continue;
                }
                $erpCode = $erpCodeByWebId[$webId];

                $existing = $connection->fetchOne(
                    $connection->select()
                        ->from('grupoawamotos_erp_entity_map', 'map_id')
                        ->where('entity_type = ?', 'order')
                        ->where('erp_code = ?', $erpCode)
                );

                if ($existing) {
                    $connection->update(
                        'grupoawamotos_erp_entity_map',
                        ['magento_entity_id' => $orderId, 'last_sync_at' => $now],
                        ['entity_type = ?' => 'order', 'erp_code = ?' => $erpCode]
                    );
                } else {
                    $connection->insert('grupoawamotos_erp_entity_map', [
                        'entity_type' => 'order',
                        'erp_code' => $erpCode,
                        'magento_entity_id' => $orderId,
                        'last_sync_at' => $now,
                        'sync_hash' => null,
                    ]);
                }
                $mapped++;
            }

            $this->logger->info('[B2B-Sectra] Mapa de pedidos gravado no caminho de reconciliacao (task-55)', [
                'mapeados' => $mapped,
                'sem_codigo_no_erp' => count($orderIds) - $mapped,
            ]);

            return $mapped;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[B2B-Sectra] Falha ao gravar o mapa de pedidos importados: ' . $e->getMessage()
            );
            return 0;
        }
    }

    /**
     * Reconcile orders imported by the Sectra desktop when it does not write
     * the local oc_order_imported ACK.
     *
     * The ACK is generated only after customer, header total, item count,
     * quantity and item total match exactly (within currency tolerance).
     */
    private function reconcileOrdersAlreadyInErp(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $candidates = $connection->fetchAll(
            'SELECT oo.order_id, oo.customer_id, oo.total,
                    so.entity_id, so.increment_id,
                    COUNT(soi.item_id) AS item_count,
                    COALESCE(SUM(soi.qty_ordered), 0) AS qty_total,
                    COALESCE(SUM(soi.row_total), 0) AS items_total
             FROM oc_order oo
             INNER JOIN sales_order so ON so.entity_id = oo.order_id - ?
             LEFT JOIN sales_order_item soi
                ON soi.order_id = so.entity_id
                AND soi.parent_item_id IS NULL
             GROUP BY oo.order_id, oo.customer_id, oo.total, so.entity_id, so.increment_id
             ORDER BY oo.order_id
             LIMIT 50',
            [self::OC_ORDER_ID_OFFSET]
        );

        if ($candidates === []) {
            return 0;
        }

        $webOrderIds = array_map(
            static fn (array $row): string => (string) (int) $row['order_id'],
            $candidates
        );
        $placeholders = implode(',', array_fill(0, count($webOrderIds), '?'));
        $erpRows = $this->erpConnection->query(
            "SELECT p.CODIGO, p.PEDIDOWEB, p.CLIENTE, p.VLRTOTAL,
                    COUNT(i.CODIGO) AS item_count,
                    COALESCE(SUM(i.QTDE), 0) AS qty_total,
                    COALESCE(SUM(i.VLRTOTAL), 0) AS items_total
             FROM VE_PEDIDO p
             LEFT JOIN VE_PEDIDOITENS i ON i.PEDIDO = p.CODIGO
             WHERE p.PEDIDOWEB IN ({$placeholders})
             GROUP BY p.CODIGO, p.PEDIDOWEB, p.CLIENTE, p.VLRTOTAL",
            $webOrderIds
        );

        /** @var array<string, array<int, array<string, mixed>>> $erpRowsByWebOrder */
        $erpRowsByWebOrder = [];
        foreach ($erpRows as $erpRow) {
            $webOrderId = trim((string) ($erpRow['PEDIDOWEB'] ?? ''));
            if ($webOrderId !== '') {
                $erpRowsByWebOrder[$webOrderId][] = $erpRow;
            }
        }

        $reconciled = 0;
        $mismatches = 0;
        $duplicates = 0;
        $errors = 0;

        foreach ($candidates as $candidate) {
            $webOrderId = (string) (int) $candidate['order_id'];
            $matches = $erpRowsByWebOrder[$webOrderId] ?? [];
            if ($matches === []) {
                continue;
            }

            if (count($matches) !== 1) {
                $duplicates++;
                $this->logger->error('[B2B-Sectra] Duplicate PEDIDOWEB found; automatic ACK skipped', [
                    'oc_order_id' => $webOrderId,
                    'matches' => count($matches),
                ]);
                continue;
            }

            $erpRow = $matches[0];
            $isExactMatch =
                (int) ($erpRow['CLIENTE'] ?? 0) === (int) $candidate['customer_id']
                && abs((float) ($erpRow['VLRTOTAL'] ?? 0) - (float) $candidate['total']) <= 0.01
                && (int) ($erpRow['item_count'] ?? 0) === (int) $candidate['item_count']
                && abs((float) ($erpRow['qty_total'] ?? 0) - (float) $candidate['qty_total']) <= 0.0001
                && abs((float) ($erpRow['items_total'] ?? 0) - (float) $candidate['items_total']) <= 0.01;

            if (!$isExactMatch) {
                $mismatches++;
                $this->logger->warning('[B2B-Sectra] Existing ERP order differs from Magento; ACK skipped', [
                    'oc_order_id' => $webOrderId,
                    'erp_order_id' => (int) ($erpRow['CODIGO'] ?? 0),
                ]);
                continue;
            }

            try {
                $response = $this->orderPull->acknowledgeOrder(
                    (string) $candidate['increment_id'],
                    (string) (int) $erpRow['CODIGO'],
                    'ACK reconciliado automaticamente após importação pelo desktop Sectra'
                );
                if (($response[0]['success'] ?? false) === true) {
                    $reconciled++;
                } else {
                    $errors++;
                }
            } catch (\Throwable $exception) {
                $errors++;
                $this->logger->error('[B2B-Sectra] Automatic ACK reconciliation failed', [
                    'oc_order_id' => $webOrderId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $reconciled;
    }

    private function isB2bCustomer(int $customerId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $groupId = (int) $connection->fetchOne(
            'SELECT group_id FROM customer_entity WHERE entity_id = ?',
            [$customerId]
        );

        return B2bGroupIds::contains($this->resourceConnection, $groupId);
    }

    private function setOrderImportStatus(int $orderId, string $status): void
    {
        $this->resourceConnection->getConnection()->update(
            'sales_order',
            ['sectra_import_status' => $status],
            ['entity_id = ?' => $orderId]
        );
    }

    private function addOrderComment(OrderInterface $order, string $comment): void
    {
        if (!$order instanceof Order) {
            return;
        }

        try {
            $order->addCommentToStatusHistory($comment, false, false);
            $order->save();
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[B2B-Sectra] Pedido #%s: falha ao adicionar comentário — %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
