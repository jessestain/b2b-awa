#!/bin/bash
# BUG A — superficie da config, escopo, e o autocomplete depende do Misspell?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) o autocomplete / search usam o Misspell? ==="
grep -rln 'Misspell' app/code/Mirasvit/SearchAutocomplete/ app/code/Mirasvit/Search/ 2>/dev/null | head -15
echo "(fim)"

echo
echo "=== 2) core_config_data: tudo com path misspell/ ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT config_id, scope, scope_id, path, value FROM core_config_data WHERE path LIKE \"misspell/%\" ORDER BY path") as $r) {
    echo "  {$r["config_id"]} | {$r["scope"]} | {$r["scope_id"]} | {$r["path"]} = {$r["value"]}\n";
}
' 2>&1 | head -20

echo
echo "=== 3) config:set esta disponivel? (teste no-op com o valor ATUAL) ==="
php bin/magento config:set misspell/general/active 1 2>&1 | head -5
echo "rc=$?"

echo
echo "=== 4) config:show depois do teste ==="
php bin/magento config:show misspell/general/active 2>&1 | head -3
