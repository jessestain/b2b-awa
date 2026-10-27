<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Copilot;

use GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Ponte do copiloto Admin com o ERP Sectra, sempre limitada à carteira.
 *
 * - A carteira vem de PortfolioScope (mesma regra do cockpit comercial).
 * - Cliente Magento -> código ERP via grupoawamotos_erp_entity_map (um cliente
 *   pode ter vários códigos — filiais; os agregados consideram todos).
 * - Somente SELECT no ERP; códigos entram na SQL como inteiros já validados.
 * - Consultas de 1–3 s no ERP => cache curto por escopo.
 */
class ErpPortfolioReader
{
    private const CACHE_PREFIX = 'awa_copilot_erp_';
    private const CACHE_TTL    = 600;
    private const ALL          = 'all';

    /**
     * Pedido que conta como venda: E (efetivado) e V (em andamento), com data
     * até hoje. Fica de fora:
     * - S: lote de 280 pedidos da filial 1 lançado em 01–04/10/2026, exatamente
     *   1 por cliente (pré-pedido/pedido sugerido — natureza não confirmada);
     *   contá-lo inflava as vendas em R$ 3,3 mi e punha 280 clientes "em dia".
     * - C/X: cancelados.
     * - datas futuras: há digitação errada no ERP (ex.: ano 5025).
     */
    private const VALID = "p.STATUS IN ('E', 'V') AND p.DTPEDIDO < DATEADD(day, 1, CAST(GETDATE() AS date))";

    public function __construct(
        private readonly ConnectionInterface $erp,
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Códigos ERP visíveis para quem pergunta; null = sem restrição.
     *
     * @return int[]|null
     */
    public function scopeCodes(PortfolioScope $scope): ?array
    {
        if ($scope->canBypassPortfolioScope()) {
            return null;
        }
        $ids = $scope->getVisibleCustomerIds();
        if ($ids === []) {
            return [];
        }
        $db = $this->resource->getConnection();
        $codes = $db->fetchCol(
            $db->select()
                ->distinct()
                ->from($this->resource->getTableName('grupoawamotos_erp_entity_map'), 'erp_code')
                ->where('entity_type = ?', 'customer')
                ->where('magento_entity_id IN (?)', array_map('intval', $ids))
        );

        return $this->ints($codes);
    }

    /**
     * Acha UM cliente da carteira por id Magento, CNPJ ou nome/razão social.
     *
     * @return array{customer_id?: int, name?: string, erp_codes?: int[], candidates?: array<int, array{customer_id: int, name: string}>, error?: string}
     */
    public function resolveCustomer(string $ref, PortfolioScope $scope): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return ['error' => 'Informe o cliente (nome, razão social, CNPJ ou código).'];
        }
        $db = $this->resource->getConnection();
        $digits = preg_replace('/\D/', '', $ref) ?? '';
        $restricted = !$scope->canBypassPortfolioScope();
        $visible = $restricted ? array_map('intval', $scope->getVisibleCustomerIds()) : [];
        if ($restricted && $visible === []) {
            return ['error' => 'Sua carteira não tem clientes vinculados.'];
        }

        $cnpjAttr = $this->attributeId('b2b_cnpj');
        $razaoAttr = $this->attributeId('b2b_razao_social');
        $select = $db->select()
            ->from(['c' => $this->resource->getTableName('customer_entity')], ['entity_id'])
            ->columns(['name' => new \Zend_Db_Expr("TRIM(CONCAT_WS(' ', c.firstname, c.lastname))")]);
        if ($razaoAttr) {
            $select->joinLeft(
                ['rz' => $this->resource->getTableName('customer_entity_varchar')],
                'rz.entity_id = c.entity_id AND rz.attribute_id = ' . $razaoAttr,
                ['razao' => 'rz.value']
            );
        }

        if (ctype_digit($ref) && strlen($ref) <= 9) {
            $select->where('c.entity_id = ?', (int) $ref);
        } elseif (strlen($digits) === 14 && $cnpjAttr) {
            $select->join(
                ['cn' => $this->resource->getTableName('customer_entity_varchar')],
                'cn.entity_id = c.entity_id AND cn.attribute_id = ' . $cnpjAttr,
                []
            )->where("REPLACE(REPLACE(REPLACE(cn.value, '.', ''), '/', ''), '-', '') = ?", $digits);
        } else {
            $like = '%' . addcslashes(mb_substr($ref, 0, 80), '%_\\') . '%';
            $cond = $db->quoteInto("CONCAT_WS(' ', c.firstname, c.lastname) LIKE ?", $like);
            if ($razaoAttr) {
                $cond .= ' OR ' . $db->quoteInto('rz.value LIKE ?', $like);
            }
            $select->where($cond);
        }
        if ($restricted) {
            $select->where('c.entity_id IN (?)', $visible);
        }
        $rows = $db->fetchAll($select->limit(6));
        if ($rows === [] && !ctype_digit($ref)) {
            $rows = $this->searchErpByName($ref, $restricted ? $visible : null);
        }

        if ($rows === []) {
            // Mesma resposta para "não existe" e "fora da carteira" (anti-enumeração).
            return ['error' => 'Cliente não encontrado na sua carteira.'];
        }
        if (count($rows) > 1) {
            return ['candidates' => array_map(fn (array $r): array => [
                'customer_id' => (int) $r['entity_id'],
                'name'        => $this->displayName($r),
            ], $rows)];
        }

        $id = (int) $rows[0]['entity_id'];
        return [
            'customer_id' => $id,
            'name'        => $this->displayName($rows[0]),
            'erp_codes'   => $this->codesForCustomer($id),
        ];
    }

    /**
     * Fallback: nome fantasia/razão social no cadastro do ERP (o vendedor fala
     * "Auto Peças do Zé", não o nome do comprador no Magento).
     *
     * @param int[]|null $visible
     * @return array<int, array{entity_id: int, name: string}>
     */
    private function searchErpByName(string $ref, ?array $visible): array
    {
        $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], mb_substr($ref, 0, 80)) . '%';
        $erpRows = $this->erp->query(
            "SELECT TOP 20 CODIGO, RAZAO, FANTASIA FROM FN_FORNECEDORES
              WHERE CKCLIENTE = 'S' AND (FANTASIA LIKE ? OR RAZAO LIKE ?)",
            [$like, $like]
        );
        $names = [];
        foreach ($erpRows as $r) {
            $fantasia = trim((string) ($r['FANTASIA'] ?? ''));
            $names[(int) $r['CODIGO']] = $this->cleanName($fantasia !== '' ? $fantasia : (string) ($r['RAZAO'] ?? ''));
        }
        $out = [];
        foreach ($this->magentoIdsForCodes(array_keys($names)) as $code => $id) {
            if ($visible !== null && !in_array($id, $visible, true)) {
                continue;
            }
            $out[$id] = ['entity_id' => $id, 'name' => '', 'razao' => $names[$code] ?? ''];
        }

        return array_slice(array_values($out), 0, 6);
    }

    /** @return int[] */
    public function codesForCustomer(int $customerId): array
    {
        $db = $this->resource->getConnection();
        return $this->ints($db->fetchCol(
            $db->select()
                ->from($this->resource->getTableName('grupoawamotos_erp_entity_map'), 'erp_code')
                ->where('entity_type = ?', 'customer')
                ->where('magento_entity_id = ?', $customerId)
                ->order('last_sync_at DESC')
        ));
    }

    /**
     * Uma linha por código ERP com pedidos/datas/valor (24 meses).
     *
     * @param int[]|null $codes
     * @return array<int, array{code: int, orders: int, first: string|null, last: string|null, value_12m: float}>
     */
    public function orderStats(?array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        return $this->cached('stats_' . $this->key($codes), function () use ($codes): array {
            $rows = $this->erp->query(
                "SELECT p.CLIENTE AS code, COUNT(DISTINCT p.CODIGO) AS orders,
                        MIN(p.DTPEDIDO) AS first_date, MAX(p.DTPEDIDO) AS last_date,
                        SUM(CASE WHEN p.DTPEDIDO >= DATEADD(month, -12, GETDATE()) THEN p.VLRTOTAL ELSE 0 END) AS value_12m
                   FROM VE_PEDIDO p
                  WHERE " . self::VALID . "
                    AND p.DTPEDIDO >= DATEADD(month, -24, GETDATE())" . $this->inClause('p.CLIENTE', $codes) . "
                  GROUP BY p.CLIENTE"
            );
            return array_map(static fn (array $r): array => [
                'code'      => (int) $r['code'],
                'orders'    => (int) $r['orders'],
                'first'     => $r['first_date'] ? substr((string) $r['first_date'], 0, 10) : null,
                'last'      => $r['last_date'] ? substr((string) $r['last_date'], 0, 10) : null,
                'value_12m' => round((float) $r['value_12m'], 2),
            ], $rows);
        });
    }

    /**
     * Nome/cidade dos códigos ERP pedidos (só os que vão aparecer na resposta).
     *
     * @param int[] $codes
     * @return array<int, array{name: string, city: string}>
     */
    public function erpNames(array $codes): array
    {
        $codes = $this->ints($codes);
        if ($codes === []) {
            return [];
        }
        $rows = $this->erp->query(
            'SELECT CODIGO, RAZAO, FANTASIA, CIDADE, UF FROM FN_FORNECEDORES WHERE 1=1'
            . $this->inClause('CODIGO', $codes)
        );
        $out = [];
        foreach ($rows as $r) {
            $fantasia = trim((string) ($r['FANTASIA'] ?? ''));
            $out[(int) $r['CODIGO']] = [
                'name' => $this->cleanName($fantasia !== '' ? $fantasia : (string) ($r['RAZAO'] ?? '')),
                'city' => trim(trim((string) ($r['CIDADE'] ?? '')) . '/' . trim((string) ($r['UF'] ?? '')), '/'),
            ];
        }
        return $out;
    }

    /**
     * Código ERP -> customer_id Magento (para o vendedor poder pedir o detalhe).
     *
     * @param int[] $codes
     * @return array<int, int>
     */
    public function magentoIdsForCodes(array $codes): array
    {
        $codes = $this->ints($codes);
        if ($codes === []) {
            return [];
        }
        $db = $this->resource->getConnection();
        $pairs = $db->fetchPairs(
            $db->select()
                ->from($this->resource->getTableName('grupoawamotos_erp_entity_map'), ['erp_code', 'magento_entity_id'])
                ->where('entity_type = ?', 'customer')
                ->where('erp_code IN (?)', array_map('strval', $codes))
        );
        $out = [];
        foreach ($pairs as $code => $id) {
            $out[(int) $code] = (int) $id;
        }
        return $out;
    }

    /**
     * Vendas do período (pedidos efetivos do ERP) + período anterior igual.
     *
     * @param int[]|null $codes
     * @return array<string, mixed>
     */
    public function salesSummary(?array $codes, int $days): array
    {
        $days = max(1, min(365, $days));
        if ($codes === []) {
            return ['orders' => 0, 'customers' => 0, 'revenue' => 0.0, 'previous' => ['orders' => 0, 'customers' => 0, 'revenue' => 0.0], 'status_breakdown' => [], 'top_customers' => [], 'top_products' => []];
        }
        return $this->cached('sales_' . $days . '_' . $this->key($codes), function () use ($codes, $days): array {
            $in = $this->inClause('p.CLIENTE', $codes);
            $period = function (int $from, int $to) use ($in): array {
                $r = $this->erp->fetchOne(
                    "SELECT COUNT(DISTINCT p.CODIGO) AS orders, COUNT(DISTINCT p.CLIENTE) AS customers,
                            COALESCE(SUM(p.VLRTOTAL), 0) AS revenue
                       FROM VE_PEDIDO p
                      WHERE " . self::VALID . "
                        AND p.DTPEDIDO >= DATEADD(day, " . (int) $from . ", CAST(GETDATE() AS date))
                        AND p.DTPEDIDO <  DATEADD(day, " . (int) $to . ", CAST(GETDATE() AS date))" . $in
                ) ?? [];
                return [
                    'orders'    => (int) ($r['orders'] ?? 0),
                    'customers' => (int) ($r['customers'] ?? 0),
                    'revenue'   => round((float) ($r['revenue'] ?? 0), 2),
                ];
            };
            $current  = $period(-($days - 1), 1);
            $previous = $period(-(2 * $days - 1), -($days - 1));

            $labels = ['E' => 'effective', 'V' => 'in_progress', 'S' => 'unconfirmed_batch', 'C' => 'cancelled', 'X' => 'cancelled'];
            $breakdown = [];
            foreach ($this->erp->query(
                "SELECT p.STATUS AS status, COUNT(DISTINCT p.CODIGO) AS orders, COALESCE(SUM(p.VLRTOTAL), 0) AS revenue
                   FROM VE_PEDIDO p
                  WHERE p.DTPEDIDO >= DATEADD(day, " . -($days - 1) . ", CAST(GETDATE() AS date))
                    AND p.DTPEDIDO <  DATEADD(day, 1, CAST(GETDATE() AS date))" . $in . "
                  GROUP BY p.STATUS"
            ) as $r) {
                $key = $labels[trim((string) $r['status'])] ?? ('status_' . trim((string) $r['status']));
                $breakdown[$key]['orders']  = ($breakdown[$key]['orders'] ?? 0) + (int) $r['orders'];
                $breakdown[$key]['revenue'] = round(($breakdown[$key]['revenue'] ?? 0) + (float) $r['revenue'], 2);
            }

            $topCustomers = $this->erp->query(
                "SELECT TOP 5 p.CLIENTE AS code, COUNT(DISTINCT p.CODIGO) AS orders, SUM(p.VLRTOTAL) AS revenue
                   FROM VE_PEDIDO p
                  WHERE " . self::VALID . "
                    AND p.DTPEDIDO >= DATEADD(day, " . -($days - 1) . ", CAST(GETDATE() AS date))" . $in . "
                  GROUP BY p.CLIENTE ORDER BY SUM(p.VLRTOTAL) DESC"
            );
            $topProducts = $this->erp->query(
                "SELECT TOP 5 i.MATERIAL AS sku, MAX(i.DESCRICAO) AS name, SUM(i.QTDE) AS qty, SUM(i.VLRTOTAL) AS revenue
                   FROM VE_PEDIDOITENS i
                   INNER JOIN VE_PEDIDO p ON i.PEDIDO = p.CODIGO
                  WHERE " . self::VALID . "
                    AND p.DTPEDIDO >= DATEADD(day, " . -($days - 1) . ", CAST(GETDATE() AS date))" . $in . "
                  GROUP BY i.MATERIAL ORDER BY SUM(i.VLRTOTAL) DESC"
            );

            return [
                'orders'        => $current['orders'],
                'customers'     => $current['customers'],
                'revenue'       => $current['revenue'],
                'previous'      => $previous,
                'status_breakdown' => $breakdown,
                'top_customers' => array_map(static fn (array $r): array => [
                    'code' => (int) $r['code'], 'orders' => (int) $r['orders'], 'revenue' => round((float) $r['revenue'], 2),
                ], $topCustomers),
                'top_products'  => array_map(static fn (array $r): array => [
                    'sku' => trim((string) $r['sku']), 'name' => trim((string) $r['name']),
                    'qty' => (float) $r['qty'], 'revenue' => round((float) $r['revenue'], 2),
                ], $topProducts),
            ];
        });
    }

    /**
     * @param int[] $codes
     * @return array<int, array<string, mixed>>
     */
    public function lastOrders(array $codes, int $limit = 5): array
    {
        $codes = $this->ints($codes);
        if ($codes === []) {
            return [];
        }
        $rows = $this->erp->query(
            'SELECT TOP ' . max(1, min(20, $limit)) . " p.CODIGO AS order_id, p.DTPEDIDO AS order_date,
                    p.STATUS AS status, p.VLRTOTAL AS total
               FROM VE_PEDIDO p WHERE " . self::VALID . "" . $this->inClause('p.CLIENTE', $codes) . '
              ORDER BY p.DTPEDIDO DESC'
        );
        return array_map(static fn (array $r): array => [
            'order_id' => (int) $r['order_id'],
            'date'     => substr((string) $r['order_date'], 0, 10),
            'status'   => trim((string) $r['status']),
            'total'    => round((float) $r['total'], 2),
        ], $rows);
    }

    /**
     * @param int[] $codes
     * @return array<int, array{month: string, orders: int, revenue: float}>
     */
    public function monthlyTrend(array $codes, int $months = 6): array
    {
        $codes = $this->ints($codes);
        if ($codes === []) {
            return [];
        }
        $rows = $this->erp->query(
            "SELECT FORMAT(p.DTPEDIDO, 'yyyy-MM') AS month, COUNT(DISTINCT p.CODIGO) AS orders, SUM(p.VLRTOTAL) AS revenue
               FROM VE_PEDIDO p
              WHERE " . self::VALID . "
                AND p.DTPEDIDO >= DATEADD(month, " . -max(1, min(24, $months)) . ', GETDATE())'
            . $this->inClause('p.CLIENTE', $codes) . "
              GROUP BY FORMAT(p.DTPEDIDO, 'yyyy-MM') ORDER BY month"
        );
        return array_map(static fn (array $r): array => [
            'month' => (string) $r['month'], 'orders' => (int) $r['orders'], 'revenue' => round((float) $r['revenue'], 2),
        ], $rows);
    }

    /**
     * Produtos por cliente (todos os códigos dele) com frequência e recência.
     *
     * @param int[] $codes
     * @return array<int, array<string, mixed>>
     */
    public function productHistory(array $codes, int $limit = 40): array
    {
        $codes = $this->ints($codes);
        if ($codes === []) {
            return [];
        }
        return $this->erp->query(
            'SELECT TOP ' . max(1, min(100, $limit)) . " i.MATERIAL AS sku, MAX(i.DESCRICAO) AS name,
                    COUNT(DISTINCT i.PEDIDO) AS order_count, SUM(i.QTDE) AS total_qty,
                    AVG(i.VLRUNITARIO) AS avg_price,
                    DATEDIFF(DAY, MAX(p.DTPEDIDO), GETDATE()) AS days_since_last
               FROM VE_PEDIDOITENS i
               INNER JOIN VE_PEDIDO p ON i.PEDIDO = p.CODIGO
              WHERE " . self::VALID . "
                AND p.DTPEDIDO >= DATEADD(month, -24, GETDATE())" . $this->inClause('p.CLIENTE', $codes) . '
              GROUP BY i.MATERIAL
              ORDER BY COUNT(DISTINCT i.PEDIDO) DESC, SUM(i.QTDE) DESC'
        );
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function cached(string $key, callable $fn): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        $hit = $this->cache->load($cacheKey);
        if ($hit !== false) {
            $decoded = json_decode((string) $hit, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $value = $fn();
        $this->cache->save((string) json_encode($value), $cacheKey, ['awa_copilot_erp'], self::CACHE_TTL);
        return $value;
    }

    /** @param int[]|null $codes */
    private function key(?array $codes): string
    {
        return $codes === null ? self::ALL : md5(implode(',', $codes));
    }

    /** @param int[]|null $codes */
    private function inClause(string $column, ?array $codes): string
    {
        if ($codes === null) {
            return '';
        }
        $codes = $this->ints($codes);
        return $codes === [] ? ' AND 1=0' : ' AND ' . $column . ' IN (' . implode(',', $codes) . ')';
    }

    /**
     * @param array<int|string, mixed> $values
     * @return int[]
     */
    private function ints(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }
        ksort($out);
        return array_values($out);
    }

    private function attributeId(string $code): int
    {
        $db = $this->resource->getConnection();
        return (int) $db->fetchOne(
            $db->select()
                ->from($this->resource->getTableName('eav_attribute'), 'attribute_id')
                ->where('entity_type_id = ?', 1)
                ->where('attribute_code = ?', $code)
        );
    }

    /** @param array<string, mixed> $row */
    private function displayName(array $row): string
    {
        $razao = trim((string) ($row['razao'] ?? ''));
        return $this->cleanName($razao !== '' ? $razao : (string) ($row['name'] ?? ''));
    }

    /**
     * Razão social de MEI começa com a raiz do CNPJ ("51.711.849 FULANO"):
     * é documento, não nome — fica fora da resposta e do LLM.
     */
    private function cleanName(string $name): string
    {
        $clean = preg_replace('/^\s*\d{2}\.?\d{3}\.?\d{3}(?:\/?\d{4}-?\d{2})?\s+/', '', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }
}
