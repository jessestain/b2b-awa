<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\OrderSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Convert\Order as OrderConverter;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction;
use Psr\Log\LoggerInterface;

class OrderSync implements OrderSyncInterface
{
    private const ALLOW_GUEST_ORDERS = false;
    private const GUEST_CLIENT_CODE = 0;
    private const OPTIONAL_COLUMNS_CACHE_FILE = 'erp_ve_pedido_optional_columns.json';

    /**
     * Mapeamento de status ERP -> Magento
     */
    private const STATUS_MAP = [
        'A' => ['state' => Order::STATE_NEW, 'status' => 'pending'],
        'P' => ['state' => Order::STATE_PROCESSING, 'status' => 'processing'],
        'F' => ['state' => Order::STATE_PROCESSING, 'status' => 'faturado'],
        'E' => ['state' => Order::STATE_COMPLETE, 'status' => 'complete'],
        'C' => ['state' => Order::STATE_CANCELED, 'status' => 'canceled'],
        'D' => ['state' => Order::STATE_HOLDED, 'status' => 'holded'],
    ];

    /**
     * ERP status codes acknowledged but requiring no Magento status change.
     * 'W' = Aguardando importacao no Sectra (pedido recebido, pendente de processamento)
     * 'T' = Transferido (pedido enviado para outra filial/processo)
     */
    private const STATUS_PASSTHROUGH = ['W', 'T'];

    private ConnectionInterface $connection;
    private Helper $helper;
    private SyncLogResource $syncLogResource;
    private CustomerSync $customerSync;
    private OrderRepositoryInterface $orderRepository;
    private ShipmentRepositoryInterface $shipmentRepository;
    private OrderConverter $orderConverter;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private Transaction $transaction;
    private LoggerInterface $logger;
    private CustomerRepositoryInterface $customerRepository;

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        SyncLogResource $syncLogResource,
        CustomerSync $customerSync,
        OrderRepositoryInterface $orderRepository,
        ShipmentRepositoryInterface $shipmentRepository,
        OrderConverter $orderConverter,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Transaction $transaction,
        LoggerInterface $logger,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->syncLogResource = $syncLogResource;
        $this->customerSync = $customerSync;
        $this->orderRepository = $orderRepository;
        $this->shipmentRepository = $shipmentRepository;
        $this->orderConverter = $orderConverter;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->customerRepository = $customerRepository;
    }

    public function sendOrder(OrderInterface $order): array
    {
        $startTime = microtime(true);
        $result = [
            'success' => false,
            'erp_order_id' => null,
            'message' => '',
            'items_synced' => 0,
            'execution_time' => 0,
            'retryable' => true,
        ];

        try {
            $erpClientCode = $this->resolveErpClientCode($order);

            if ($erpClientCode === 0 && !self::ALLOW_GUEST_ORDERS) {
                $taxvat = $order->getCustomerTaxvat() ?: 'não informado';
                $result['message'] = sprintf(
                    'Cliente não encontrado no ERP. CPF/CNPJ: %s. ' .
                    'Cadastre o cliente no ERP antes de sincronizar o pedido.',
                    $taxvat
                );

                $this->logger->warning('[ERP] Order rejected - customer not found', [
                    'order_id' => $order->getIncrementId(),
                    'customer_taxvat' => $taxvat,
                    'customer_id' => $order->getCustomerId(),
                ]);

                $this->syncLogResource->addLog(
                    'order',
                    'export',
                    'error',
                    $result['message'],
                    null,
                    (int) $order->getEntityId()
                );

                $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
                return $result;
            }

            // Fetch customer commercial data from ERP (VENDEDOR, CONDPAGTO, FATORPRECO, etc.)
            $erpCustomerData = $this->getErpCustomerOrderData($erpClientCode);

            $items = $this->getValidOrderItems($order);
            if (empty($items)) {
                $result['message'] = 'Pedido sem itens válidos para sincronizar.';
                $this->syncLogResource->addLog(
                    'order',
                    'export',
                    'error',
                    $result['message'],
                    null,
                    (int) $order->getEntityId()
                );
                $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
                return $result;
            }

            $this->connection->beginTransaction();

            try {
                $erpOrderId = $this->insertOrderHeader($order, $erpClientCode, $erpCustomerData);

                if ($erpOrderId <= 0) {
                    throw new \RuntimeException('Não foi possível obter o ID do pedido criado no ERP.');
                }

                $itemsSynced = $this->insertOrderItems($erpOrderId, $items);

                $this->connection->commit();

                $result['success'] = true;
                $result['erp_order_id'] = $erpOrderId;
                $result['items_synced'] = $itemsSynced;
                $result['message'] = sprintf(
                    'Pedido enviado ao ERP com sucesso. ID ERP: %d, Itens: %d',
                    $erpOrderId,
                    $itemsSynced
                );

                $this->logger->info('[ERP] Order synced successfully', [
                    'order_id' => $order->getIncrementId(),
                    'erp_order_id' => $erpOrderId,
                    'items_count' => $itemsSynced,
                    'client_code' => $erpClientCode,
                ]);

                $this->syncLogResource->addLog(
                    'order',
                    'export',
                    'success',
                    $result['message'],
                    (string) $erpOrderId,
                    (int) $order->getEntityId()
                );

                $this->syncLogResource->setEntityMap(
                    'order',
                    (string) $erpOrderId,
                    (int) $order->getEntityId()
                );
            } catch (\Exception $e) {
                try {
                    $this->connection->rollback();
                } catch (\Exception $rollbackEx) {
                    $this->logger->debug('[ERP] Rollback after error: ' . $rollbackEx->getMessage());
                }
                throw $e;
            }
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            $isPermissionDenied = stripos($msg, 'permission') !== false
                || (stripos($msg, 'INSERT') !== false && stripos($msg, 'denied') !== false);

            if ($isPermissionDenied) {
                $result['message'] = 'Modo PULL ativo — pedidos são obtidos pelo ERP via API REST (GET /V1/erp/orders/pending).';
                $result['retryable'] = false;

                $this->logger->info('[ERP] PULL mode active - order available via REST API', [
                    'order_id' => $order->getIncrementId(),
                ]);

                $this->syncLogResource->addLog(
                    'order',
                    'export',
                    'info',
                    $result['message'],
                    null,
                    (int) $order->getEntityId()
                );
            } else {
                $result['message'] = 'Erro de banco ao enviar pedido ao ERP: ' . $msg;

                $this->logger->error('[ERP] Order sync PDO error', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $msg,
                ]);

                $this->syncLogResource->addLog(
                    'order',
                    'export',
                    'error',
                    $result['message'],
                    null,
                    (int) $order->getEntityId()
                );
            }
        } catch (\Exception $e) {
            $result['message'] = 'Erro ao enviar pedido ao ERP: ' . $e->getMessage();

            $this->logger->error('[ERP] Order sync error', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->syncLogResource->addLog(
                'order',
                'export',
                'error',
                $e->getMessage(),
                null,
                (int) $order->getEntityId()
            );
        }

        $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

        return $result;
    }

    public function syncOrderStatuses(): array
    {
        $result = ['synced' => 0, 'errors' => 0, 'skipped' => 0];

        if (!$this->helper->isOrderSyncEnabled()) {
            $this->logger->info('[ERP] Order status sync is disabled');
            return $result;
        }

        try {
            // Busca pedidos do Magento que têm mapeamento com ERP e não estão completos/cancelados
            $pendingOrders = $this->getPendingOrdersForSync();

            $this->logger->info(sprintf('[ERP] Syncing status for %d orders', count($pendingOrders)));

            foreach ($pendingOrders as $orderData) {
                try {
                    $erpOrderId = (int) $orderData['erp_code'];
                    $magentoOrderId = (int) $orderData['magento_entity_id'];

                    $syncResult = $this->updateOrderStatus($magentoOrderId);

                    if ($syncResult['success']) {
                        $result['synced']++;
                    } elseif ($syncResult['passthrough'] ?? false) {
                        // ERP reconhece o pedido mas nao requer mudanca de status no Magento
                        $result['synced']++;
                    } else {
                        $result['skipped']++;
                        $this->logger->debug(sprintf(
                            '[ERP] Order %d skipped: %s (erp_order=%d)',
                            $magentoOrderId,
                            $syncResult['message'] ?? 'unknown',
                            $erpOrderId
                        ));
                    }
                } catch (\Exception $e) {
                    $result['errors']++;
                    $this->logger->error(sprintf(
                        '[ERP] Order status sync error for order %d: %s',
                        $magentoOrderId ?? 0,
                        $e->getMessage()
                    ));
                }
            }

            $this->syncLogResource->addLog(
                'order_status',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf('Sincronizados: %d, Erros: %d, Ignorados: %d', $result['synced'], $result['errors'], $result['skipped'])
            );
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Order status sync failed: ' . $e->getMessage());
        }

        return $result;
    }

    public function getErpOrderStatus(int $erpOrderId): ?array
    {
        try {
            // Query base sem CODRASTREIO (coluna pode não existir na view VE_PEDIDO)
            // Tenta com CODRASTREIO primeiro; se falhar, faz fallback sem ela
            $result = $this->fetchOrderStatusWithTracking($erpOrderId);

            if ($result) {
                // Tentar buscar nome da transportadora separadamente
                $result['TRANSPORTADORA_NOME'] = $this->getTransportadoraNome(
                    (int) ($result['TRANSPORTADORA'] ?? 0)
                );
                // Garantir que CODRASTREIO existe no array (pode ser null)
                if (!array_key_exists('CODRASTREIO', $result)) {
                    $result['CODRASTREIO'] = null;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Get order status error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca status do pedido no ERP com detecção automática de colunas disponíveis.
     *
     * A view VE_PEDIDO pode não ter todas as colunas (CODRASTREIO, TRANSPORTADORA).
     * Detectamos quais colunas existem na primeira chamada e reutilizamos para as demais.
     */
    private function fetchOrderStatusWithTracking(int $erpOrderId): ?array
    {
        /** @var string[]|null Colunas opcionais confirmadas como disponíveis */
        static $availableOptionalColumns = null;

        // Colunas obrigatórias (CODIGO e STATUS certamente existem em VE_PEDIDO)
        $baseColumns = [
            'p.CODIGO', 'p.STATUS'
        ];

        // Colunas opcionais (podem não existir na view — detectadas automaticamente)
        $optionalColumns = [
            'DTFATURAMENTO', 'DTSAIDA', 'DTENTREGA',
            'NFNUMERO', 'NFSERIE', 'NFCHAVE',
            'TRANSPORTADORA', 'CODRASTREIO',
            'TPPAGAMENTO', 'DTPEDIDO',
        ];

        if ($availableOptionalColumns === null) {
            $cachedColumns = $this->loadCachedOptionalColumns($optionalColumns);
            if ($cachedColumns !== null) {
                $availableOptionalColumns = $cachedColumns;
            }
        }

        // Se já detectamos as colunas disponíveis, ir direto à query segura
        if ($availableOptionalColumns !== null) {
            try {
                return $this->executeStatusQuery($baseColumns, $availableOptionalColumns, $erpOrderId, $optionalColumns);
            } catch (\Exception $e) {
                if (!$this->isMissingColumnException($e, $optionalColumns)) {
                    throw $e;
                }
                $availableOptionalColumns = null;
                $this->clearCachedOptionalColumns();
            }
        }

        // Primeira execução: tentar com TODAS as colunas opcionais
        $columnsToTry = $optionalColumns;

        while (true) {
            try {
                $allColumns = array_merge($baseColumns, array_map(fn($c) => "p.$c", $columnsToTry));
                $sql = "SELECT " . implode(', ', $allColumns) . "
                        FROM VE_PEDIDO p
                        WHERE p.CODIGO = :codigo";

                $result = $this->connection->fetchOne($sql, [':codigo' => $erpOrderId]);

                // Sucesso — cachear colunas disponíveis
                $availableOptionalColumns = $columnsToTry;
                $this->saveCachedOptionalColumns($availableOptionalColumns);

                if ($result) {
                    // Definir colunas faltantes como null no resultado
                    foreach (array_diff($optionalColumns, $columnsToTry) as $missing) {
                        $result[$missing] = null;
                    }
                }

                return $result;
            } catch (\Exception $e) {
                // Verificar se é erro de coluna inválida
                $removed = false;
                foreach ($columnsToTry as $idx => $col) {
                    if (stripos($e->getMessage(), $col) !== false) {
                        $this->logger->info(
                            sprintf('[ERP] VE_PEDIDO does not have %s column, removing from query', $col)
                        );
                        unset($columnsToTry[$idx]);
                        $columnsToTry = array_values($columnsToTry);
                        $removed = true;
                        break;
                    }
                }

                if (!$removed) {
                    // Erro não é de coluna inválida — propagar
                    throw $e;
                }

                // Se não sobrou nenhuma coluna opcional, usar apenas as base
                if (empty($columnsToTry)) {
                    $availableOptionalColumns = [];
                    $this->saveCachedOptionalColumns($availableOptionalColumns);
                    break;
                }
            }
        }

        // Fallback final: apenas colunas base
        return $this->executeStatusQuery($baseColumns, [], $erpOrderId, $optionalColumns);
    }

    /**
     * Executa query de status com as colunas determinadas.
     *
     * @param string[] $baseColumns     Colunas obrigatórias (já com prefixo p.)
     * @param string[] $optionalAvail   Colunas opcionais disponíveis (sem prefixo)
     * @param int      $erpOrderId      ID do pedido no ERP
     * @param string[] $allOptional     Todas as colunas opcionais (para preencher nulls)
     * @return array<string, mixed>|null
     */
    private function executeStatusQuery(
        array $baseColumns,
        array $optionalAvail,
        int $erpOrderId,
        array $allOptional
    ): ?array {
        $allColumns = array_merge($baseColumns, array_map(fn($c) => "p.$c", $optionalAvail));
        $sql = "SELECT " . implode(', ', $allColumns) . "
                FROM VE_PEDIDO p
                WHERE p.CODIGO = :codigo";

        $result = $this->connection->fetchOne($sql, [':codigo' => $erpOrderId]);

        if ($result) {
            foreach (array_diff($allOptional, $optionalAvail) as $missing) {
                $result[$missing] = null;
            }
        }

        return $result;
    }

    /**
     * @param string[] $allOptionalColumns
     * @return string[]|null
     */
    private function loadCachedOptionalColumns(array $allOptionalColumns): ?array
    {
        $file = $this->getOptionalColumnsCacheFilePath();
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $allowedLookup = array_flip($allOptionalColumns);
        $columns = [];
        foreach ($decoded as $column) {
            if (!is_string($column)) {
                continue;
            }
            if (isset($allowedLookup[$column])) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param string[] $columns
     */
    private function saveCachedOptionalColumns(array $columns): void
    {
        $file = $this->getOptionalColumnsCacheFilePath();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode(array_values($columns));
        if ($payload === false) {
            return;
        }

        @file_put_contents($file, $payload, LOCK_EX);
        @chmod($file, 0666);
    }

    private function clearCachedOptionalColumns(): void
    {
        $file = $this->getOptionalColumnsCacheFilePath();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function getOptionalColumnsCacheFilePath(): string
    {
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        return rtrim($basePath, '/') . '/var/locks/' . self::OPTIONAL_COLUMNS_CACHE_FILE;
    }

    /**
     * @param string[] $optionalColumns
     */
    private function isMissingColumnException(\Throwable $exception, array $optionalColumns): bool
    {
        $message = $exception->getMessage();
        if (stripos($message, 'invalid column') !== false) {
            return true;
        }

        foreach ($optionalColumns as $column) {
            if (stripos($message, $column) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Busca o nome da transportadora pelo código.
     * Retorna string vazia se a tabela não existir no ERP.
     */
    private function getTransportadoraNome(int $codigo): string
    {
        if ($codigo <= 0) {
            return '';
        }

        try {
            $sql = "SELECT TOP 1 NOME FROM CL_TRANSPORTADORA WHERE CODIGO = :codigo";
            $result = $this->connection->fetchOne($sql, [':codigo' => $codigo]);
            return $result['NOME'] ?? '';
        } catch (\Exception $e) {
            // CL_TRANSPORTADORA pode não existir — não é erro crítico
            $this->logger->debug('[ERP] Transportadora lookup failed (table may not exist): ' . $e->getMessage());
            return '';
        }
    }

    public function syncOrderTracking(int $magentoOrderId): bool
    {
        try {
            $order = $this->orderRepository->get($magentoOrderId);
            $erpOrderId = $this->getErpOrderIdByMagentoId($magentoOrderId);

            if (!$erpOrderId) {
                $this->logger->warning('[ERP] No ERP order mapping for Magento order ' . $magentoOrderId);
                return false;
            }

            $erpStatus = $this->getErpOrderStatus($erpOrderId);

            if (!$erpStatus || empty($erpStatus['CODRASTREIO'])) {
                return false; // Sem rastreio no ERP
            }

            // Verifica se já existe shipment
            /** @var Order $order */
            if (!$order->hasShipments()) {
                $this->createShipmentWithTracking($order, $erpStatus);
            } else {
                $this->addTrackingToExistingShipment($order, $erpStatus);
            }

            $this->logger->info(sprintf(
                '[ERP] Tracking synced for order %s: %s',
                $order->getIncrementId(),
                $erpStatus['CODRASTREIO']
            ));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Tracking sync error: ' . $e->getMessage());
            return false;
        }
    }

    public function getOrderInvoiceData(int $erpOrderId): ?array
    {
        try {
            $sql = "SELECT p.NFNUMERO, p.NFSERIE, p.NFCHAVE, p.DTFATURAMENTO,
                           p.VLRTOTAL, p.VLRFRETE, p.VLRDESCONTO,
                           f.RAZAO AS EMITENTE_RAZAO, f.CGC AS EMITENTE_CNPJ
                    FROM VE_PEDIDO p
                    LEFT JOIN CD_FILIAL f ON f.CODIGO = p.FILIAL
                    WHERE p.CODIGO = :codigo AND p.NFNUMERO IS NOT NULL";

            $data = $this->connection->fetchOne($sql, [':codigo' => $erpOrderId]);

            if ($data && $data['NFNUMERO']) {
                return [
                    'numero' => $data['NFNUMERO'],
                    'serie' => $data['NFSERIE'],
                    'chave' => $data['NFCHAVE'],
                    'data_emissao' => $data['DTFATURAMENTO'],
                    'valor_total' => (float) $data['VLRTOTAL'],
                    'valor_frete' => (float) $data['VLRFRETE'],
                    'valor_desconto' => (float) $data['VLRDESCONTO'],
                    'emitente' => [
                        'razao_social' => $data['EMITENTE_RAZAO'],
                        'cnpj' => $data['EMITENTE_CNPJ'],
                    ],
                    'url_danfe' => $this->buildDanfeUrl($data['NFCHAVE']),
                ];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Get invoice data error: ' . $e->getMessage());
            return null;
        }
    }

    public function updateOrderStatus(int $magentoOrderId): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'new_status' => null,
            'previous_status' => null,
        ];

        try {
            $order = $this->orderRepository->get($magentoOrderId);
            $result['previous_status'] = $order->getStatus();

            // Pedidos já finalizados não precisam de sync
            if (in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CANCELED, Order::STATE_CLOSED])) {
                $result['message'] = 'Pedido já finalizado, status não atualizado';
                return $result;
            }

            $erpOrderId = $this->getErpOrderIdByMagentoId($magentoOrderId);
            if (!$erpOrderId) {
                $result['message'] = 'Pedido não encontrado no mapeamento ERP';
                return $result;
            }

            $erpStatus = $this->getErpOrderStatus($erpOrderId);
            if (!$erpStatus) {
                $result['message'] = 'Status não encontrado no ERP';
                return $result;
            }

            $erpStatusCode = $erpStatus['STATUS'] ?? '';

            // Passthrough: ERP recebeu o pedido mas ainda nao processou (ex: Aguardando Sectra)
            if (in_array($erpStatusCode, self::STATUS_PASSTHROUGH, true)) {
                $tpPagamento = $erpStatus['TPPAGAMENTO'] ?? '';
                $dtPedido    = $erpStatus['DTPEDIDO'] ?? null;
                $diasEspera  = 0;

                if ($dtPedido instanceof \DateTime) {
                    $diasEspera = (int) $dtPedido->diff(new \DateTime())->days;
                } elseif (is_string($dtPedido) && $dtPedido !== '') {
                    try {
                        $diasEspera = (int) (new \DateTime($dtPedido))->diff(new \DateTime())->days;
                    } catch (\Exception $ignored) {
                        $this->logger->warning('[OrderSync::updateOrderStatus] Exceção ignorada: ' . $ignored->getMessage());
                        // intentionally ignored: invalid date string — treat as 0 days
                    }
                }

                if ($tpPagamento === 'BLQ') {
                    // Pedido bloqueado financeiramente no Sectra — alertar se espera > 7 dias
                    $logMsg = sprintf(
                        '[ERP] Pedido %s bloqueado no Sectra (BLQ) há %d dias — cliente deve ser liberado pelo financeiro',
                        $order->getIncrementId(),
                        $diasEspera
                    );
                    if ($diasEspera >= 7) {
                        $this->logger->warning($logMsg);
                    } else {
                        $this->logger->info($logMsg);
                    }
                    $result['message'] = sprintf('ERP status "W" bloqueado financeiramente (BLQ) há %d dias', $diasEspera);
                } else {
                    $result['message'] = sprintf(
                        'ERP status "%s" — aguardando processamento Sectra (%d dias)',
                        $erpStatusCode,
                        $diasEspera
                    );
                }

                $result['passthrough'] = true;
                return $result;
            }

            $statusMap = self::STATUS_MAP[$erpStatusCode] ?? null;

            if (!$statusMap) {
                $result['message'] = sprintf('Status ERP "%s" nao mapeado', $erpStatusCode);
                return $result;
            }

            // Verifica se status mudou
            if ($order->getStatus() === $statusMap['status']) {
                $result['message'] = 'Status já está atualizado';
                return $result;
            }

            // Atualiza status
            $order->setState($statusMap['state']);
            $order->setStatus($statusMap['status']);

            // Adiciona comentário com informações do ERP
            $comment = $this->buildStatusComment($erpStatus);
            /** @var Order $order */
            $order->addCommentToStatusHistory($comment, $statusMap['status']);

            // Salva pedido
            $this->orderRepository->save($order);

            // Sincroniza tracking se disponível
            if (!empty($erpStatus['CODRASTREIO'])) {
                $this->syncOrderTracking($magentoOrderId);
            }

            $result['success'] = true;
            $result['new_status'] = $statusMap['status'];
            $result['message'] = sprintf(
                'Status atualizado de "%s" para "%s"',
                $result['previous_status'],
                $statusMap['status']
            );

            $this->logger->info('[ERP] Order status updated', [
                'order_id' => $order->getIncrementId(),
                'erp_status' => $erpStatusCode,
                'new_status' => $statusMap['status'],
            ]);
        } catch (\Exception $e) {
            $result['message'] = 'Erro ao atualizar status: ' . $e->getMessage();
            $this->logger->error('[ERP] Update order status error: ' . $e->getMessage());
        }

        return $result;
    }

    public function getOrderHistory(int $erpClientCode, int $limit = 50): array
    {
        try {
            return $this->fetchOrderHistory($erpClientCode, $limit);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Order history error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca histórico de pedidos com detecção automática de colunas disponíveis.
     *
     * @param int $erpClientCode Código do cliente no ERP
     * @param int $limit         Máximo de registros
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrderHistory(int $erpClientCode, int $limit): array
    {
        /** @var string[]|null Colunas opcionais confirmadas p/ history */
        static $historyColumns = null;

        // Colunas base (certamente existem)
        $baseSelect = 'p.CODIGO AS pedido_id, p.STATUS AS status';

        // Colunas opcionais com alias
        $optionalDefs = [
            'DTPEDIDO'  => 'p.DTPEDIDO AS data_pedido',
            'VLRTOTAL'  => 'p.VLRTOTAL AS valor_total',
            'NFNUMERO'  => 'p.NFNUMERO AS nf_numero',
            'CLIENTE'   => '', // usado no WHERE, não no SELECT
        ];

        // Se CLIENTE não existir, não podemos filtrar — retornar vazio
        $optionalNames = array_keys($optionalDefs);

        if ($historyColumns !== null) {
            if (!in_array('CLIENTE', $historyColumns, true)) {
                return [];
            }
            return $this->executeHistoryQuery($baseSelect, $optionalDefs, $historyColumns, $erpClientCode, $limit);
        }

        $columnsToTry = $optionalNames;

        while (true) {
            try {
                $result = $this->executeHistoryQuery($baseSelect, $optionalDefs, $columnsToTry, $erpClientCode, $limit);
                $historyColumns = $columnsToTry;
                return $result;
            } catch (\Exception $e) {
                $removed = false;
                foreach ($columnsToTry as $idx => $col) {
                    if (stripos($e->getMessage(), $col) !== false) {
                        $this->logger->info(
                            sprintf('[ERP] VE_PEDIDO history: column %s not available, removing', $col)
                        );
                        unset($columnsToTry[$idx]);
                        $columnsToTry = array_values($columnsToTry);
                        $removed = true;
                        break;
                    }
                }

                if (!$removed) {
                    throw $e;
                }

                if (empty($columnsToTry)) {
                    $historyColumns = [];
                    break;
                }
            }
        }

        // Fallback: apenas CODIGO e STATUS, sem filtro por CLIENTE
        return $this->executeHistoryQuery($baseSelect, $optionalDefs, [], $erpClientCode, $limit);
    }

    /**
     * Executa query de histórico com colunas determinadas.
     */
    private function executeHistoryQuery(
        string $baseSelect,
        array $optionalDefs,
        array $availableCols,
        int $erpClientCode,
        int $limit
    ): array {
        $selectParts = [$baseSelect];
        foreach ($availableCols as $col) {
            if (!empty($optionalDefs[$col])) {
                $selectParts[] = $optionalDefs[$col];
            }
        }

        // Subquery de itens sempre usa VE_PEDIDOITENS
        $selectParts[] = '(SELECT COUNT(*) FROM VE_PEDIDOITENS pi WHERE pi.PEDIDO = p.CODIGO) AS total_itens';

        $hasCliente = in_array('CLIENTE', $availableCols, true);
        $hasDtPedido = in_array('DTPEDIDO', $availableCols, true);

        $where = $hasCliente ? 'WHERE p.CLIENTE = :cliente' : 'WHERE 1=1';
        $orderBy = $hasDtPedido ? 'ORDER BY p.DTPEDIDO DESC' : 'ORDER BY p.CODIGO DESC';

        $sql = "SELECT " . implode(', ', $selectParts) . "
                FROM VE_PEDIDO p
                $where
                $orderBy
                OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY";

        $params = [':limit' => $limit];
        if ($hasCliente) {
            $params[':cliente'] = $erpClientCode;
        }

        return $this->connection->query($sql, $params);
    }

    // ==================== Private Methods ====================

    /**
     * Fetch customer commercial data from ERP for order enrichment
     * Returns VENDPREF, CONDPAGTO, FATORPRECO, CONTATO_NOME, TRANSPPREF, TPFATOR, PERCFATOR
     */
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

            if ($result) {
                $this->logger->debug('[ERP] Customer order data fetched', [
                    'client_code' => $erpClientCode,
                    'vendedor' => $result['VENDPREF'] ?? 0,
                    'condpagto' => $result['CONDPAGTO'] ?? 0,
                    'fatorpreco' => $result['FATORPRECO'] ?? 0,
                    'contato' => $result['CONTATO_NOME'] ?? '',
                    'tpfator' => $result['TPFATOR'] ?? '',
                    'percfator' => $result['PERCFATOR'] ?? 0,
                ]);
                return $result;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Failed to fetch customer order data: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch product unit (UNDVENDA) from ERP for a given SKU
     */
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

    /**
     * Resolve the ERP client code for an order.
     *
     * Kept in sync with OrderPullManagement::resolveErpClientCode() —
     * both must apply the same resolution order so an order is never
     * sent to the ERP under the wrong (or guest) client code just
     * because it came through a different sync entry point.
     */
    private function resolveErpClientCode(OrderInterface $order): int
    {
        // 0. Already stamped on the order (set by OrderPlaceAfter observer)
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
            return self::GUEST_CLIENT_CODE;
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
                $this->logger->info('[ERP] Resolved ERP code from customer attribute', [
                    'customer_id' => $customerId,
                    'erp_code' => $attr->getValue(),
                ]);
                return (int) $attr->getValue();
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Failed to load customer for ERP code: ' . $e->getMessage());
        }

        $this->logger->error('[ERP] Could not resolve ERP client code for order', [
            'increment_id' => $order->getIncrementId(),
            'customer_id' => $customerId,
            'taxvat' => $taxvat,
        ]);

        return self::GUEST_CLIENT_CODE;
    }

    private function getValidOrderItems(OrderInterface $order): array
    {
        $validItems = [];

        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }

            if ((float) $item->getQtyOrdered() <= 0) {
                continue;
            }

            $validItems[] = $item;
        }

        return $validItems;
    }

    private function insertOrderHeader(OrderInterface $order, int $erpClientCode, array $erpCustomerData = []): int
    {
        $filial = $this->helper->getStockFilial();
        /** @var Order $order */
        $shipping = $order->getShippingAddress();

        // Resolve customer commercial data from ERP
        $vendedor = (int) ($erpCustomerData['VENDPREF'] ?? 0);
        $condPagto = (int) ($erpCustomerData['CONDPAGTO'] ?? 0);
        $fatorPreco = (int) ($erpCustomerData['FATORPRECO'] ?? 0);
        $contato = (string) ($erpCustomerData['CONTATO_NOME'] ?? '');
        $transportadora = (int) ($erpCustomerData['TRANSPPREF'] ?? 0);
        $tipoFator = (string) ($erpCustomerData['TPFATOR'] ?? '');
        $percFator = (float) ($erpCustomerData['PERCFATOR'] ?? 0);

        // Usar INSERT simples + SCOPE_IDENTITY() para compatibilidade com todos os drivers
        // (OUTPUT INSERTED não funciona com dblib/FreeTDS)
        $orderSql = "INSERT INTO VE_PEDIDO (
                        FILIAL, DTPEDIDO, CLIENTE, VENDEDOR, STATUS,
                        CONDPAGTO, FATORPRECO, CONTATO, TRANSPORTADOR,
                        TPFATOR, PERCFATOR,
                        VLRBRUTO, VLRDESCONTO, VLRTOTAL, VLRFRETE,
                        PEDIDOWEB, PEDIDOCLI,
                        ENTENDERECO, ENTBAIRRO, ENTCIDADE, ENTCEP, ENTUF,
                        USERNAME1, USERDATE1
                     )
                     VALUES (
                        :filial, GETDATE(), :cliente, :vendedor, 'W',
                        :condpagto, :fatorpreco, :contato, :transportador,
                        :tpfator, :percfator,
                        :vlrbruto, :vlrdesconto, :vlrtotal, :vlrfrete,
                        :pedidoweb, :pedidocli,
                        :entendereco, :entbairro, :entcidade, :entcep, :entuf,
                        'MAGENTO', GETDATE()
                     )";

        $params = [
            ':filial' => $filial,
            ':cliente' => $erpClientCode,
            ':vendedor' => $vendedor,
            ':condpagto' => $condPagto,
            ':fatorpreco' => $fatorPreco,
            ':contato' => $contato,
            ':transportador' => $transportadora,
            ':tpfator' => $tipoFator,
            ':percfator' => $percFator,
            ':vlrbruto' => (float) $order->getSubtotal(),
            ':vlrdesconto' => abs((float) $order->getDiscountAmount()),
            ':vlrtotal' => (float) $order->getGrandTotal(),
            ':vlrfrete' => (float) $order->getShippingAmount(),
            ':pedidoweb' => $order->getIncrementId(),
            ':pedidocli' => $order->getIncrementId(),
            ':entendereco' => $shipping ? implode(', ', $shipping->getStreet()) : '',
            ':entbairro' => $this->extractBairro($shipping),
            ':entcidade' => $shipping ? ($shipping->getCity() ?? '') : '',
            ':entcep' => $shipping ? ($shipping->getPostcode() ?? '') : '',
            ':entuf' => $shipping ? ($shipping->getRegionCode() ?? '') : '',
        ];

        // Executa o INSERT
        $this->connection->execute($orderSql, $params);

        // Obtém o ID inserido usando SCOPE_IDENTITY() - compatível com todos os drivers
        $identitySql = "SELECT SCOPE_IDENTITY() AS new_id";
        $result = $this->connection->fetchOne($identitySql, []);

        return $result ? (int) ($result['new_id'] ?? $result['NEW_ID'] ?? 0) : 0;
    }

    private function extractBairro($shipping): string
    {
        if (!$shipping) {
            return '';
        }

        $street = $shipping->getStreet();
        return $street[2] ?? $street[1] ?? '';
    }

    private function insertOrderItems(int $erpOrderId, array $items): int
    {
        $itemsSynced = 0;

        $itemSql = "INSERT INTO VE_PEDIDOITENS (
                        PEDIDO, MATERIAL, QTDE, UNIDADE, VLRUNITARIO, VLRTOTAL,
                        VLRDESCONTO, VLRBRUTO
                    ) VALUES (
                        :pedido, :material, :qtde, :unidade, :vlrunitario, :vlrtotal,
                        :vlrdesconto, :vlrbruto
                    )";

        foreach ($items as $item) {
            $unidade = $this->getErpProductUnit($item->getSku());

            $this->connection->execute($itemSql, [
                ':pedido' => $erpOrderId,
                ':material' => $item->getSku(),
                ':qtde' => (float) $item->getQtyOrdered(),
                ':unidade' => $unidade,
                ':vlrunitario' => (float) $item->getPrice(),
                ':vlrtotal' => (float) $item->getRowTotal(),
                ':vlrdesconto' => abs((float) $item->getDiscountAmount()),
                ':vlrbruto' => (float) $item->getRowTotal() + abs((float) $item->getDiscountAmount()),
            ]);
            $itemsSynced++;
        }

        return $itemsSynced;
    }

    /**
     * Detecta pedidos CANCELADOS no Magento que ainda estão em STATUS='W' no Sectra.
     * Loga warnings para que o time possa cancelar manualmente no Sectra.
     *
     * @return array{found: int}
     */
    public function syncCanceledOrders(): array
    {
        $found = 0;

        try {
            $sql = "SELECT em.erp_code, em.magento_entity_id, so.increment_id
                    FROM grupoawamotos_erp_entity_map em
                    INNER JOIN sales_order so ON so.entity_id = em.magento_entity_id
                    WHERE em.entity_type = 'order'
                      AND so.state = 'canceled'
                      AND em.erp_code REGEXP '^[0-9]+$'
                      AND CAST(em.erp_code AS UNSIGNED) > 0
                      AND em.last_sync_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    ORDER BY em.last_sync_at DESC
                    LIMIT 50";

            $orders = $this->syncLogResource->getConnection()->fetchAll($sql);

            foreach ($orders as $orderData) {
                $erpOrderId = (int) $orderData['erp_code'];
                $erpStatus  = $this->getErpOrderStatus($erpOrderId);

                if (!$erpStatus) {
                    continue;
                }

                $erpStatusCode = $erpStatus['STATUS'] ?? '';

                if (in_array($erpStatusCode, self::STATUS_PASSTHROUGH, true)) {
                    $found++;
                    $this->logger->warning(sprintf(
                        '[ERP] Pedido %s CANCELADO no Magento mas STATUS="%s" no Sectra (ERP #%d) — cancelar manualmente no Sectra',
                        $orderData['increment_id'],
                        $erpStatusCode,
                        $erpOrderId
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('[ERP] syncCanceledOrders error: ' . $e->getMessage());
        }

        return ['found' => $found];
    }

    private function getPendingOrdersForSync(): array
    {
        try {
            // Busca mapeamentos de pedidos que não estão completos/cancelados no Magento
            // Filtra apenas erp_codes numéricos (evita entradas TEST-... ou outras inválidas)
            $sql = "SELECT em.erp_code, em.magento_entity_id
                    FROM grupoawamotos_erp_entity_map em
                    INNER JOIN sales_order so ON so.entity_id = em.magento_entity_id
                    WHERE em.entity_type = 'order'
                      AND so.state NOT IN ('complete', 'canceled', 'closed')
                      AND em.erp_code REGEXP '^[0-9]+$'
                      AND CAST(em.erp_code AS UNSIGNED) > 0
                    ORDER BY em.last_sync_at ASC
                    LIMIT 100";

            return $this->syncLogResource->getConnection()->fetchAll($sql);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Get pending orders error: ' . $e->getMessage());
            return [];
        }
    }

    private function getErpOrderIdByMagentoId(int $magentoOrderId): ?int
    {
        $erpId = $this->syncLogResource->getErpCodeByMagentoId('order', $magentoOrderId);
        return $erpId ? (int) $erpId : null;
    }

    private function createShipmentWithTracking(Order $order, array $erpStatus): void
    {
        if (!$order->canShip()) {
            return;
        }

        $shipment = $this->orderConverter->toShipment($order);

        foreach ($order->getAllItems() as $item) {
            if (!$item->getQtyToShip() || $item->getIsVirtual()) {
                continue;
            }

            $qtyShipped = $item->getQtyToShip();
            $shipmentItem = $this->orderConverter->itemToShipmentItem($item)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register();

        // Adiciona tracking
        $this->addTrackToShipment($shipment, $erpStatus);

        $this->transaction->addObject($shipment)->addObject($order)->save();
    }

    private function addTrackingToExistingShipment(Order $order, array $erpStatus): void
    {
        $trackNumber = $erpStatus['CODRASTREIO'] ?? null;
        if (empty($trackNumber)) {
            return;
        }

        foreach ($order->getShipmentsCollection() as $shipment) {
            // Verifica se já tem esse tracking
            $existingTracks = $shipment->getAllTracks();
            foreach ($existingTracks as $track) {
                if ($track->getTrackNumber() === $trackNumber) {
                    return; // Tracking já existe
                }
            }

            $this->addTrackToShipment($shipment, $erpStatus);
            $this->shipmentRepository->save($shipment);
            break; // Adiciona apenas no primeiro shipment
        }
    }

    private function addTrackToShipment($shipment, array $erpStatus): void
    {
        $trackNumber = $erpStatus['CODRASTREIO'] ?? null;
        if (empty($trackNumber)) {
            return;
        }

        $carrierCode = $this->resolveCarrierCode($erpStatus['TRANSPORTADORA_NOME'] ?? '');

        $track = $shipment->getTracksCollection()->getNewEmptyItem();
        $track->setCarrierCode($carrierCode);
        $track->setTitle($erpStatus['TRANSPORTADORA_NOME'] ?? 'Transportadora');
        $track->setTrackNumber($trackNumber);

        $shipment->addTrack($track);
    }

    private function resolveCarrierCode(string $carrierName): string
    {
        $carrierName = strtolower($carrierName);

        if (str_contains($carrierName, 'correios')) {
            return 'correios';
        }
        if (str_contains($carrierName, 'jadlog')) {
            return 'jadlog';
        }
        if (str_contains($carrierName, 'total express') || str_contains($carrierName, 'totalexpress')) {
            return 'totalexpress';
        }
        if (str_contains($carrierName, 'sedex') || str_contains($carrierName, 'pac')) {
            return 'correios';
        }

        return 'custom';
    }

    private function buildStatusComment(array $erpStatus): string
    {
        $parts = ['[ERP] Status atualizado automaticamente.'];

        if (!empty($erpStatus['NFNUMERO'])) {
            $parts[] = sprintf('NF-e: %s-%s', $erpStatus['NFNUMERO'], $erpStatus['NFSERIE'] ?? '1');
        }

        if (!empty($erpStatus['DTFATURAMENTO'])) {
            $parts[] = sprintf('Faturado em: %s', $erpStatus['DTFATURAMENTO']);
        }

        if (!empty($erpStatus['CODRASTREIO'] ?? null)) {
            $parts[] = sprintf('Rastreio: %s', $erpStatus['CODRASTREIO']);
        }

        if (!empty($erpStatus['TRANSPORTADORA_NOME'])) {
            $parts[] = sprintf('Transportadora: %s', $erpStatus['TRANSPORTADORA_NOME']);
        }

        return implode(' | ', $parts);
    }

    private function buildDanfeUrl(?string $chaveNfe): ?string
    {
        if (!$chaveNfe || strlen($chaveNfe) !== 44) {
            return null;
        }

        return sprintf('https://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx?tipoConsulta=resumo&tipoConteudo=7PhJ+gAVw2g=&nfe=%s', $chaveNfe);
    }
}
