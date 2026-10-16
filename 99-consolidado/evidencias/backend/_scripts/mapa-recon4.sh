#!/bin/bash
# task-55 — ultimas fontes possiveis de erp_code + o chamador da reconciliacao.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) sync_log: entity_type sectra_b2b_order e order_status ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach (["sectra_b2b_order","order_status"] as $t) {
  echo "-- $t\n";
  foreach ($pdo->query("SELECT log_id, direction, erp_code, magento_id, status, LEFT(message,80) msg, created_at FROM grupoawamotos_erp_sync_log WHERE entity_type=\"$t\" ORDER BY log_id DESC LIMIT 8") as $r) {
    echo "   {$r["log_id"]} | {$r["direction"]} | erp={$r["erp_code"]} | mag={$r["magento_id"]} | {$r["status"]} | {$r["msg"]} | {$r["created_at"]}\n";
  }
}
'

echo
echo "=== 2) syncImportedOrderFlags (313-356) ==="
sed -n '313,356p' app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php

echo
echo "=== 3) syncImportedOrderFlags: quem chama ==="
grep -rn 'syncImportedOrderFlags' app/code --include=*.php | grep -v '/Test/'

echo
echo "=== 4) HeldOrdersCommand 430-450 (o caminho CLI que grava os dois) ==="
sed -n '430,450p' app/code/GrupoAwamotos/ERPIntegration/Console/Command/HeldOrdersCommand.php

echo
echo "=== 5) ConnectionInterface ==="
cat app/code/GrupoAwamotos/ERPIntegration/Api/ConnectionInterface.php | grep -n 'function\|interface'
