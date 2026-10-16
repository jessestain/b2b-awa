<?php
/** task-55 — o erp_code dos 29 imported ja esta registrado em alguma tabela do Magento? */
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

foreach (['grupoawamotos_erp_sync_log', 'sectra_export_audit', 'grupoawamotos_b2b_sectra_sync_log', 'grupoawamotos_erp_order_retry'] as $t) {
    $ex = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    if (!$ex) { echo "\n### $t NAO EXISTE\n"; continue; }
    dump("COLUNAS $t", $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC));
    dump("$t: total", $pdo->query("SELECT COUNT(*) n FROM `$t`")->fetchAll(PDO::FETCH_ASSOC));
    dump("$t: amostra", $pdo->query("SELECT * FROM `$t` ORDER BY 1 DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
}

echo "\n=== os 29 imported: contagem exata e quantos sem mapa ===\n";
dump('resumo', $pdo->query(
    "SELECT COUNT(*) imported,
            SUM(EXISTS(SELECT 1 FROM grupoawamotos_erp_entity_map m WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id)) com_mapa,
            SUM(NOT EXISTS(SELECT 1 FROM grupoawamotos_erp_entity_map m WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id)) sem_mapa
     FROM sales_order so WHERE so.sectra_import_status='imported'"
)->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== os imported SEM mapa: estado + se sao sincronizaveis (state NOT IN complete/canceled/closed) ===\n";
dump('lista', $pdo->query(
    "SELECT so.entity_id, so.increment_id, so.state, so.status,
            EXISTS(SELECT 1 FROM oc_order_imported oi WHERE oi.order_id = so.entity_id + 200000) em_oc_imported
     FROM sales_order so
     WHERE so.sectra_import_status='imported'
       AND NOT EXISTS(SELECT 1 FROM grupoawamotos_erp_entity_map m WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id)
     ORDER BY so.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== a view vw_sectra_pedidos_sincronizados existe no MySQL? ===\n";
$ex = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vw_sectra_pedidos_sincronizados'")->fetchColumn();
echo $ex ? "SIM\n" : "NAO\n";
if ($ex) {
    dump('colunas', $pdo->query('SHOW COLUMNS FROM vw_sectra_pedidos_sincronizados')->fetchAll(PDO::FETCH_ASSOC));
    dump('amostra', $pdo->query('SELECT * FROM vw_sectra_pedidos_sincronizados LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));
}
