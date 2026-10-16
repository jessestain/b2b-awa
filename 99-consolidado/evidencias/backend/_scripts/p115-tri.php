<?php
/** task-115 — o triangulo da reativacao: CCKATIVO x CKCOMERCIALIZA x preco. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

$mag = $conn->fetchAll(
    "SELECT e.sku, e.entity_id, i.qty,
            (SELECT value FROM catalog_product_entity_decimal d WHERE d.entity_id=e.entity_id AND d.store_id=0
              AND d.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4)) AS price
     FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0"
);
$erpInfo = [];
foreach (array_chunk($mag, 40) as $lote) {
    $ph = implode(',', array_fill(0, count($lote), '?'));
    $skus = array_map(static fn($r) => trim((string) $r['sku']), $lote);
    foreach ($erp->query("SELECT CODIGO, CCKATIVO, CKCOMERCIALIZA FROM MT_MATERIAL WHERE CODIGO IN ($ph)", $skus) as $r) {
        $erpInfo[trim((string) $r['CODIGO'])] = $r;
    }
}

$t = [
    'ATIVO+comercializa+preco_ok (deveria reativar)' => [],
    'ATIVO+comercializa+preco 0.01 (bloqueado pelo guard)' => [],
    'ATIVO+comercializa+sem preco (bloqueado)' => [],
    'ATIVO+NAO comercializa (fora do escopo)' => [],
    'INATIVO no ERP' => [],
    'NAO EXISTE no ERP' => [],
];
foreach ($mag as $m) {
    $sku = trim((string) $m['sku']);
    $e = $erpInfo[$sku] ?? null;
    $p = $m['price'] === null ? null : (float) $m['price'];
    if ($e === null) {
        $t['NAO EXISTE no ERP'][] = $sku;
    } elseif ($e['CCKATIVO'] !== 'S') {
        $t['INATIVO no ERP'][] = $sku;
    } elseif ($e['CKCOMERCIALIZA'] !== 'S') {
        $t['ATIVO+NAO comercializa (fora do escopo)'][] = $sku;
    } elseif ($p === null || $p <= 0) {
        $t['ATIVO+comercializa+sem preco (bloqueado)'][] = $sku;
    } elseif (abs($p - 0.01) < 0.0001) {
        $t['ATIVO+comercializa+preco 0.01 (bloqueado pelo guard)'][] = $sku;
    } else {
        $t['ATIVO+comercializa+preco_ok (deveria reativar)'][] = $sku;
    }
}
echo "=== TRIANGULO DA REATIVACAO (115 produtos) ===\n";
foreach ($t as $k => $v) {
    printf("  %-52s %3d  %s\n", $k, count($v), implode(', ', array_slice($v, 0, 12)));
}
$cands = $t['ATIVO+comercializa+preco_ok (deveria reativar)'];
echo "\n  >>> CANDIDATOS QUE DEVERIAM TER SIDO REATIVADOS PELO CRON E CONTINUAM DESATIVADOS: " . count($cands) . "\n";
echo '      ' . implode(', ', $cands) . "\n";
