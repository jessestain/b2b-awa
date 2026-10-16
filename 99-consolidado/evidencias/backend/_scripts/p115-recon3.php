<?php
/**
 * task-115 — RECON 3 (SOMENTE LEITURA): status real, amostra, NonCatalogProductList, tabelas de estoque.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

echo "############ 1) DISTRIBUICAO DE CCKATIVO / CKCOMERCIALIZA ############\n";
foreach ($erp->query(
    "SELECT TOP 12 CCKATIVO, CKCOMERCIALIZA, COUNT(*) n FROM MT_MATERIAL GROUP BY CCKATIVO, CKCOMERCIALIZA ORDER BY n DESC"
) as $r) {
    printf("    CCKATIVO=%-6s CKCOMERCIALIZA=%-6s n=%s\n",
        var_export($r['CCKATIVO'], true), var_export($r['CKCOMERCIALIZA'], true), $r['n']);
}
echo '  total: ' . $erp->fetchColumn('SELECT COUNT(*) FROM MT_MATERIAL') . "\n";

echo "\n############ 2) CCKSTATUS (o outro candidato) ############\n";
foreach ($erp->query("SELECT TOP 8 CCKSTATUS, COUNT(*) n FROM MT_MATERIAL GROUP BY CCKSTATUS ORDER BY n DESC") as $r) {
    printf("    CCKSTATUS=%-6s n=%s\n", var_export($r['CCKSTATUS'], true), $r['n']);
}

echo "\n############ 3) AMOSTRA dos 6 primeiros SKUs ############\n";
$skus = ['20050058', '6015', '6011', '6009', '6012', '4017 VM'];
$ph = implode(',', array_fill(0, count($skus), '?'));
$rows = $erp->query(
    "SELECT m.CODIGO, m.DESCRICAO, m.CCKATIVO, m.CKCOMERCIALIZA, m.CCKSTATUS, m.CODINTERNO,
            m.EDITDATE, m.EDITNAME, m.INSERTDATE
     FROM MT_MATERIAL m WHERE m.CODIGO IN ($ph)",
    $skus
);
echo '  encontrados: ' . count($rows) . ' de ' . count($skus) . "\n";
foreach ($rows as $r) {
    echo '    ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n############ 4) TABELAS DE ESTOQUE/SALDO ############\n";
foreach ($erp->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME LIKE '%ESTQ%' OR TABLE_NAME LIKE '%ESTOQ%' OR TABLE_NAME LIKE '%SALDO%'
        OR TABLE_NAME LIKE '%MATERIAL%' ORDER BY TABLE_NAME"
) as $t) {
    echo "    {$t['TABLE_NAME']}\n";
}

echo "\n############ 5) NonCatalogProductList ############\n";
foreach (['ERPIntegration/Model/NonCatalogProductList.php', 'ERPIntegration/Helper/NonCatalogProductList.php'] as $rel) {
    $f = $root . '/app/code/GrupoAwamotos/' . $rel;
    if (file_exists($f)) {
        echo "--- $rel ---\n" . file_get_contents($f);
    }
}
