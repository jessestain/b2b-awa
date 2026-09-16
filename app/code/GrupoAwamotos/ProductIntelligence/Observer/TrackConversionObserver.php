<?php

declare(strict_types=1);

namespace GrupoAwamotos\ProductIntelligence\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class TrackConversionObserver implements ObserverInterface
{
    private ResourceConnection $resource;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        ResourceConnection $resource,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $observer->getEvent()->getOrder();
            if (!$order) {
                return;
            }

            $customerId = $order->getCustomerId();
            if (!$customerId) {
                return;
            }

            // Resolve ERP code for this customer
            $erpCode = $this->resolveErpCode((int)$customerId);
            if (!$erpCode) {
                return;
            }

            $connection = $this->resource->getConnection();
            $recomTable = $this->resource->getTableName('rexis_dataset_recomendacao');
            $metricasTable = $this->resource->getTableName('rexis_metricas_conversao');

            $convertedCount = 0;
            $convertedValue = 0.0;

            foreach ($order->getAllVisibleItems() as $item) {
                $sku = $item->getSku();
                $qty = (int)$item->getQtyOrdered();
                $rowTotal = (float)$item->getRowTotal();

                // Check if this product was recommended for this customer (by ERP code)
                $select = $connection->select()
                    ->from($recomTable, ['id', 'identificador_produto'])
                    ->where('identificador_cliente = ?', $erpCode)
                    ->where('identificador_produto = ?', $sku)
                    ->order('pred DESC')
                    ->limit(1);

                $recommendation = $connection->fetchRow($select);

                if ($recommendation) {
                    $safeRowTotal = (float)$rowTotal;
                    $safeQty = (int)$qty;
                    $connection->update($recomTable, [
                        'valor_convertida' => new \Zend_Db_Expr("COALESCE(valor_convertida, 0) + {$safeRowTotal}"),
                        'quantidade_convertida' => new \Zend_Db_Expr("COALESCE(quantidade_convertida, 0) + {$safeQty}"),
                    ], ['id = ?' => $recommendation['id']]);

                    $convertedCount++;
                    $convertedValue += $rowTotal;
                }
            }

            if ($convertedCount > 0) {
                $this->updateMonthlyMetrics($connection, $recomTable, $metricasTable, $erpCode, $convertedCount, $convertedValue);

                $this->logger->info(sprintf(
                    '[ProductIntelligence] Conversion tracked: customer=%s (erp=%s), products=%d, value=%.2f',
                    $customerId,
                    $erpCode,
                    $convertedCount,
                    $convertedValue
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence] Conversion tracking error: ' . $e->getMessage());
        }
    }

    /**
     * Resolve ERP code from Magento customer ID
     */
    private function resolveErpCode(int $customerId): ?string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $erpAttr = $customer->getCustomAttribute('erp_code');
            if ($erpAttr && $erpAttr->getValue()) {
                return (string)$erpAttr->getValue();
            }
        } catch (\Exception $e) {
            $this->logger->warning('[TrackConversionObserver::resolveErpCode] Exceção ignorada: ' . $e->getMessage());
            // Customer not found
        }

        // Fallback: entity_map
        try {
            $connection = $this->resource->getConnection();
            $result = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('grupoawamotos_erp_entity_map'), 'erp_code')
                    ->where('entity_type = ?', 'customer')
                    ->where('magento_entity_id = ?', $customerId)
            );
            return $result ?: (string)$customerId;
        } catch (\Exception $e) {
            return (string)$customerId;
        }
    }

    /**
     * Update monthly conversion metrics with percentage calculation
     */
    private function updateMonthlyMetrics(
        $connection,
        string $recomTable,
        string $metricasTable,
        string $erpCode,
        int $convertedCount,
        float $convertedValue
    ): void {
        $mesCode = date('m-Y');

        $existing = $connection->fetchRow(
            $connection->select()->from($metricasTable)->where('mes_rexis_code = ?', $mesCode)
        );

        if ($existing) {
            $newClientCount = (int)$existing['n_cliente_comprou_mes_atual'] + 1;
            $newProductCount = (int)$existing['n_produto_comprou_mes_atual'] + $convertedCount;
            $newConvertedValue = (float)$existing['valor_convertido_atual'] + $convertedValue;
            $totalClients = max((int)$existing['n_clientes_rec_mes_atual'], 1);
            $totalProducts = max((int)$existing['n_produto_rec_mes_atual'], 1);
            $expectedValue = max((float)$existing['valor_esperado_atual'], 1);

            $connection->update($metricasTable, [
                'n_cliente_comprou_mes_atual' => $newClientCount,
                'n_produto_comprou_mes_atual' => $newProductCount,
                'valor_convertido_atual' => $newConvertedValue,
                'perc_conversao_cliente' => round(($newClientCount / $totalClients) * 100, 2),
                'perc_conversao_produto' => round(($newProductCount / $totalProducts) * 100, 2),
            ], ['mes_rexis_code = ?' => $mesCode]);
        } else {
            // Create new monthly record
            $recClientCount = (int)$connection->fetchOne(
                $connection->select()
                    ->from($recomTable, [new \Zend_Db_Expr('COUNT(DISTINCT identificador_cliente)')])
            );
            $recProductCount = (int)$connection->fetchOne(
                $connection->select()
                    ->from($recomTable, [new \Zend_Db_Expr('COUNT(*)')])
            );
            $expectedValue = (float)$connection->fetchOne(
                $connection->select()
                    ->from($recomTable, [new \Zend_Db_Expr('COALESCE(SUM(previsao_gasto_round_up), 0)')])
            );

            $percCliente = $recClientCount > 0 ? round((1 / $recClientCount) * 100, 2) : 0;
            $percProduto = $recProductCount > 0 ? round(($convertedCount / $recProductCount) * 100, 2) : 0;

            $connection->insert($metricasTable, [
                'mes_rexis_code' => $mesCode,
                'n_clientes_rec_mes_atual' => $recClientCount,
                'n_cliente_comprou_mes_atual' => 1,
                'n_produto_rec_mes_atual' => $recProductCount,
                'n_produto_comprou_mes_atual' => $convertedCount,
                'valor_esperado_atual' => $expectedValue,
                'valor_convertido_atual' => $convertedValue,
                'perc_conversao_cliente' => $percCliente,
                'perc_conversao_produto' => $percProduto,
            ]);
        }
    }
}
