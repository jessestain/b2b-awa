#!/bin/bash
# task-74 — quais combos (alert_type, severity, source) o modulo pode emitir? (limite de N)
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) todos os setAlertType do modulo ==="
grep -rn 'setAlertType(' app/code/GrupoAwamotos/LogMonitoring --include=*.php | grep -v '/Test/'

echo
echo "=== 2) constantes ALERT_TYPE / ALERT_SOURCE ==="
grep -rn 'ALERT_TYPE\s*=\|ALERT_SOURCE\s*=' app/code/GrupoAwamotos/LogMonitoring --include=*.php | grep -v '/Test/'

echo
echo "=== 3) as severidades que cada analyzer usa ==="
grep -rn 'setSeverity(' app/code/GrupoAwamotos/LogMonitoring --include=*.php | grep -v '/Test/'

echo
echo "=== 4) crontab do AlertProcessor ==="
grep -n -B3 -A5 'AlertProcessor' app/code/GrupoAwamotos/LogMonitoring/etc/crontab.xml

echo
echo "=== 5) quantos alertas distintos ja existiram (combos) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
echo "  combos (alert_type, severity, source) ja vistos:\n";
foreach ($pdo->query("SELECT alert_type, severity, source, COUNT(*) n FROM awa_log_alerts GROUP BY alert_type, severity, source") as $r) {
  echo "    {$r["alert_type"]} | {$r["severity"]} | {$r["source"]} | n={$r["n"]}\n";
}
echo "  abertos hoje: " . $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE status=\"open\"")->fetchColumn() . "\n";
echo "  severity IN (critical,high) e status != resolved (o que getCriticalAlerts enxerga): " .
     $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE severity IN (\"critical\",\"high\") AND status <> \"resolved\"")->fetchColumn() . "\n";
'
