<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Api;

use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\CustomerSync;
use GrupoAwamotos\ERPIntegration\Model\B2BClientRegistration;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Psr\Log\LoggerInterface;

class OrderPullManagement implements OrderPullInterface
{
    private ConnectionInterface $connection;
    private Helper $helper;
    private CustomerSync $customerSync;
    private B2BClientRegistration $b2bRegistration;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private SortOrderBuilder $sortOrderBuilder;
    private SyncLogResource $syncLogResource;
    private CustomerRepositoryInterface $customerRepository;
    private RegionFactory $regionFactory;
    private LoggerInterface $logger;
    /** @var array<int, bool> */
    private array $clientRegistrationCache = [];
    private ?bool $writeAccessCache = null;

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        CustomerSync $customerSync,
        B2BClientRegistration $b2bRegistration,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder,
        SyncLogResource $syncLogResource,
        CustomerRepositoryInterface $customerRepository,
        RegionFactory $regionFactory,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->customerSync = $customerSync;
        $this->b2bRegistration = $b2bRegistration;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderBuilder = $sortOrderBuilder;
        $this->syncLogResource = $syncLogResource;
        $this->customerRepository = $customerRepository;
        $this->regionFactory = $regionFactory;
        $this->logger = $logger;
    }

    public function getPendingOrders(int $limit = 50, ?string $fromDate = null): array
    {
        $this->logger->info('[ERP API] getPendingOrders called', ['limit' => $limit, 'fromDate' => $fromDate]);

        $allOrders = $this->fetchUnsyncedOrders($limit * 2, $fromDate);

        $orders = [];
        $held = [];
        $hasWriteAccess = $this->hasWriteAccess();

        foreach ($allOrders as $order) {
            try {
                // Skip orders that are explicitly blocked — they need manual resolution
                $importStatus = $order->getData('sectra_import_status');
                if (in_array($importStatus, [
                    'order_blocked_product_not_registered',
                    'order_cancelled_before_erp_import',
                    'imported',
                    ], true)
                ) {
                    continue;
                }

                $erpClientCode = $this->resolveErpClientCode($order);

                // Orders without any ERP client code are truly unresolvable — hold them
                if ($erpClientCode <= 0) {
                    $held[] = [
                        'increment_id' => $order->getIncrementId(),
                        'erp_code' => 0,
                        'customer' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
                        'reason' => 'Cliente sem erp_code no Magento',
                    ];
                    continue;
                }

                // Guarantee only Sectra-validated clients are exposed as pending
                $clientRegistered = $this->isClientRegisteredCached($erpClientCode);
                if (!$clientRegistered) {
                    if ($hasWriteAccess) {
                        $clientRegistered = $this->registerClientCached($erpClientCode);
                        $hasWriteAccess = $this->hasWriteAccess();
                        if (!$clientRegistered) {
                            // Write available but registration failed — hold and warn
                            $held[] = [
                                'increment_id' => $order->getIncrementId(),
                                'erp_code' => $erpClientCode,
                                'customer' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
                                'reason' => 'Falha ao registrar cliente no GR_INTEGRACAOVALIDADOR. Verificar logs.',
                            ];
                            $this->logger->warning('[ERP API] Order held - auto-registration failed', [
                                'increment_id' => $order->getIncrementId(),
                                'erp_code' => $erpClientCode,
                            ]);
                            continue;
                        }
                    } else {
                        $held[] = [
                            'increment_id' => $order->getIncrementId(),
                            'erp_code' => $erpClientCode,
                            'customer' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
                            'reason' => 'Cliente nao registrado no GR_INTEGRACAOVALIDADOR e conexao de escrita desativada',
                        ];
                        $this->logger->info('[ERP API] Order held - unregistered client and no write access', [
                            'increment_id' => $order->getIncrementId(),
                            'erp_code' => $erpClientCode,
                        ]);
                        continue;
                    }
                }

                $orders[] = $this->buildOrderPayload($order, $clientRegistered);
                if (count($orders) >= $limit) {
                    break;
                }
            } catch (\Exception $e) {
                $this->logger->warning('[ERP API] Failed to build payload for order ' . $order->getIncrementId(), [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->syncLogResource->addLog(
            'order_pull',
            'export',
            'success',
            sprintf('Listed %d pending orders via API (%d held)', count($orders), count($held))
        );

        // Wrap in indexed array so Magento's ServiceOutputProcessor preserves keys
        return [[
            'orders' => $orders,
            'total_count' => count($orders),
            'held_count' => count($held),
            'held_orders' => $held,
            'timestamp' => date('c'),
        ]];
    }

    public function getOrderDetails(string $incrementId): array
    {
        $this->logger->info('[ERP API] getOrderDetails called', ['increment_id' => $incrementId]);

        $order = $this->findOrderByIncrementId($incrementId);

        // Wrap in indexed array so Magento's ServiceOutputProcessor preserves keys
        return [$this->buildOrderPayload($order)];
    }

    public function acknowledgeOrder(
        string $incrementId,
        string $erpOrderId,
        ?string $message = null
    ): array {
        $this->logger->info('[ERP API] acknowledgeOrder called', [
            'increment_id' => $incrementId,
            'erp_order_id' => $erpOrderId,
        ]);

        // Validate that erpOrderId is a numeric VE_PEDIDO.CODIGO
        if (!ctype_digit($erpOrderId) || (int) $erpOrderId <= 0) {
            $this->logger->error('[ERP API] acknowledgeOrder rejected: erpOrderId must be a positive integer', [
                'increment_id' => $incrementId,
                'erp_order_id' => $erpOrderId,
            ]);
            return [[
                'success' => false,
                'message' => sprintf(
                    'erpOrderId must be a positive integer (VE_PEDIDO.CODIGO). Received: "%s"',
                    $erpOrderId
                ),
                'increment_id' => $incrementId,
                'erp_order_id' => $erpOrderId,
            ]];
        }

        $order = $this->findOrderByIncrementId($incrementId);

        // Store ERP mapping
        $this->syncLogResource->setEntityMap(
            'order',
            $erpOrderId,
            (int) $order->getEntityId()
        );

        // Update order status to 'processing' if still pending/new
        $currentState = $order->getState();
        if (in_array($currentState, [
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
            ], true)
        ) {
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus('processing');
        }

        // Add order comment
        $comment = sprintf(
            '[ERP] Pedido recebido pelo ERP via API. ID ERP: %s%s',
            $erpOrderId,
            $message ? ' - ' . $message : ''
        );
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);

        // Mark as imported in both bridge tables so the Sectra Desktop and oc_order VIEW
        // don't see this order again (prevents double-import during the 5-min cron window)
        try {
            $connection = $this->syncLogResource->getConnection();
            $connection->update(
                'sales_order',
                ['sectra_import_status' => 'imported'],
                ['entity_id = ?' => (int) $order->getEntityId()]
            );
            $ocOrderId = (int) $order->getEntityId() + 200000;
            $existing = $connection->fetchOne(
                $connection->select()
                    ->from('oc_order_imported', 'order_id')
                    ->where('order_id = ?', $ocOrderId)
            );
            if (!$existing) {
                $connection->insert(
                    'oc_order_imported',
                    ['order_id' => $ocOrderId, 'date_imported' => date('Y-m-d H:i:s')]
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ERP API] Failed to update bridge import status: ' . $e->getMessage());
        }

        // Log
        $this->syncLogResource->addLog(
            'order_pull',
            'export',
            'success',
            sprintf('Order %s acknowledged by ERP. ERP ID: %s', $incrementId, $erpOrderId),
            $erpOrderId,
            (int) $order->getEntityId()
        );

        $this->logger->info('[ERP API] Order acknowledged', [
            'increment_id' => $incrementId,
            'erp_order_id' => $erpOrderId,
        ]);

        // Wrap in indexed array so Magento's ServiceOutputProcessor preserves keys
        return [[
            'success' => true,
            'message' => sprintf('Order %s acknowledged. ERP ID: %s', $incrementId, $erpOrderId),
            'increment_id' => $incrementId,
            'erp_order_id' => $erpOrderId,
            'acknowledged_at' => date('c'),
        ]];
    }

    public function getCanceledOrders(?string $fromDate = null): array
    {
        $this->logger->info('[ERP API] getCanceledOrders called', ['fromDate' => $fromDate]);

        $syncedOrderIds = $this->getSyncedOrderIds();

        $this->searchCriteriaBuilder->addFilter('state', 'canceled');

        if ($fromDate) {
            $this->searchCriteriaBuilder->addFilter('created_at', $fromDate, 'gteq');
        }

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setAscendingDirection()
            ->create();
        $this->searchCriteriaBuilder->setSortOrders([$sortOrder]);
        $this->searchCriteriaBuilder->setPageSize(100);

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $orderList = $this->orderRepository->getList($searchCriteria);

        $orders = [];
        foreach ($orderList->getItems() as $order) {
            if (in_array((int) $order->getEntityId(), $syncedOrderIds, true)) {
                continue;
            }

            $orders[] = [
                'increment_id' => $order->getIncrementId(),
                'entity_id' => (int) $order->getEntityId(),
                'state' => $order->getState(),
                'status' => $order->getStatus(),
                'grand_total' => (float) $order->getGrandTotal(),
                'customer_email' => $order->getCustomerEmail(),
                'created_at' => $order->getCreatedAt(),
                'canceled_at' => $order->getUpdatedAt(),
            ];
        }

        return [[
            'orders' => $orders,
            'total_count' => count($orders),
            'timestamp' => date('c'),
        ]];
    }

    public function getHeldOrders(int $limit = 50): array
    {
        $this->logger->info('[ERP API] getHeldOrders called', ['limit' => $limit]);

        $allOrders = $this->fetchUnsyncedOrders($limit * 3, null);

        $held = [];
        foreach ($allOrders as $order) {
            if (count($held) >= $limit) {
                break;
            }

            try {
                $erpClientCode = $this->resolveErpClientCode($order);

                if ($erpClientCode <= 0) {
                    $held[] = $this->buildHeldOrderPayload($order, 0, 'Cliente sem erp_code no Magento');
                    continue;
                }

                if (!$this->isClientRegisteredCached($erpClientCode)) {
                    $held[] = $this->buildHeldOrderPayload(
                        $order,
                        $erpClientCode,
                        'Cliente nao registrado no GR_INTEGRACAOVALIDADOR do Sectra'
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning('[OrderPullManagement::getHeldOrders] Exceção ignorada: ' . $e->getMessage());
                // skip
            }
        }

        $hasWriteAccess = $this->hasWriteAccess();

        return [[
            'orders' => $held,
            'total_held' => count($held),
            'write_connection_available' => $hasWriteAccess,
            'resolution' => $hasWriteAccess
                ? 'Auto-registro habilitado. Clientes serao registrados automaticamente na proxima chamada de getPendingOrders.'
                : 'Configurar credenciais de escrita em Stores > Config > GrupoAwamotos > ERP Integration > Conexao de Escrita, ou executar SQL no Sectra.',
            'timestamp' => date('c'),
        ]];
    }

    // ==================== Private Methods ====================

    /**
     * Fetch unsynced orders from Magento
     *
     * @return OrderInterface[]
     */
    private function fetchUnsyncedOrders(int $limit, ?string $fromDate): array
    {
        $syncedOrderIds = $this->getSyncedOrderIds();

        $this->searchCriteriaBuilder->addFilter(
            'state',
            ['new', 'pending_payment', 'processing'],
            'in'
        );

        if ($fromDate) {
            $this->searchCriteriaBuilder->addFilter('created_at', $fromDate, 'gteq');
        }

        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setAscendingDirection()
            ->create();

        $this->searchCriteriaBuilder->setSortOrders([$sortOrder]);
        $this->searchCriteriaBuilder->setPageSize($limit);

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $orderList = $this->orderRepository->getList($searchCriteria);

        $orders = [];
        foreach ($orderList->getItems() as $order) {
            if (!in_array((int) $order->getEntityId(), $syncedOrderIds, true)) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    private function buildHeldOrderPayload(OrderInterface $order, int $erpClientCode, string $reason): array
    {
        return [
            'increment_id' => $order->getIncrementId(),
            'magento_id' => (int) $order->getEntityId(),
            'erp_code' => $erpClientCode,
            'customer' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
            'customer_email' => $order->getCustomerEmail() ?? '',
            'grand_total' => (float) $order->getGrandTotal(),
            'created_at' => $order->getCreatedAt(),
            'status' => $order->getStatus(),
            'reason' => $reason,
        ];
    }

    private function findOrderByIncrementId(string $incrementId): OrderInterface
    {
        $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        if (empty($orders)) {
            throw new NoSuchEntityException(
                __('Order with increment_id "%1" not found.', $incrementId)
            );
        }

        return reset($orders);
    }

    private function getSyncedOrderIds(): array
    {
        try {
            $connection = $this->syncLogResource->getConnection();

            // REST API ACK path (Sync-MagentoOrders.ps1 → POST /ack)
            $select = $connection->select()
                ->from('grupoawamotos_erp_entity_map', ['magento_entity_id'])
                ->where('entity_type = ?', 'order');
            $ids1 = array_map('intval', $connection->fetchCol($select));

            // Legacy Sectra Desktop path (oc_order_imported, order_id = entity_id + 200000)
            $select2 = $connection->select()
                ->from('oc_order_imported', ['order_id'])
                ->where('order_id > ?', 200000);
            $rawIds = $connection->fetchCol($select2);
            $ids2 = array_map(static fn($id) => (int)$id - 200000, $rawIds);

            return array_values(array_unique(array_merge($ids1, $ids2)));
        } catch (\Exception $e) {
            $this->logger->warning('[ERP API] Failed to get synced order IDs: ' . $e->getMessage());
            return [];
        }
    }

    private function buildOrderPayload(OrderInterface $order, ?bool $clientRegistered = null): array
    {
        // Resolve ERP client code
        $erpClientCode = $this->resolveErpClientCode($order);

        // Fetch customer commercial data from ERP
        $erpCustomerData = $this->getErpCustomerOrderData($erpClientCode);

        // Check and auto-register client in Sectra B2B integration
        if ($clientRegistered === null) {
            $clientRegistered = $this->isClientRegisteredCached($erpClientCode);
            if (!$clientRegistered && $this->hasWriteAccess()) {
                // Attempt auto-registration only when write connection is available
                $clientRegistered = $this->registerClientCached($erpClientCode);
            }
        }

        // Build items
        $items = $this->buildItemsPayload($order);

        // Shipping address
        $shipping = $order->getShippingAddress();

        return [
            'increment_id' => $order->getIncrementId(),
            'magento_id' => (int) $order->getEntityId(),
            'created_at' => $order->getCreatedAt(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'store_id' => (int) $order->getStoreId(),

            // Financial (VE_PEDIDO.VLR*)
            'subtotal' => (float) $order->getSubtotal(),
            'discount' => abs((float) $order->getDiscountAmount()),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'grand_total' => (float) $order->getGrandTotal(),

            // Customer
            'customer' => [
                'erp_code' => $erpClientCode,
                'taxvat' => $order->getCustomerTaxvat() ?? '',
                'name' => trim(($order->getCustomerFirstname() ?? '') . ' ' . ($order->getCustomerLastname() ?? '')),
                'email' => $order->getCustomerEmail() ?? '',
                'registered_in_b2b' => $clientRegistered,
            ],

            // ERP Commercial Conditions (from FN_FORNECEDORES)
            'erp_conditions' => [
                'vendedor' => (int) ($erpCustomerData['VENDPREF'] ?? 0),
                'cond_pagto' => (int) ($erpCustomerData['CONDPAGTO'] ?? 0),
                'fator_preco' => (int) ($erpCustomerData['FATORPRECO'] ?? 0),
                'contato' => (string) ($erpCustomerData['CONTATO_NOME'] ?? ''),
                'transportadora' => (int) ($erpCustomerData['TRANSPPREF'] ?? 0),
                'tp_fator' => (string) ($erpCustomerData['TPFATOR'] ?? ''),
                'perc_fator' => (float) ($erpCustomerData['PERCFATOR'] ?? 0),
            ],

            // ERP order fields (VE_PEDIDO direct mapping)
            'erp_status' => 'W',              // STATUS = 'W' (Ped. Web)
            'filial' => $this->helper->getStockFilial(),
            'pedidoweb' => $order->getIncrementId(),
            'pedidocli' => $order->getIncrementId(),
            'username' => 'MAGENTO',

            // Shipping Address (VE_PEDIDO.ENT*)
            'shipping_address' => [
                'street' => $shipping ? implode(', ', $shipping->getStreet()) : '',
                'neighborhood' => $this->extractBairro($shipping),
                'city' => $shipping ? ($shipping->getCity() ?? '') : '',
                'region' => $shipping ? $this->resolveRegionCode($shipping) : '',
                'postcode' => $shipping ? ($shipping->getPostcode() ?? '') : '',
            ],

            // Payment
            'payment_method' => $order->getPayment() ? $order->getPayment()->getMethod() : '',

            // Bridge status (diagnostic — do not import if not ready_for_import)
            'sectra_import_status' => $order->getData('sectra_import_status') ?? '',

            // Items (VE_PEDIDOITENS)
            'items' => $items,
            'items_count' => count($items),
        ];
    }

    private function buildItemsPayload(OrderInterface $order): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            if ((float) $item->getQtyOrdered() <= 0) {
                continue;
            }

            $unidade = $this->getErpProductUnit($item->getSku());

            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float) $item->getQtyOrdered(),
                'unit' => $unidade,
                'unit_price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'discount' => abs((float) $item->getDiscountAmount()),
                'row_total_incl_discount' => (float) $item->getRowTotal() - abs((float) $item->getDiscountAmount()),
            ];
        }

        return $items;
    }

    private function resolveErpClientCode(OrderInterface $order): int
    {
        // 0. Check if already stamped on the order (set by OrderPlaceAfter observer)
        $stamped = $order->getData('customer_erp_code');
        if ($stamped && is_numeric($stamped)) {
            return (int) $stamped;
        }

        // 1. Lookup by taxvat (CPF/CNPJ) in ERP directly
        $taxvat = $order->getCustomerTaxvat();
        if ($taxvat) {
            $erpCustomer = $this->customerSync->getErpCustomerByTaxvat($taxvat);
            if ($erpCustomer) {
                return (int) $erpCustomer['CODIGO'];
            }
        }

        $customerId = $order->getCustomerId();
        if (!$customerId) {
            $this->logger->warning('[ERP API] Order has no customer_id (guest order)', [
                'increment_id' => $order->getIncrementId(),
            ]);
            return 0;
        }

        // 2. Entity map lookup
        $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', (int) $customerId);
        if ($erpCode && is_numeric($erpCode)) {
            return (int) $erpCode;
        }

        // 3. Customer erp_code attribute (definitive fallback)
        try {
            $customer = $this->customerRepository->getById((int) $customerId);
            $attr = $customer->getCustomAttribute('erp_code');
            if ($attr && $attr->getValue() && is_numeric($attr->getValue())) {
                $this->logger->info('[ERP API] Resolved ERP code from customer attribute', [
                    'customer_id' => $customerId,
                    'erp_code' => $attr->getValue(),
                ]);
                return (int) $attr->getValue();
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ERP API] Failed to load customer for ERP code: ' . $e->getMessage());
        }

        $this->logger->error('[ERP API] Could not resolve ERP client code for order', [
            'increment_id' => $order->getIncrementId(),
            'customer_id' => $customerId,
            'taxvat' => $taxvat,
        ]);

        return 0;
    }

    private function getErpCustomerOrderData(int $erpClientCode): array
    {
        if ($erpClientCode <= 0) {
            return [];
        }

        try {
            $sql = "SELECT f.CONDPAGTO, f.FATORPRECO, f.TRANSPPREF, f.VENDPREF,
                           f.TPFATOR, f.PERCFATOR,
                           c.NOME AS CONTATO_NOME
                    FROM FN_FORNECEDORES f
                    LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = f.CODIGO AND c.PRINCIPAL = 'S'
                    WHERE f.CODIGO = :code AND f.CKCLIENTE = 'S'";

            $result = $this->connection->fetchOne($sql, [':code' => $erpClientCode]);
            return $result ?: [];
        } catch (\Exception $e) {
            $this->logger->warning('[ERP API] Failed to fetch customer data: ' . $e->getMessage());
            return [];
        }
    }

    private function getErpProductUnit(string $sku): string
    {
        try {
            $sql = "SELECT UNDVENDA FROM MT_MATERIAL WHERE CODIGO = :sku";
            $result = $this->connection->fetchOne($sql, [':sku' => $sku]);
            return $result ? (string) ($result['UNDVENDA'] ?? 'PC') : 'PC';
        } catch (\Exception $e) {
            return 'PC';
        }
    }

    private function resolveRegionCode($shipping): string
    {
        $countryId = $shipping->getCountryId() ?? 'BR';

        // Prefer region name lookup (more reliable than region_id for BR addresses)
        $regionName = $shipping->getRegion();
        if ($regionName) {
            $region = $this->regionFactory->create()->loadByName($regionName, $countryId);
            if ($region->getId()) {
                return $region->getCode();
            }
        }

        // Fallback: use region_id
        $regionId = $shipping->getRegionId();
        if ($regionId) {
            $region = $this->regionFactory->create()->load($regionId);
            if ($region->getId()) {
                return $region->getCode();
            }
        }

        return $shipping->getRegionCode() ?? '';
    }

    private function extractBairro($shipping): string
    {
        if (!$shipping) {
            return '';
        }

        $street = $shipping->getStreet();
        if (!is_array($street)) {
            return '';
        }
        return $street[2] ?? $street[1] ?? '';
    }

    private function hasWriteAccess(): bool
    {
        if ($this->writeAccessCache === null) {
            $this->writeAccessCache = $this->b2bRegistration->hasWriteAccess();
        }

        return $this->writeAccessCache;
    }

    private function isClientRegisteredCached(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        if (array_key_exists($erpClientCode, $this->clientRegistrationCache)) {
            return $this->clientRegistrationCache[$erpClientCode];
        }

        $registered = $this->b2bRegistration->isClientRegistered($erpClientCode);
        $this->clientRegistrationCache[$erpClientCode] = $registered;

        return $registered;
    }

    private function registerClientCached(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0 || !$this->hasWriteAccess()) {
            return false;
        }

        $registered = $this->b2bRegistration->registerClient($erpClientCode);
        $this->clientRegistrationCache[$erpClientCode] = $registered;

        if (!$registered) {
            // Refresh write access cache in case permission was revoked during INSERT attempt
            $this->writeAccessCache = $this->b2bRegistration->hasWriteAccess();
        }

        return $registered;
    }
}
