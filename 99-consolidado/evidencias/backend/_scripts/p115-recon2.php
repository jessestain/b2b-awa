<?php
/**
 * task-115 — RECON 2 (SOMENTE LEITURA):
 *  a) colunas de MT_MATERIAL (existe status? existe saldo?)
 *  b) NonCatalogProductList (a hipotese "ativo no ERP, fora da loja por decisao")
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "############ a) COLUNAS DE MT_MATERIAL ############\n";
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');
$cols = $erp->query(
    "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
     FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'MT_MATERIAL' ORDER BY ORDINAL_POSITION"
);
echo '  total de colunas: ' . count($cols) . "\n";
foreach ($cols as $c) {
    $flag = '';
    if (preg_match('/ATIVO|STATUS|SITUAC|EXCLU|BLOQ|INATIV/i', (string) $c['COLUMN_NAME'])) {
        $flag = '   <<< CANDIDATA A STATUS';
    }
    if (preg_match('/SALDO|ESTQ|ESTOQUE|QTDE|QTD|DISPON|QUANT/i', (string) $c['COLUMN_NAME'])) {
        $flag = '   <<< CANDIDATA A SALDO';
    }
    printf("    %-24s %-12s %-6s %s%s\n", $c['COLUMN_NAME'], $c['DATA_TYPE'],
        $c['CHARACTER_MAXIMUM_LENGTH'] ?? '-', $c['IS_NULLABLE'], $flag);
}

echo "\n############ b) DISTRIBUICAO DE CCKATIVO / CKCOMERCIALIZA NO ERP ############\n";
foreach ($erp->query(
    "SELECT TOP 10 CCKATIVO, CKCOMERCIALIZA, COUNT(*) n FROM MT_MATERIAL GROUP BY CCKATIVO, CKCOMERCIALIZA ORDER BY n DESC"
) as $r) {
    echo "    CCKATIVO=" . var_export($r['CCKATIVO'], true)
        . " CKCOMERCIALIZA=" . var_export($r['CKCOMERCIALIZA'], true)
        . " n={$r['n']}\n";
}
echo '  total de materiais: ' . ($erp->fetchColumn('SELECT COUNT(*) FROM MT_MATERIAL') ?? '?') . "\n";

echo "\n############ c) AMOSTRA dos 5 primeiros SKUs ############\n";
$skus = ['20050058', '6015', '6011', '6009', '6012'];
$ph = implode(',', array_fill(0, count($skus), '?'));
foreach ($erp->query(
    "SELECT m.CODIGO, m.DESCRICAO, m.CCKATIVO, m.CKCOMERCIALIZA, m.VCKATIVO, m.CODINTERNO
     FROM MT_MATERIAL m WHERE m.CODIGO IN ($ph)",
    $skus
) as $r) {
    echo '    ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n############ d) NonCatalogProductList ############\n";
$f = $root . '/app/code/GrupoAwamotos/ERPIntegration/Model/NonCatalogProductList.php';
echo file_exists($f) ? file_get_contents($f) : "  (nao existe em ERPIntegration; procurando...\n";
