#!/bin/bash
# task-67 — constantes de status do MySQL-MQ + config dos numeros de WhatsApp.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) TODAS as constantes de status ==="
grep -n 'const MESSAGE_STATUS\|const MESSAGE_NUMBER\|maxNumberOfTrials' vendor/magento/module-mysql-mq/Model/QueueManagement.php | head -15
echo "--- maxNumberOfTrials (de onde vem) ---"
grep -rn 'maxNumberOfTrials' vendor/magento/module-mysql-mq/Model/QueueManagement.php vendor/magento/module-mysql-mq/etc/di.xml | head -10

echo
echo "=== 2) quem e consumido (NEW/RETRY) ==="
sed -n '175,185p' vendor/magento/module-mysql-mq/Model/ResourceModel/Queue.php

echo
echo "=== 3) config dos numeros de WhatsApp ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT scope, scope_id, path, value FROM core_config_data WHERE path LIKE \"grupoawamotos_b2b/whatsapp%\" ORDER BY path") as $r) {
  $v = $r["value"];
  if (stripos($r["path"], "number") !== false || stripos($r["path"], "team") !== false) {
    $v = strlen($v) > 40 ? substr($v,0,20) . "...(" . strlen($v) . " chars)" : $v;
  }
  echo "  {$r["scope"]}:{$r["scope_id"]} | {$r["path"]} = $v\n";
}
'

echo
echo "=== 4) as 28 mensagens: tipo e payload (so leitura) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT qm.topic_name, COUNT(*) n FROM queue_message qm
                      JOIN queue_message_status qms ON qms.message_id = qm.id
                      JOIN queue q ON q.id = qms.queue_id
                      WHERE q.name=\"grupoawamotos.b2b.whatsapp.send\" GROUP BY qm.topic_name") as $r) {
  echo "  {$r["topic_name"]} | {$r["n"]}\n";
}
echo "  -- amostra de payloads --\n";
foreach ($pdo->query("SELECT LEFT(qm.body, 150) b FROM queue_message qm
                      JOIN queue_message_status qms ON qms.message_id = qm.id
                      JOIN queue q ON q.id = qms.queue_id
                      WHERE q.name=\"grupoawamotos.b2b.whatsapp.send\" LIMIT 4") as $r) {
  echo "    " . $r["b"] . "\n";
}
'
