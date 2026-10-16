#!/bin/bash
# BUG A — desliga a substituicao silenciosa de termo na busca.
# Backup -> config:set -> cache:clean config -> prova.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TS=$(date -u +%Y%m%dT%H%M%SZ)
BAK="$ROOT/_backups/busca-misspell-$TS"
mkdir -p "$BAK"

echo "=== 1) BACKUP da config atual ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
$rows = $pdo->query("SELECT config_id, scope, scope_id, path, value FROM core_config_data WHERE path LIKE \"misspell/%\" ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
$sql = "-- backup task-40 BUG A ($argv[1])\n";
$out = "";
foreach ($rows as $r) {
    $out .= "{$r["config_id"]} | {$r["scope"]} | {$r["scope_id"]} | {$r["path"]} = {$r["value"]}\n";
    $v = str_replace("'"'"'", "'"'"''"'"'", (string)$r["value"]);
    $sql .= "UPDATE core_config_data SET value = '"'"'$v'"'"' WHERE config_id = {$r["config_id"]};\n";
}
file_put_contents($argv[2] . "/core_config_data-misspell.txt", $out);
file_put_contents($argv[2] . "/ROLLBACK.sql", $sql);
echo $out;
' "$TS" "$BAK"
echo "-- gravado em $BAK"
ls -la "$BAK"

echo
echo "=== 2) config:set (default scope) ==="
php bin/magento config:set misspell/general/active 0 2>&1 | head -3
php bin/magento config:set misspell/general/fallback 0 2>&1 | head -3

echo
echo "=== 3) cache:clean config ==="
php bin/magento cache:clean config 2>&1 | tail -3

echo
echo "=== 4) prova: config:show ==="
echo -n "misspell/general/active   = "; php bin/magento config:show misspell/general/active 2>&1 | head -1
echo -n "misspell/general/fallback = "; php bin/magento config:show misspell/general/fallback 2>&1 | head -1

echo
echo "=== 5) prova: curl -I nos casos do aceite ==="
for q in pneu relacao relacao-de-transmissao capacete; do
  echo "--- q=$q"
  curl -sI -A 'Mozilla/5.0' "https://awamotos.com/catalogsearch/result/?q=$q" | grep -i -E '^(HTTP/|location:)' | sed 's/^/    /'
done

echo
echo "=== 6) prova no corpo: pneu mantem o termo e mostra 0 resultado? ==="
php /tmp/medir-busca.php "https://awamotos.com/catalogsearch/result/?q=pneu"  "DEPOIS pneu"
php /tmp/medir-busca.php "https://awamotos.com/catalogsearch/result/?q=relacao" "DEPOIS relacao"

echo
echo "BACKUP=$BAK"
