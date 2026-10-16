<?php
/** task-55 — de onde vem o erp_code dos pedidos importados. Somente leitura. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function dump(string $t, array $r): void
{
    echo "\n### $t\n";
    if (!$r) { echo "(vazio)\n"; return; }
    echo implode(' | ', array_keys($r[0])) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

dump('SHOW COLUMNS oc_order_imported', $pdo->query('SHOW COLUMNS FROM oc_order_imported')->fetchAll(PDO::FETCH_ASSOC));
dump('oc_order_imported: total e amostra', $pdo->query('SELECT * FROM oc_order_imported ORDER BY order_id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC));
dump('oc_order_imported: total', $pdo->query('SELECT COUNT(*) n FROM oc_order_imported')->fetchAll(PDO::FETCH_ASSOC));

dump('grupoawamotos_erp_entity_map: colunas', $pdo->query('SHOW COLUMNS FROM grupoawamotos_erp_entity_map')->fetchAll(PDO::FETCH_ASSOC));
dump('mapa: total por entity_type', $pdo->query("SELECT entity_type, COUNT(*) n FROM grupoawamotos_erp_entity_map GROUP BY entity_type")->fetchAll(PDO::FETCH_ASSOC));
dump('mapa: as 7 linhas de order', $pdo->query("SELECT * FROM grupoawamotos_erp_entity_map WHERE entity_type='order' ORDER BY map_id")->fetchAll(PDO::FETCH_ASSOC));

dump('sales_order por sectra_import_status', $pdo->query(
    "SELECT COALESCE(sectra_import_status,'(NULL)') st, COUNT(*) n FROM sales_order GROUP BY sectra_import_status ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC));

dump('os imported SEM linha no mapa', $pdo->query(
    "SELECT so.entity_id, so.increment_id, so.state, so.created_at,
            (SELECT COUNT(*) FROM oc_order_imported oi WHERE oi.order_id = so.entity_id + 200000) AS em_oc_imported,
            (SELECT COUNT(*) FROM grupoawamotos_erp_entity_map m WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id) AS no_mapa
     FROM sales_order so WHERE so.sectra_import_status='imported' ORDER BY so.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));
