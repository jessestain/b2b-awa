<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Service;

use GrupoAwamotos\ERPIntegration\Model\Connection as ErpConnection;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Detecta produtos Private Label via lista de preços ERP e os registra em
 * grupoawamotos_b2b_exclusive_product.
 *
 * Regra de negócio:
 *   - Lista #24 (006 NACIONAL) = pública — produtos visíveis a todos.
 *   - Qualquer produto que exista em uma lista de cliente (ex: #25 MAM/SMA)
 *     mas NÃO exista na lista pública (#24) é exclusivo daquele cliente.
 *
 * O mapeamento lista→customer_id vive em grupoawamotos_b2b_erp_alias
 * com alias_name = 'ERP_LIST_<código>' (ex: 'ERP_LIST_25').
 */
class PrivateLabelDetector
{
    private const ALIAS_TABLE     = 'grupoawamotos_b2b_erp_alias';
    private const EXCLUSIVE_TABLE = 'grupoawamotos_b2b_exclusive_product';
    private const PUBLIC_LIST     = 24;
    private const ERP_FILIAL      = 2;
    private const ERP_LIST_PREFIX = 'ERP_LIST_';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ErpConnection $erpConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verifica via ERP se o SKU é exclusivo de algum cliente e o protege.
     * Chamado pelo observer ao salvar um produto.
     */
    public function detectAndRegister(int $productId, string $sku): bool
    {
        try {
            $listMap = $this->loadErpListMap();
            if (empty($listMap)) {
                return false;
            }

            foreach ($listMap as $listCode => $customer) {
                $inCustomerList = $this->isSkuInList($sku, $listCode);
                $inPublicList   = $this->isSkuInList($sku, self::PUBLIC_LIST);

                if ($inCustomerList && !$inPublicList) {
                    $this->upsertExclusive($productId, $customer['customer_id'], $customer['label']);
                    $this->logger->info(sprintf(
                        '[PrivateLabel] Produto %d (SKU: %s) é exclusivo da lista ERP #%d → customer_id %d',
                        $productId,
                        $sku,
                        $listCode,
                        $customer['customer_id']
                    ));
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PrivateLabel] detectAndRegister error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Varre o ERP e sincroniza todos os produtos exclusivos para todos os
     * clientes mapeados. Retorna o total de registros inseridos/atualizados.
     */
    public function scanAll(): int
    {
        $listMap = $this->loadErpListMap();
        if (empty($listMap)) {
            $this->logger->warning('[PrivateLabel] Nenhuma lista ERP configurada em ' . self::ALIAS_TABLE);
            return 0;
        }

        $connection     = $this->resourceConnection->getConnection();
        $productTable   = $this->resourceConnection->getTableName('catalog_product_entity');
        $exclusiveTable = $this->resourceConnection->getTableName(self::EXCLUSIVE_TABLE);

        // Pré-carrega todos os SKUs do Magento para lookup eficiente
        $magentoSkus = $connection->fetchPairs(
            $connection->select()->from($productTable, ['sku', 'entity_id'])
        );

        $count = 0;

        foreach ($listMap as $listCode => $customer) {
            $exclusiveSkus = $this->fetchExclusiveSkusFromErp($listCode);

            if (empty($exclusiveSkus)) {
                $this->logger->info(sprintf('[PrivateLabel] Lista ERP #%d: nenhum produto exclusivo', $listCode));
                continue;
            }

            $this->logger->info(sprintf(
                '[PrivateLabel] Lista ERP #%d: %d SKUs exclusivos encontrados',
                $listCode,
                count($exclusiveSkus)
            ));

            foreach ($exclusiveSkus as $sku) {
                $productId = $magentoSkus[$sku] ?? null;
                if ($productId === null) {
                    continue; // SKU não importado no Magento ainda
                }

                // Verifica se já está registrado corretamente
                $existing = $connection->fetchRow(
                    $connection->select()
                        ->from($exclusiveTable, ['customer_id'])
                        ->where('product_id = ?', (int) $productId)
                );

                if ($existing && (int) $existing['customer_id'] === $customer['customer_id']) {
                    continue; // já correto
                }

                $this->upsertExclusive((int) $productId, $customer['customer_id'], $customer['label']);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Busca no ERP os SKUs que estão na lista do cliente mas NÃO na lista pública.
     *
     * @return string[]
     */
    private function fetchExclusiveSkusFromErp(int $listCode): array
    {
        try {
            $rows = $this->erpConnection->query(
                "SELECT DISTINCT m.MATERIAL
                 FROM MT_MATERIALLISTA m
                 INNER JOIN MT_MATERIAL mat ON mat.CODIGO = m.MATERIAL AND mat.CCKATIVO = 'S'
                 WHERE m.FATORPRECO = ?
                   AND m.FILIAL = ?
                   AND m.VLRVDSUG > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM MT_MATERIALLISTA m2
                       WHERE m2.MATERIAL = m.MATERIAL
                         AND m2.FATORPRECO = ?
                         AND m2.FILIAL = ?
                   )
                 ORDER BY m.MATERIAL",
                [$listCode, self::ERP_FILIAL, self::PUBLIC_LIST, self::ERP_FILIAL]
            );

            return array_column($rows ?? [], 'MATERIAL');
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[PrivateLabel] ERP query error (list #%d): %s', $listCode, $e->getMessage()));
            return [];
        }
    }

    private function isSkuInList(string $sku, int $listCode): bool
    {
        try {
            $result = $this->erpConnection->fetchOne(
                "SELECT TOP 1 ml.MATERIAL
                 FROM MT_MATERIALLISTA ml
                 INNER JOIN MT_MATERIAL mat ON mat.CODIGO = ml.MATERIAL AND mat.CCKATIVO = 'S'
                 WHERE ml.MATERIAL = ? AND ml.FATORPRECO = ? AND ml.FILIAL = ? AND ml.VLRVDSUG > 0",
                [$sku, $listCode, self::ERP_FILIAL]
            );
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function upsertExclusive(int $productId, int $customerId, ?string $label): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::EXCLUSIVE_TABLE);

        $connection->insertOnDuplicate(
            $table,
            ['product_id' => $productId, 'customer_id' => $customerId, 'label' => $label],
            ['customer_id', 'label']
        );
    }

    /**
     * Carrega o mapa lista→cliente a partir de grupoawamotos_b2b_erp_alias.
     * Entradas com alias_name = 'ERP_LIST_25' → listCode=25.
     *
     * @return array<int, array{customer_id:int, label:string|null}>
     */
    private function loadErpListMap(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName(self::ALIAS_TABLE);

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['alias_name', 'customer_id', 'label'])
                ->where('alias_name LIKE ?', self::ERP_LIST_PREFIX . '%')
        );

        $map = [];
        foreach ($rows as $row) {
            $code = (int) str_replace(self::ERP_LIST_PREFIX, '', $row['alias_name']);
            if ($code > 0) {
                $map[$code] = [
                    'customer_id' => (int) $row['customer_id'],
                    'label'       => $row['label'],
                ];
            }
        }

        return $map;
    }
}
