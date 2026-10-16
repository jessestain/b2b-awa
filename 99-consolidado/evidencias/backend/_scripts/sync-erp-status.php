<?php
/** task-55 — o ERP diz que esses 21 pedidos estao cancelados? (somente leitura) */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$codes = ['192433','192434','192435','192436','192437','192525','192621','192631','192639','192640',
          '192641','192642','192643','192644','192857','193029','193079','193080','193078','193090','193091'];

$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');
$ph = implode(',', array_fill(0, count($codes), '?'));
$rows = $erp->query(
    "SELECT p.CODIGO, p.PEDIDOWEB, p.STATUS, p.VLRTOTAL FROM VE_PEDIDO p WHERE p.CODIGO IN ($ph) ORDER BY p.CODIGO",
    $codes
);

echo "  CODIGO | PEDIDOWEB | STATUS | VLRTOTAL\n";
$porStatus = [];
foreach ($rows as $r) {
    printf("  %-8s | %-9s | %-6s | %s\n", $r['CODIGO'], $r['PEDIDOWEB'], $r['STATUS'], $r['VLRTOTAL']);
    $porStatus[(string) $r['STATUS']] = ($porStatus[(string) $r['STATUS']] ?? 0) + 1;
}
echo "\nlinhas: " . count($rows) . "\n";
echo 'distribuicao de STATUS no ERP: ' . json_encode($porStatus) . "\n";

$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();

echo "\n=== comentario que o sync deixou no pedido 26 ===\n";
foreach ($conn->fetchAll(
    "SELECT comment, created_at FROM sales_order_status_history WHERE parent_id = 26 ORDER BY entity_id DESC LIMIT 3"
) as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== mapeamento de STATUS do ERP no OrderSync ===\n";
$f = $root . '/app/code/GrupoAwamotos/ERPIntegration/Model/OrderSync.php';
$out = [];
exec('grep -n "STATUS_PASSTHROUGH\|STATUS_TO_CANCEL\|const STATUS\|case \x27C\x27\|cancel" ' . escapeshellarg($f) . ' | head -25', $out);
foreach ($out as $l) {
    echo '  ' . substr(trim($l), 0, 190) . "\n";
}
