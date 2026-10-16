#!/bin/bash
# task-67 — constantes reais do MySQL-MQ e o schema da fila.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) MessageStatus (mysql-mq) ==="
cat vendor/magento/module-mysql-mq/Model/MessageStatus.php | grep -n 'const \|class '

echo
echo "=== 2) quem marca IN_PROGRESS / COMPLETE / RETRY ==="
grep -rn 'IN_PROGRESS\|RETRY_REQUIRED\|STATUS_COMPLETE' vendor/magento/module-mysql-mq --include=*.php | grep -v Test | head -20

echo
echo "=== 3) o consumer do mysql-mq: tratamento de excecao ==="
find vendor/magento/module-mysql-mq -name '*.php' | head -20

echo
echo "=== 4) schema das tabelas de fila ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach (["queue_message_status","queue","queue_message"] as $t) {
  echo "-- $t\n";
  foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $r) { echo "   {$r["Field"]} | {$r["Type"]}\n"; }
}
'

echo
echo "=== 5) estado da fila do whatsapp (leitura) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT q.name AS fila, qms.status, COUNT(*) n, SUM(qms.number_of_trials) trials, MAX(qms.updated_at) ult
                      FROM queue_message_status qms JOIN queue q ON q.id = qms.queue_id
                      GROUP BY q.name, qms.status ORDER BY n DESC LIMIT 20") as $r) {
  echo "   {$r["fila"]} | status={$r["status"]} | n={$r["n"]} | trials={$r["trials"]} | ult={$r["ult"]}\n";
}
'
