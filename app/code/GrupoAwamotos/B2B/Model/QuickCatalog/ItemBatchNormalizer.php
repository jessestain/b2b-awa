<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

/**
 * Normalizador puro de lotes de itens do Catálogo de Compra Rápida.
 *
 * Regras (CONTRACT.md §4):
 * - SKU obrigatório (trim, case-insensitive para dedupe);
 * - qty inteiro entre 1 e MAX_QTY_PER_SKU;
 * - máximo de MAX_ITEMS SKUs distintos por lote;
 * - SKUs repetidos no mesmo lote somam qty (limitado ao teto).
 *
 * Sem dependências de framework para permitir teste puro (php CLI).
 */
class ItemBatchNormalizer
{
    public const MAX_ITEMS = 120;
    public const MAX_QTY_PER_SKU = 9999;

    /**
     * Normaliza e consolida o lote de itens informado.
     *
     * @param array<int,mixed> $rawItems
     * @return array<int,array{sku:string,qty:int}>
     */
    public function normalize(array $rawItems): array
    {
        $deduped = [];

        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $rawQty = $item['qty'] ?? 1;
            $qty = (int) $rawQty;
            if ($qty < 1) {
                $qty = 1;
            }
            $qty = min(self::MAX_QTY_PER_SKU, $qty);

            $key = strtolower($sku);
            if (!isset($deduped[$key])) {
                if (count($deduped) >= self::MAX_ITEMS) {
                    break;
                }

                $deduped[$key] = ['sku' => $sku, 'qty' => 0];
            }

            $deduped[$key]['qty'] += $qty;
            if ($deduped[$key]['qty'] > self::MAX_QTY_PER_SKU) {
                $deduped[$key]['qty'] = self::MAX_QTY_PER_SKU;
            }
        }

        return array_values($deduped);
    }

    /**
     * Valida o request_id de idempotência do lote.
     *
     * @param string $requestId
     * @return bool
     */
    public function isValidRequestId(string $requestId): bool
    {
        $length = strlen($requestId);
        if ($length < 8 || $length > 64) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) === 1;
    }
}
