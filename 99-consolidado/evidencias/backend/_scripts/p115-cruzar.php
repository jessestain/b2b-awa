<?php
/**
 * task-115 — CRUZAMENTO dos 115 SKUs com o ERP (SOMENTE LEITURA).
 *
 * Fonte do status: MT_MATERIAL.CCKATIVO ('S'/'N') — a MESMA coluna que o modulo usa para decidir
 * o status no Magento (ProductSync.php:418-419 -> :492 setStatus(ENABLED/DISABLED); :133/:146/:172
 * filtram o escopo ativo com "WHERE m.CCKATIVO = 'S'").
 *
 * Escreve /tmp/p115.csv e imprime o resumo. NENHUMA escrita em Magento nem no ERP.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$erp  = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

$magento = $conn->fetchAll(
    "SELECT e.sku, i.qty, e.entity_id
     FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0
     ORDER BY i.qty DESC"
);
echo 'SKUs no Magento (status=2, qty>0): ' . count($magento) . "\n";

// ---- ERP: consulta em lotes (CODIGO e varchar(15))
$erpPorSku = [];
foreach (array_chunk($magento, 40) as $lote) {
    $skus = array_map(static fn($r) => (string) $r['sku'], $lote);
    $ph = implode(',', array_fill(0, count($skus), '?'));
    foreach ($erp->query(
        "SELECT m.CODIGO, m.DESCRICAO, m.CCKATIVO, m.CKCOMERCIALIZA, m.CCKSTATUS, m.CODINTERNO,
                m.EDITDATE, m.EDITNAME, m.INSERTDATE
         FROM MT_MATERIAL m WHERE m.CODIGO IN ($ph)",
        $skus
    ) as $r) {
        $erpPorSku[trim((string) $r['CODIGO'])] = $r;
    }
}
echo 'linhas devolvidas pelo ERP: ' . count($erpPorSku) . "\n";

// ---- monta o CSV
$fh = fopen('/tmp/p115.csv', 'w');
fputcsv($fh, ['sku', 'qty_magento', 'status_erp', 'qty_erp', 'existe_no_erp', 'comercializa_erp',
    'ckstatus_erp', 'descricao_erp', 'editdate_erp', 'editname_erp'], ';', '"', '');
$cont = ['ATIVO' => 0, 'INATIVO' => 0, 'NAO_EXISTE' => 0, 'INDETERMINADO' => 0];
$ativos = [];
foreach ($magento as $m) {
    $sku = (string) $m['sku'];
    $chave = trim($sku);
    $e = $erpPorSku[$chave] ?? null;
    if ($e === null) {
        $status = 'NAO_EXISTE';
    } elseif ($e['CCKATIVO'] === 'S') {
        $status = 'ATIVO';
    } elseif ($e['CCKATIVO'] === 'N') {
        $status = 'INATIVO';
    } else {
        $status = 'INDETERMINADO';
    }
    $cont[$status]++;
    if ($status === 'ATIVO') {
        $ativos[] = $sku;
    }
    fputcsv($fh, [
        $sku, (int) $m['qty'], $status, '', $e ? 'SIM' : 'NAO',
        $e['CKCOMERCIALIZA'] ?? '', $e['CCKSTATUS'] ?? '',
        $e['DESCRICAO'] ?? '', $e['EDITDATE'] ?? '', $e['EDITNAME'] ?? '',
    ], ';', '"', '');
}
fclose($fh);

echo "\n=== CONTAGEM ===\n";
foreach ($cont as $k => $v) {
    printf("  %-14s %d\n", $k, $v);
}
echo '  total         ' . array_sum($cont) . "\n";

echo "\n=== os ATIVOS no ERP (candidatos a reativar) ===\n";
echo '  ' . count($ativos) . " -> " . implode(', ', array_slice($ativos, 0, 40)) . "\n";

echo "\n=== DETALHE dos 6 primeiros da lista ===\n";
foreach (array_slice($magento, 0, 6) as $m) {
    $e = $erpPorSku[trim((string) $m['sku'])] ?? null;
    printf("  %-12s magento_qty=%-7s erp_ativo=%-4s comercializa=%-4s edit=%s por %s\n",
        $m['sku'], (int) $m['qty'], $e['CCKATIVO'] ?? '-', $e['CKCOMERCIALIZA'] ?? '-',
        $e['EDITDATE'] ?? '-', $e['EDITNAME'] ?? '-');
}
echo "\nCSV: /tmp/p115.csv\n";

echo "\n=== MT_ESTOQUE: colunas (para o saldo do ERP) ===\n";
foreach ($erp->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='MT_ESTOQUE' ORDER BY ORDINAL_POSITION") as $c) {
    printf("  %-20s %s\n", $c['COLUMN_NAME'], $c['DATA_TYPE']);
}
echo "--- 3 linhas de amostra ---\n";
foreach ($erp->query("SELECT TOP 3 * FROM MT_ESTOQUE") as $r) {
    echo '  ' . json_encode(array_slice($r, 0, 12), JSON_UNESCAPED_UNICODE) . "\n";
}
