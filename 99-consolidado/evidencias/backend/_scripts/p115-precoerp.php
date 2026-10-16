<?php
/** task-115 — o preco R$ 0,01 vem do ERP? (MT_MATERIALLISTA.VLRVDSUG). SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

echo "=== colunas de MT_MATERIALLISTA ===\n";
foreach ($erp->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='MT_MATERIALLISTA' ORDER BY ORDINAL_POSITION") as $c) {
    printf("  %-18s %s\n", $c['COLUMN_NAME'], $c['DATA_TYPE']);
}

echo "\n=== preco no ERP dos 20 bloqueados pelo guard (e de 3 de controle) ===\n";
$skus = ['20050058','00576 MED','022.012-6000','022.012-6003','20700141','2381','502/SOLMAK','1077',
         '022.012-6016','6015','6011','2385'];
$ph = implode(',', array_fill(0, count($skus), '?'));
$rows = $erp->query(
    "SELECT MATERIAL, FATORPRECO, VLRVDSUG FROM MT_MATERIALLISTA WHERE MATERIAL IN ($ph) ORDER BY MATERIAL, FATORPRECO",
    $skus
);
$porSku = [];
foreach ($rows as $r) {
    $porSku[trim((string) $r['MATERIAL'])][] = $r['FATORPRECO'] . '=' . $r['VLRVDSUG'];
}
foreach ($skus as $s) {
    printf("  %-16s %s\n", $s, isset($porSku[$s]) ? implode(' | ', $porSku[$s]) : '(sem linha na lista de preco)');
}
