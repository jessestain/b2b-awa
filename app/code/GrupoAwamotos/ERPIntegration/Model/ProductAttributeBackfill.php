<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\ColorNormalizationInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class ProductAttributeBackfill
{
    private ConnectionInterface $connection;
    private ProductRepositoryInterface $productRepository;
    private LoggerInterface $logger;
    private ColorNormalizationInterface $colorNormalization;
    private ?\GrupoAwamotos\ERPIntegration\Helper\Data $helper = null;

    public function __construct(
        ConnectionInterface $connection,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        ColorNormalizationInterface $colorNormalization
    ) {
        $this->connection = $connection;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->colorNormalization = $colorNormalization;
    }

    private function getHelper(): \GrupoAwamotos\ERPIntegration\Helper\Data
    {
        if (!isset($this->helper)) {
            $this->helper = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\GrupoAwamotos\ERPIntegration\Helper\Data::class);
        }
        return $this->helper;
    }

    /**
     * @return array{total:int,updated:int,unchanged:int,not_found:int,skipped:int,errors:int}
     */
    public function backfill(?string $sku = null, int $limit = 0): array
    {
        $result = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'not_found' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $rows = $this->getErpRows($sku, $limit);
        $result['total'] = count($rows);

        foreach ($rows as $row) {
            $targetSku = trim((string) ($row['CODIGO'] ?? ''));
            if ($targetSku === '') {
                $result['skipped']++;
                continue;
            }

            $attributes = $this->extractAttributes($row);
            if (empty($attributes)) {
                $result['skipped']++;
                continue;
            }

            // B2B Densidade: mesma chave do PriceSync — "1119 RS" -> "1119"
            // (SKU da loja = base numérica do MATERIAL do ERP).
            $catalogSku = $this->normalizeErpSku($targetSku);

            try {
                $product = $this->productRepository->get($catalogSku, false, 0);
            } catch (NoSuchEntityException $exception) {
                $result['not_found']++;
                continue;
            }

            $hasChanges = false;
            foreach ($attributes as $attributeCode => $attributeValue) {
                $currentValue = $product->getCustomAttribute($attributeCode)?->getValue();
                if ((string) $currentValue === $attributeValue) {
                    continue;
                }

                $product->setCustomAttribute($attributeCode, $attributeValue);
                $hasChanges = true;
            }

            if (!$hasChanges) {
                $result['unchanged']++;
                continue;
            }

            try {
                $this->productRepository->save($product);
                $result['updated']++;
            } catch (\Exception $exception) {
                $result['errors']++;
                $this->logger->error('[ERP] Product attribute backfill failed', [
                    'sku' => $targetSku,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->logger->info('[ERP] Product attribute backfill completed', $result);

        return $result;
    }

    private function getErpRows(?string $sku, int $limit): array
    {
        $params = [];
        $topClause = $limit > 0 ? 'TOP ' . (int) $limit . ' ' : '';

        $filial = (int) $this->getHelper()->getStockFilial();
        $sql = "SELECT {$topClause}
                    l.MATERIAL AS CODIGO,
                    CAST(m.CODINTERNO AS VARCHAR(255)) AS CODINTERNO,
                    CAST(m.NCM AS VARCHAR(255)) AS NCM,
                    CAST(m.COR AS VARCHAR(255)) AS COR
                FROM MT_MATERIALLISTA l
                INNER JOIN MT_MATERIAL m ON m.CODIGO = l.MATERIAL
                WHERE l.MATERIAL IS NOT NULL
                  AND LTRIM(RTRIM(CAST(l.MATERIAL AS VARCHAR(255)))) != ''
                  AND l.VLRVDSUG > 0
                  AND l.FILIAL = :filial";

        $params[':filial'] = $filial;

        if ($sku !== null && $sku !== '') {
            $sql .= ' AND l.MATERIAL = :sku';
            $params[':sku'] = $sku;
        }

        $sql .= ' GROUP BY l.MATERIAL, m.CODINTERNO, m.NCM, m.COR ORDER BY l.MATERIAL';

        return $this->connection->query($sql, $params);
    }

    /**
     * @return array<string, string>
     */
    private function extractAttributes(array $row): array
    {
        $attributes = [];

        $internalCode = $this->normalizeErpValue($row['CODINTERNO'] ?? null);
        if ($internalCode !== null) {
            $attributes['erp_internal_code'] = $internalCode;
        }

        $ncm = $this->normalizeErpValue($row['NCM'] ?? null);
        if ($ncm !== null) {
            $attributes['erp_ncm'] = $ncm;
        }

        $corRaw = $this->normalizeErpValue($row['COR'] ?? null);
        if ($corRaw !== null) {
            $colorOptionId = $this->colorNormalization->resolveOptionId($corRaw);
            if ($colorOptionId !== null) {
                $attributes['color'] = (string) $colorOptionId;
            }
        }

        return $attributes;
    }

    private function normalizeErpValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || $normalized === '0') {
            return null;
        }

        return $normalized;
    }


    /**
     * B2B Densidade — normaliza o MATERIAL do ERP para o SKU da loja
     * (mesma regra do PriceSync::getBaseSku).
     */
    private function normalizeErpSku(string $sku): string
    {
        $sku = trim($sku);
        if (str_contains($sku, ' ')) {
            return trim(explode(' ', $sku)[0]);
        }
        if (preg_match('/^(\d{3,})\.\d+$/', $sku, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{3,})[A-Z]{2,3}$/i', $sku, $m)) {
            return $m[1];
        }
        return $sku;
    }

}
