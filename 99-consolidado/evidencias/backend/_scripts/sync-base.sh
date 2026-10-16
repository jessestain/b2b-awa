#!/bin/bash
# task-55 — qual job roda o sync de status, e qual a linha de base do log.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem chama o sync de status do OrderSync ==="
grep -rn 'OrderSync' app/code/GrupoAwamotos/ERPIntegration/etc/crontab.xml app/code/GrupoAwamotos/ERPIntegration/Console/Command/*.php 2>/dev/null | head -15

echo
echo "=== 2) crontab do ERPIntegration ==="
cat app/code/GrupoAwamotos/ERPIntegration/etc/crontab.xml 2>/dev/null | head -60

echo
echo "=== 3) o metodo que loga 'Syncing status for' ==="
grep -n -B 12 'Syncing status for' app/code/GrupoAwamotos/ERPIntegration/Model/OrderSync.php | head -30

echo
echo "=== 4) linha de base no log ==="
for f in var/log/erp_integration.log var/log/debug.log var/log/system.log; do
  [ -e "$f" ] || continue
  n=$(grep -c 'Syncing status for' "$f" 2>/dev/null || echo 0)
  echo "  $f -> $n linhas 'Syncing status for'"
  grep -h 'Syncing status for' "$f" 2>/dev/null | tail -3 | cut -c1-150
done

echo
echo "=== 5) quantos elegiveis ANTES (reconstruido a partir das 7 linhas antigas) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
$ids = [24,92,94,91,93,101,108];
$in = implode(",", $ids);
foreach ($pdo->query("SELECT entity_id, increment_id, state FROM sales_order WHERE entity_id IN ($in)") as $r) {
  echo "  id={$r["entity_id"]} inc={$r["increment_id"]} state={$r["state"]}\n";
}
$n = (int)$pdo->fetchOne ? 0 : 0;
$q = $pdo->query("SELECT COUNT(*) FROM sales_order WHERE entity_id IN ($in) AND state NOT IN (\"complete\",\"canceled\",\"closed\")");
echo "  elegiveis ANTES (das 7) = " . $q->fetchColumn() . "\n";
'
