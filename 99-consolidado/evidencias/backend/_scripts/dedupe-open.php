<?php
/** task-49 — estado exato antes de mexer. Somente leitura. */
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

dump('ABERTOS de nosuchentity_spike (todos)', $pdo->query(
    "SELECT entity_id, severity, status, occurrences, source, first_occurrence, last_occurrence, notified_at
     FROM awa_log_alerts WHERE alert_type='nosuchentity_spike' AND status='open' ORDER BY entity_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('resumo por status', $pdo->query(
    "SELECT status, COUNT(*) n, SUM(occurrences) soma, MIN(entity_id) id_min, MAX(entity_id) id_max
     FROM awa_log_alerts WHERE alert_type='nosuchentity_spike' GROUP BY status"
)->fetchAll(PDO::FETCH_ASSOC));

dump('todos os tipos x status', $pdo->query(
    "SELECT alert_type, source, severity, status, COUNT(*) n FROM awa_log_alerts
     GROUP BY alert_type, source, severity, status ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC));

dump('quantos abertos NOTIFICAVEIS hoje (severity critical/high, status=open, occurrences<=3)', $pdo->query(
    "SELECT COUNT(*) n FROM awa_log_alerts WHERE status='open' AND severity IN ('critical','high') AND occurrences <= 3"
)->fetchAll(PDO::FETCH_ASSOC));

dump('linhas criadas nas ultimas 24h (o ritmo do flood)', $pdo->query(
    "SELECT DATE(created_at) dia, COUNT(*) n FROM awa_log_alerts GROUP BY DATE(created_at) ORDER BY dia DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC));
