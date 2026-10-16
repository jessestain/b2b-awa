#!/bin/bash
# task-72 — confirmacoes finais.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) alguma linha NOVA de alerta depois do fix da task-49 (20:33:50 de 10/10)? ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
$r = $pdo->query("SELECT MAX(entity_id) max_id, MAX(created_at) max_created, MAX(last_occurrence) max_last FROM awa_log_alerts")->fetch(PDO::FETCH_ASSOC);
echo "  maior entity_id = {$r["max_id"]}\n";
echo "  maior created_at = {$r["max_created"]}\n";
echo "  maior last_occurrence = {$r["max_last"]}\n";
$n = $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE created_at > \"2026-10-10 20:33:50\"")->fetchColumn();
echo "  linhas criadas DEPOIS do fix da task-49 = $n  (0 = o dedupe nao deixou nascer duplicata)\n";
$o = $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE status=\"open\"")->fetchColumn();
echo "  alertas open hoje = $o\n";
'

echo
echo "=== 2) os 5 erros de Unique constraint no log ==="
grep -rh 'Unique constraint\|Integrity constraint violation' var/log/*.log 2>/dev/null | tail -6 | cut -c1-200
echo "-- contagem por arquivo --"
for f in var/log/*.log; do
  c=$(grep -c 'Unique constraint' "$f" 2>/dev/null || echo 0)
  [ "$c" != "0" ] && echo "   $f -> $c"
done

echo
echo "=== 3) a constraint no banco ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SHOW INDEX FROM awa_log_metrics WHERE Non_unique = 0") as $r) {
  echo "  unique: {$r["Key_name"]} | coluna={$r["Column_name"]} | seq={$r["Seq_in_index"]}\n";
}
'
