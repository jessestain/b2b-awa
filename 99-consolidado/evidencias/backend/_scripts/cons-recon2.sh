#!/bin/bash
# task-72 — o 3o caminho que cria alerta + o unique constraint das metricas.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) DiagnosticReportService: como cria alerta (110-150) ==="
sed -n '105,150p' app/code/GrupoAwamotos/LogMonitoring/Service/AgentTermination/DiagnosticReportService.php

echo
echo "=== 2) db_schema.xml do LogMonitoring ==="
cat app/code/GrupoAwamotos/LogMonitoring/etc/db_schema.xml 2>/dev/null

echo
echo "=== 3) indice unico real na tabela de metricas ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SHOW CREATE TABLE awa_log_metrics") as $r) { echo $r["Create Table"] . "\n"; }
'

echo
echo "=== 4) o erro Unique constraint no log ==="
grep -rh 'Unique constraint\|Integrity constraint' var/log/*.log 2>/dev/null | tail -6 | cut -c1-230
