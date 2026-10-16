<?php
/** task-55 — os 23 tem erp_code em algum lugar do Magento? */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ids = [26,27,28,29,32,33,38,40,42,43,44,45,46,47,49,51,69,70,71,75,77,83,87];
$in = implode(',', $ids);

function dump(string $t, array $r): void
{
    echo "\n### $t\n";
    if (!$r) { echo "(vazio)\n"; return; }
    echo implode(' | ', array_keys($r[0])) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

dump('erp_sync_log: linhas com entity_type=order', $pdo->query(
    "SELECT entity_type, direction, status, COUNT(*) n FROM grupoawamotos_erp_sync_log WHERE entity_type='order' GROUP BY entity_type, direction, status"
)->fetchAll(PDO::FETCH_ASSOC));

dump('erp_sync_log: entity_type=order com erp_code preenchido', $pdo->query(
    "SELECT log_id, entity_type, direction, erp_code, magento_id, status, created_at
     FROM grupoawamotos_erp_sync_log WHERE entity_type='order' AND erp_code IS NOT NULL AND erp_code <> '' AND magento_id > 0
     ORDER BY log_id DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC));

dump('erp_sync_log: linhas para os 23 ids', $pdo->query(
    "SELECT log_id, entity_type, direction, erp_code, magento_id, status, created_at
     FROM grupoawamotos_erp_sync_log WHERE magento_id IN ($in) ORDER BY magento_id, log_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('outros entity_types que possam carregar order', $pdo->query(
    "SELECT entity_type, COUNT(*) n FROM grupoawamotos_erp_sync_log GROUP BY entity_type ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));

dump('vw_sectra_pedidos_sincronizados: colunas', $pdo->query('SHOW COLUMNS FROM vw_sectra_pedidos_sincronizados')->fetchAll(PDO::FETCH_ASSOC));

dump('vw_sectra_pedidos_sincronizados: total', $pdo->query('SELECT COUNT(*) n FROM vw_sectra_pedidos_sincronizados')->fetchAll(PDO::FETCH_ASSOC));

dump('vw_sectra_pedidos_sincronizados: os 23', $pdo->query(
    "SELECT * FROM vw_sectra_pedidos_sincronizados LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC));
