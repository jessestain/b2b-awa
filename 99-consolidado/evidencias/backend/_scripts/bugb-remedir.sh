#!/bin/bash
# BUG B — remedicao depois do reindex que o outro agente disparou.
set -u
B=https://awamotos.com/catalogsearch/result
php /tmp/medir-busca.php "$B/?q=0047+PR"  "REMEDICAO 0047 PR"
php /tmp/medir-busca.php "$B/?q=PR"       "REMEDICAO PR"
php /tmp/medir-busca.php "$B/?q=0047%20PR&cb=$RANDOM" "REMEDICAO 0047 PR sem cache"
echo
echo "=== indexer_state agora ==="
php -r '
$env = include "/home/jessessh/htdocs/srv1113343.hstgr.cloud/app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT indexer_id, status, updated FROM indexer_state WHERE indexer_id IN (\"catalog_product_flat\",\"catalogsearch_fulltext\",\"catalog_category_product\")") as $r) {
  echo "  {$r["indexer_id"]} | {$r["status"]} | {$r["updated"]}\n";
}
'
