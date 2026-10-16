<?php
/** task-49 — schema + contagem ANTES. Somente leitura. */
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

dump('SHOW COLUMNS awa_log_alerts', $pdo->query('SHOW COLUMNS FROM awa_log_alerts')->fetchAll(PDO::FETCH_ASSOC));

dump('CONTAGEM POR TIPO (a medida do aceite)', $pdo->query(
    "SELECT alert_type, COUNT(*) AS linhas, SUM(occurrences) AS soma_occurrences,
            SUM(status='open') AS abertos, MIN(entity_id) AS id_min, MAX(entity_id) AS id_max
     FROM awa_log_alerts GROUP BY alert_type ORDER BY linhas DESC"
)->fetchAll(PDO::FETCH_ASSOC));

dump('nosuchentity_spike: as 13 linhas', $pdo->query(
    "SELECT entity_id, severity, status, occurrences, first_occurrence, last_occurrence, notified_at, source
     FROM awa_log_alerts WHERE alert_type='nosuchentity_spike' ORDER BY entity_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('total geral', $pdo->query("SELECT COUNT(*) linhas, SUM(occurrences) soma FROM awa_log_alerts")->fetchAll(PDO::FETCH_ASSOC));

dump('todos os alert_type distintos com severidade/status', $pdo->query(
    "SELECT alert_type, severity, status, COUNT(*) n FROM awa_log_alerts GROUP BY alert_type, severity, status ORDER BY n DESC LIMIT 25"
)->fetchAll(PDO::FETCH_ASSOC));
