#!/bin/bash
# task-55 — de onde tirar o erp_code real, e o que o consumidor faz com o mapa.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) tabelas com 'erp' no nome ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SHOW TABLES") as $r) { $t=$r[0]; if (stripos($t,"erp")!==false || stripos($t,"sectra")!==false) echo "  $t\n"; }
'

echo
echo "=== 2) colunas de sales_order que citam erp/sectra ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SHOW COLUMNS FROM sales_order") as $r) { if (stripos($r["Field"],"erp")!==false || stripos($r["Field"],"sectra")!==false) echo "  {$r["Field"]} | {$r["Type"]}\n"; }
'

echo
echo "=== 3) OrderSync: como usa o mapa (160-200) ==="
sed -n '160,200p' app/code/GrupoAwamotos/ERPIntegration/Model/OrderSync.php

echo
echo "=== 4) OrderSync::getPendingOrdersForSync (1197+) ==="
sed -n '1197,1260p' app/code/GrupoAwamotos/ERPIntegration/Model/OrderSync.php

echo
echo "=== 5) a mensagem 'Syncing status for' ==="
grep -rn 'Syncing status for' app/code --include=*.php

echo
echo "=== 6) ConnectionInterface: read vs write ==="
grep -rn 'function ' app/code/GrupoAwamotos/ERPIntegration/Api/ConnectionInterface.php
