#!/bin/bash
# task-72 — recon: passivo de alertas + incidente ainda vivo?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) estado dos alertas AGORA ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
echo "  por alert_type/status:\n";
foreach ($pdo->query("SELECT alert_type, severity, source, status, COUNT(*) n, SUM(occurrences) occ, MIN(entity_id) id_min, MAX(entity_id) id_max
                      FROM awa_log_alerts GROUP BY alert_type, severity, source, status ORDER BY n DESC") as $r) {
  echo "    {$r["alert_type"]} | {$r["severity"]} | {$r["source"]} | {$r["status"]} | n={$r["n"]} | occ={$r["occ"]} | ids {$r["id_min"]}..{$r["id_max"]}\n";
}
echo "\n  as 15 open:\n";
foreach ($pdo->query("SELECT entity_id, occurrences, first_occurrence, last_occurrence, notified_at, LEFT(message,60) msg
                      FROM awa_log_alerts WHERE status=\"open\" ORDER BY entity_id") as $r) {
  echo "    id={$r["entity_id"]} occ={$r["occurrences"]} first={$r["first_occurrence"]} last={$r["last_occurrence"]} notified={$r["notified_at"]} | {$r["msg"]}\n";
}
echo "\n  md5 das mensagens (procura duplicatas):\n";
foreach ($pdo->query("SELECT MD5(message) h, COUNT(*) n, GROUP_CONCAT(entity_id ORDER BY entity_id) ids
                      FROM awa_log_alerts WHERE status=\"open\" GROUP BY h ORDER BY n DESC") as $r) {
  echo "    {$r["h"]} -> n={$r["n"]} ids={$r["ids"]}\n";
}
'

echo
echo "=== 2) o incidente ainda esta vivo? (nosuchentity no tail dos logs) ==="
php -r '
$env = include "app/etc/env.php";
' 2>/dev/null
for f in var/log/awa_erp.log var/log/system.log var/log/exception.log; do
  [ -e "$f" ] || { echo "  $f: nao existe"; continue; }
  n=$(tail -c 204800 "$f" | grep -c 'NoSuchEntity' || true)
  echo "  $f: NoSuchEntity no ultimo 200KB = $n (mtime $(date -r "$f" '+%F %T'))"
done

echo
echo "=== 3) quem mais cria alerta no modulo ==="
grep -rn 'alertRepository->save\|alertFactory->create' app/code/GrupoAwamotos/LogMonitoring --include=*.php | grep -v '/Test/'
