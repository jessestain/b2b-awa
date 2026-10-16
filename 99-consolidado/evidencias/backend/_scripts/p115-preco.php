<?php
/** task-115 — o preco bloqueia a reativacao dos que estao ATIVOS no ERP? SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

$mag = $conn->fetchAll(
    "SELECT e.sku, e.entity_id, i.qty,
            (SELECT value FROM catalog_product_entity_decimal d
             WHERE d.entity_id=e.entity_id AND d.store_id=0
               AND d.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4)) AS price
     FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0"
);
echo 'total: ' . count($mag) . "\n";

$ativos = [];
foreach (array_chunk($mag, 40) as $lote) {
    $ph = implode(',', array_fill(0, count($lote), '?'));
    $skus = array_map(static fn($r) => trim((string) $r['sku']), $lote);
    foreach ($erp->query("SELECT CODIGO, CCKATIVO, CKCOMERCIALIZA FROM MT_MATERIAL WHERE CODIGO IN ($ph)", $skus) as $r) {
        $ativos[trim((string) $r['CODIGO'])] = $r;
    }
}

$faixas = ['0.01 (placeholder)' => 0, '<= 0' => 0, 'outro valor' => 0];
$ativosSemPreco = [];
foreach ($mag as $m) {
    $sku = trim((string) $m['sku']);
    $e = $ativos[$sku] ?? null;
    if ($e === null || $e['CCKATIVO'] !== 'S') {
        continue;
    }
    $p = $m['price'] === null ? null : (float) $m['price'];
    if ($p === null || $p <= 0) {
        $faixas['<= 0']++;
        $ativosSemPreco[] = $sku;
    } elseif (abs($p - 0.01) < 0.0001) {
        $faixas['0.01 (placeholder)']++;
        $ativosSemPreco[] = $sku;
    } else {
        $faixas['outro valor']++;
    }
}
echo "\n=== dos 50 ATIVOS no ERP, o preco no Magento ===\n";
foreach ($faixas as $k => $v) {
    printf("  %-22s %d\n", $k, $v);
}
echo '  com preco inutilizavel (<=0 ou 0.01): ' . count($ativosSemPreco) . " -> " . implode(', ', array_slice($ativosSemPreco, 0, 25)) . "\n";

echo "\n=== os 20 primeiros: preco x status ERP ===\n";
$ordem = ['20050058','6015','6011','2385','6009','3511','3510','6012','3122','3512','00576 MED','6016',
    '37','23','4021 CRS','022.012-6000','54','75','613 CV','4031 CRS'];
$porSku = [];
foreach ($mag as $m) {
    $porSku[trim((string) $m['sku'])] = $m;
}
foreach ($ordem as $i => $sku) {
    $m = $porSku[$sku] ?? null;
    $e = $ativos[$sku] ?? null;
    printf("  %-3d %-14s preco=%-9s qty=%-7s erp=%-9s\n", $i + 1, $sku,
        $m && $m['price'] !== null ? (float) $m['price'] : '(sem preco)',
        $m ? (int) $m['qty'] : '?',
        $e ? ($e['CCKATIVO'] === 'S' ? 'ATIVO' : 'INATIVO') : 'NAO_EXISTE');
}

echo "\n=== o guard de reativacao ===\n";
foreach (['Model/InactiveProductReactivationGuard.php', 'Model/Product/InactiveProductReactivationGuard.php'] as $rel) {
    $f = $root . '/app/code/GrupoAwamotos/ERPIntegration/' . $rel;
    if (file_exists($f)) {
        echo "--- $rel ---\n";
        $t = file_get_contents($f);
        echo substr($t, 0, 1800) . "\n";
    }
}
