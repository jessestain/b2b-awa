<?php
/**
 * task-55 — VALIDACAO: rodar o job de sync de status e medir o "Syncing status for N orders".
 * Registra o estado dos pedidos ANTES e DEPOIS (transparencia sobre o que o job escreve).
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$rc = $om->get('Magento\\Framework\\App\\ResourceConnection');
$conn = $rc->getConnection();

$helper = $om->get('GrupoAwamotos\\ERPIntegration\\Helper\\Data');
echo 'isOrderSyncEnabled() = ' . var_export($helper->isOrderSyncEnabled(), true) . "\n";

$mapa = $conn->fetchAll(
    "SELECT em.erp_code, em.magento_entity_id, so.increment_id, so.state, so.status
     FROM grupoawamotos_erp_entity_map em
     INNER JOIN sales_order so ON so.entity_id = em.magento_entity_id
     WHERE em.entity_type='order'
       AND so.state NOT IN ('complete','canceled','closed')
       AND em.erp_code REGEXP '^[0-9]+$' AND CAST(em.erp_code AS UNSIGNED) > 0
     ORDER BY so.entity_id"
);
echo 'pedidos elegiveis (o que o sync vai ver): ' . count($mapa) . "\n";

$antes = [];
foreach ($mapa as $r) {
    $antes[(int) $r['magento_entity_id']] = $r['state'] . '/' . $r['status'];
}
$shipAntes = (int) $conn->fetchOne('SELECT COUNT(*) FROM sales_shipment');
echo "shipments antes: $shipAntes\n";

$logs = [
    'debug' => $root . '/var/log/debug.log',
    'erp'   => $root . '/var/log/erp_integration.log',
];
$tamanhoAntes = [];
foreach ($logs as $k => $f) {
    $tamanhoAntes[$k] = file_exists($f) ? filesize($f) : 0;
    echo "log $k antes: {$tamanhoAntes[$k]} bytes\n";
}

echo "\n=== rodando SyncOrderStatuses::execute() (o job do cron */15) ===\n";
$t0 = microtime(true);
try {
    $om->create('GrupoAwamotos\\ERPIntegration\\Cron\\SyncOrderStatuses')->execute();
    echo 'job executado em ' . round((microtime(true) - $t0) * 1000) . " ms\n";
} catch (\Throwable $e) {
    echo 'EXCECAO: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n=== log NOVO (procurando 'Syncing status for') ===\n";
foreach ($logs as $k => $f) {
    if (!file_exists($f)) { continue; }
    clearstatcache(true, $f);
    $novo = filesize($f) - $tamanhoAntes[$k];
    echo "-- $k: cresceu $novo bytes\n";
    if ($novo > 0 && $novo < 4000000) {
        $fh = fopen($f, 'r');
        fseek($fh, $tamanhoAntes[$k]);
        $txt = stream_get_contents($fh);
        fclose($fh);
        foreach (explode("\n", $txt) as $line) {
            if (stripos($line, 'Syncing status for') !== false || stripos($line, 'Order status sync') !== false) {
                echo '   ' . substr(trim($line), 0, 220) . "\n";
            }
        }
    }
}

echo "\n=== pedidos DEPOIS ===\n";
$mudou = 0;
foreach ($mapa as $r) {
    $id = (int) $r['magento_entity_id'];
    $depois = $conn->fetchRow("SELECT state, status FROM sales_order WHERE entity_id = $id");
    $atual = $depois['state'] . '/' . $depois['status'];
    if ($atual !== $antes[$id]) {
        echo "  MUDOU id=$id {$r['increment_id']}: {$antes[$id]} -> $atual\n";
        $mudou++;
    }
}
echo "pedidos com status alterado: $mudou\n";
$shipDepois = (int) $conn->fetchOne('SELECT COUNT(*) FROM sales_shipment');
echo "shipments depois: $shipDepois (delta " . ($shipDepois - $shipAntes) . ")\n";
