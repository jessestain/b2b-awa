<?php
/** task-115 — rigor do saldo (tipos de estoque) + NonCatalogProductList x os 115. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

echo "=== 1) TPESTOQUE: que tipos existem e quanto representam ===\n";
foreach ($erp->query("SELECT TOP 12 TPESTOQUE, COUNT(*) linhas, COUNT(DISTINCT MATERIAL) materiais, SUM(QTDEREAL) soma FROM MT_ESTOQUE GROUP BY TPESTOQUE ORDER BY linhas DESC") as $r) {
    printf("    TPESTOQUE=%-6s linhas=%-7s materiais=%-7s soma=%s\n", var_export($r['TPESTOQUE'], true), $r['linhas'], $r['materiais'], $r['soma']);
}

echo "\n=== 2) FILIAL: quantas filiais ===\n";
foreach ($erp->query("SELECT TOP 10 FILIAL, COUNT(*) linhas, SUM(QTDEREAL) soma FROM MT_ESTOQUE GROUP BY FILIAL ORDER BY linhas DESC") as $r) {
    printf("    FILIAL=%-5s linhas=%-7s soma=%s\n", $r['FILIAL'], $r['linhas'], $r['soma']);
}

echo "\n=== 3) 20050058: TODAS as linhas de estoque (sem somar nada) ===\n";
foreach ($erp->query("SELECT FILIAL, TPESTOQUE, MATERIALLOTE, QTDEREAL, QTDEFISCAL FROM MT_ESTOQUE WHERE MATERIAL = ?", ['20050058']) as $r) {
    echo '    ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== 4) comparacao Magento x ERP nos 6 maiores ===\n";
$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$mag = $conn->fetchPairs(
    "SELECT e.sku, i.qty FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0"
);
foreach (['20050058', '6015', '6011', '2385', '6009', '3511'] as $sku) {
    $linhas = $erp->query("SELECT FILIAL, TPESTOQUE, QTDEREAL FROM MT_ESTOQUE WHERE MATERIAL = ?", [$sku]);
    $soma = 0.0;
    $det = [];
    foreach ($linhas as $l) {
        $soma += (float) $l['QTDEREAL'];
        $det[] = $l['TPESTOQUE'] . '/' . $l['FILIAL'] . '=' . (float) $l['QTDEREAL'];
    }
    printf("    %-10s magento=%-8s erp_soma=%-9s [%s]\n", $sku, $mag[trim($sku)] ?? '?', $soma, implode(' ', $det));
}

echo "\n=== 5) NonCatalogProductList x os 115 ===\n";
$f = $root . '/app/code/GrupoAwamotos/ERPIntegration/Model/Product/NonCatalogProductList.php';
if (file_exists($f)) {
    $txt = file_get_contents($f);
    preg_match_all("/'([0-9A-Za-z.\\- ]+)'\s*=>/", $txt, $m);
    $lista = array_unique($m[1] ?? []);
    echo '    SKUs na lista: ' . count($lista) . ' -> ' . implode(', ', array_slice($lista, 0, 20)) . "\n";
    $inter = array_intersect(array_map('trim', $lista), array_map('trim', array_keys($mag)));
    echo '    interseccao com os 115: ' . count($inter) . ($inter ? ' -> ' . implode(', ', $inter) : ' (nenhum)') . "\n";
    echo "    motivo de cada um:\n";
    preg_match_all("/'([0-9A-Za-z.\\- ]+)'\s*=>\s*'([^']{0,110})'/", $txt, $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        echo "      {$x[1]} => {$x[2]}\n";
    }
} else {
    echo "    (nao encontrado)\n";
}
