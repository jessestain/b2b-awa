#!/bin/bash
# task-67 — o que um throw faz no MySQL-MQ? ha teto de tentativas?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) RETRY_REQUIRED: quem define ==="
grep -rn 'RETRY_REQUIRED\|MB_RETRY' vendor/magento/framework-message-queue vendor/magento/module-message-queue 2>/dev/null | grep -v Test | head -20

echo
echo "=== 2) MessageStatus / constantes ==="
grep -rn 'const ' vendor/magento/framework/MessageQueue/MessageStatus.php 2>/dev/null
find vendor/magento -name 'MessageStatus*.php' 2>/dev/null | head

echo
echo "=== 3) quem trata excecao do handler no consumer ==="
grep -rn 'catch' vendor/magento/module-message-queue/Model/ResourceModel/Consumer.php 2>/dev/null | head
sed -n '1,40p' vendor/magento/module-message-queue/Model/ResourceModel/Consumer.php 2>/dev/null | head -5

echo
echo "=== 4) estado ATUAL da fila (NAO TOCAR — so leitura) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
echo "  queue_message_status por status/topico:\n";
foreach ($pdo->query("SELECT qt.name AS topico, qms.status, COUNT(*) n, SUM(qms.number_of_trials) trials
                      FROM queue_message_status qms
                      JOIN queue_message qm ON qm.id = qms.message_id
                      JOIN queue_message_to_topic qmt ON qmt.message_id = qm.id
                      JOIN queue_topic qt ON qt.id = qmt.topic_id
                      GROUP BY qt.name, qms.status ORDER BY n DESC") as $r) {
  echo "    {$r["topico"]} | status={$r["status"]} | n={$r["n"]} | trials={$r["trials"]}\n";
}
echo "\n  as 28 do whatsapp:\n";
foreach ($pdo->query("SELECT qms.status, COUNT(*) n, MIN(qms.number_of_trials) min_t, MAX(qms.number_of_trials) max_t
                      FROM queue_message_status qms
                      JOIN queue_message qm ON qm.id=qms.message_id
                      JOIN queue_message_to_topic qmt ON qmt.message_id=qm.id
                      JOIN queue_topic qt ON qt.id=qmt.topic_id
                      WHERE qt.name=\"grupoawamotos.b2b.whatsapp.send\" GROUP BY qms.status") as $r) {
  echo "    status={$r["status"]} n={$r["n"]} trials={$r["min_t"]}..{$r["max_t"]}\n";
}
'

echo
echo "=== 5) o consumidor roda? (cron/processo) ==="
ps aux | grep -i 'queue:consumers' | grep -v grep | head -5
echo "--- cron_schedule ---"
grep -rn 'consumers_runner' app/etc/env.php | head -3
