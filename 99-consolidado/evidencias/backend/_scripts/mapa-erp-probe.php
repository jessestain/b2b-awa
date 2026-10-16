<?php
/**
 * task-55 — FEASIBILIDADE (somente leitura do ERP, conexao de leitura):
 * o ERP conhece o CODIGO dos 23 pedidos importados sem mapa?
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$ids = [26,27,28,29,32,33,38,40,42,43,44,45,46,47,49,51,69,70,71,75,77,83,87];
$web = array_map(static fn(int $i): string => (string)($i + 200000), $ids);

echo "classe da conexao ERP: " . get_class($om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface')) . "\n";
echo 'pedidos web consultados: ' . implode(',', $web) . "\n\n";

$conn = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');
$ph = implode(',', array_fill(0, count($web), '?'));

try {
    $rows = $conn->query(
        "SELECT p.CODIGO, p.PEDIDOWEB, p.CLIENTE, p.VLRTOTAL
         FROM VE_PEDIDO p
         WHERE p.PEDIDOWEB IN ($ph)
         ORDER BY p.PEDIDOWEB",
        $web
    );
    echo 'linhas devolvidas pelo ERP: ' . count($rows) . "\n\n";
    $achados = [];
    foreach ($rows as $r) {
        $achados[(string)$r['PEDIDOWEB']] = $r;
        printf("  PEDIDOWEB=%-9s CODIGO=%-8s CLIENTE=%-7s VLRTOTAL=%s\n",
            $r['PEDIDOWEB'], $r['CODIGO'], $r['CLIENTE'], $r['VLRTOTAL']);
    }
    echo "\n=== cobertura ===\n";
    $sem = [];
    foreach ($ids as $i) {
        $key = (string)($i + 200000);
        if (!isset($achados[$key])) { $sem[] = $i; }
    }
    echo '  com CODIGO no ERP: ' . (count($ids) - count($sem)) . ' de ' . count($ids) . "\n";
    echo '  sem correspondencia: ' . (($sem) ? implode(',', $sem) : '(nenhum)') . "\n";
} catch (\Throwable $e) {
    echo 'ERRO na consulta ao ERP: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
