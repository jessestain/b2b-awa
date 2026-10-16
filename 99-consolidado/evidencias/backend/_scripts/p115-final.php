<?php
/**
 * task-115 — ENTREGA FINAL (SOMENTE LEITURA).
 *  - status de cada um dos 115 (MT_MATERIAL.CCKATIVO)
 *  - saldo do ERP (MT_ESTOQUE.QTDEREAL somado por MATERIAL)
 *  - os 20 primeiros NA ORDEM DO DONO (peso_qty_x_demanda)
 *  - o NonCatalogProductList (a hipotese "ativo no ERP, fora da loja por decisao")
 * Escreve /tmp/lista1-115-com-status-erp.csv
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$erp  = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

/** ordem do dono (peso_qty_x_demanda) — lida de lista1-115-desativados-com-estoque.csv */
$ordemDono = ['20050058','6015','6011','2385','6009','3511','3510','6012','3122','3512','00576 MED','6016',
    '37','23','4021 CRS','022.012-6000','54','75','613 CV','4031 CRS','1077','2213','1010 FA','3036','2383'];

$magento = $conn->fetchAll(
    "SELECT e.sku, i.qty FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0"
);
$mag = [];
foreach ($magento as $m) {
    $mag[trim((string) $m['sku'])] = (int) $m['qty'];
}

$erpPorSku = [];
foreach (array_chunk(array_keys($mag), 40) as $lote) {
    $ph = implode(',', array_fill(0, count($lote), '?'));
    foreach ($erp->query(
        "SELECT m.CODIGO, m.DESCRICAO, m.CCKATIVO, m.CKCOMERCIALIZA, m.CCKSTATUS, m.EDITDATE, m.EDITNAME
         FROM MT_MATERIAL m WHERE m.CODIGO IN ($ph)", $lote
    ) as $r) {
        $erpPorSku[trim((string) $r['CODIGO'])] = $r;
    }
}

// ---- saldo do ERP
$saldo = [];
foreach (array_chunk(array_keys($mag), 40) as $lote) {
    $ph = implode(',', array_fill(0, count($lote), '?'));
    foreach ($erp->query(
        "SELECT MATERIAL, SUM(QTDEREAL) AS SALDO, COUNT(*) AS LINHAS FROM MT_ESTOQUE
         WHERE MATERIAL IN ($ph) GROUP BY MATERIAL", $lote
    ) as $r) {
        $saldo[trim((string) $r['MATERIAL'])] = ['saldo' => $r['SALDO'], 'linhas' => $r['LINHAS']];
    }
}
echo 'SKUs com linha em MT_ESTOQUE: ' . count($saldo) . " de " . count($mag) . "\n\n";

$fh = fopen('/tmp/lista1-115-com-status-erp.csv', 'w');
fputcsv($fh, ['sku', 'qty_magento', 'status_erp', 'qty_erp', 'existe_no_erp'], ';', '"', '');
$cont = ['ATIVO' => 0, 'INATIVO' => 0, 'NAO_EXISTE' => 0, 'INDETERMINADO' => 0];
$comSaldoZero = 0;
foreach ($mag as $sku => $qty) {
    $e = $erpPorSku[$sku] ?? null;
    $st = $e === null ? 'NAO_EXISTE' : ($e['CCKATIVO'] === 'S' ? 'ATIVO' : ($e['CCKATIVO'] === 'N' ? 'INATIVO' : 'INDETERMINADO'));
    $cont[$st]++;
    $qerp = isset($saldo[$sku]) ? (float) $saldo[$sku]['saldo'] : '';
    if ($st === 'ATIVO' && ($qerp === '' || (float) $qerp <= 0)) {
        $comSaldoZero++;
    }
    fputcsv($fh, [$sku, $qty, $st, $qerp, $e ? 'SIM' : 'NAO'], ';', '"', '');
}
fclose($fh);

echo "=== CONTAGEM (115) ===\n";
foreach ($cont as $k => $v) {
    printf("  %-14s %d\n", $k, $v);
}
echo '  dos ATIVOS, quantos tem SALDO ZERO ou sem linha no ERP: ' . $comSaldoZero . "\n";

echo "\n=== OS 20 PRIMEIROS (ordem do dono: peso_qty_x_demanda) ===\n";
printf("  %-3s %-14s %-9s %-9s %-10s %-6s %-20s %s\n", '#', 'sku', 'qty_mag', 'saldo_erp', 'status_erp', 'comerc', 'editdate', 'editname');
foreach (array_slice($ordemDono, 0, 20) as $i => $sku) {
    $e = $erpPorSku[$sku] ?? null;
    $st = $e === null ? 'NAO_EXISTE' : ($e['CCKATIVO'] === 'S' ? 'ATIVO' : 'INATIVO');
    printf("  %-3d %-14s %-9s %-9s %-10s %-6s %-20s %s\n", $i + 1, $sku,
        $mag[$sku] ?? '?', isset($saldo[$sku]) ? (float) $saldo[$sku]['saldo'] : '-', $st,
        $e['CKCOMERCIALIZA'] ?? '-', $e['EDITDATE'] ?? '-', $e['EDITNAME'] ?? '-');
}

echo "\n=== 20050058 (o maior) ===\n";
if (isset($erpPorSku['20050058'])) {
    echo '  MT_MATERIAL: ' . json_encode($erpPorSku['20050058'], JSON_UNESCAPED_UNICODE) . "\n";
}
if (isset($saldo['20050058'])) {
    echo '  MT_ESTOQUE: saldo somado=' . $saldo['20050058']['saldo'] . ' em ' . $saldo['20050058']['linhas'] . " linhas\n";
}
echo "  Magento qty = " . ($mag['20050058'] ?? '?') . "\n";

echo "\n=== NonCatalogProductList ===\n";
$out = [];
exec('grep -rl "class NonCatalogProductList" ' . escapeshellarg($root . '/app/code') . ' 2>/dev/null', $out);
if ($out) {
    foreach ($out as $f) {
        echo "--- $f ---\n";
        $txt = file_get_contents($f);
        echo substr($txt, 0, 2600) . "\n";
    }
} else {
    echo "  (classe nao encontrada)\n";
}
