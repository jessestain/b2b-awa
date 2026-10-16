<?php
/**
 * Backup duplamente verificado ANTES de qualquer alteracao (task-37).
 * Cria _backups/be-task37-<UTC>/{StoreSetup-module.xml.orig, *.sql, *.csv, ROLLBACK.md, SHA256SUMS}
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$ts = gmdate('Ymd\THis\Z');
$dir = $root . '/_backups/be-task37-' . $ts;
if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "nao consegui criar $dir\n");
    exit(1);
}

$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function mkInsert(string $table, array $rows): string
{
    $sql = '';
    foreach ($rows as $r) {
        $cols = array_keys($r);
        $vals = array_map(static function ($v) {
            return $v === null ? 'NULL' : "'" . str_replace("'", "''", (string)$v) . "'";
        }, array_values($r));
        $sql .= "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
    }
    return $sql;
}

// 1) module.xml do StoreSetup
$src = $root . '/app/code/GrupoAwamotos/StoreSetup/etc/module.xml';
copy($src, $dir . '/StoreSetup-module.xml.orig');

// 2) linhas de authorization_role / authorization_rule
$hashes = [];
foreach ([
    'authorization_role' => 'role_id IN (189,191)',
    'authorization_rule' => 'role_id IN (189,191)',
] as $table => $where) {
    $rows = $pdo->query("SELECT * FROM `$table` WHERE $where")->fetchAll(PDO::FETCH_ASSOC);
    $sql = "-- backup task-37 ($ts)\n-- origem: `$table` WHERE $where\n";
    $sql .= mkInsert($table, $rows);
    file_put_contents($dir . "/$table-189-191.sql", $sql);

    $csv = fopen($dir . "/$table-189-191.csv", 'w');
    if ($rows) {
        fputcsv($csv, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($csv, $r);
        }
    }
    fclose($csv);
    printf("%-20s %d linhas\n", $table, count($rows));
}

// 3) contagens ANTES
$antes = [
    'authorization_role' => (int)$pdo->query('SELECT COUNT(*) FROM authorization_role')->fetchColumn(),
    'authorization_rule' => (int)$pdo->query('SELECT COUNT(*) FROM authorization_rule')->fetchColumn(),
];
file_put_contents($dir . '/ANTES.json', json_encode(['ts' => $ts, 'contagens' => $antes] + $antes, JSON_PRETTY_PRINT));

// 4) rollback
$rollback = <<<MD
# ROLLBACK — task-37 (gerado $ts)

## B) GrupoAwamotos/StoreSetup/etc/module.xml
Restaurar o arquivo original (mesmo inode):
```
cat $dir/StoreSetup-module.xml.orig > $root/app/code/GrupoAwamotos/StoreSetup/etc/module.xml
sha256sum $root/app/code/GrupoAwamotos/StoreSetup/etc/module.xml   # esperado:
```
sha256 original: %SHA_MODULE%
Depois: `php bin/magento cache:clean config`

## C) papeis de ACL orfaos (role_id 189 e 191)
Reinserir:
```
mysql < $dir/authorization_role-189-191.sql
mysql < $dir/authorization_rule-189-191.sql
```
Contagens ANTES: authorization_role=%R%, authorization_rule=%U%
Depois: `php bin/magento cache:clean config`
MD;
$rollback = str_replace(
    ['%SHA_MODULE%', '%R%', '%U%'],
    [trim((string)shell_exec('sha256sum ' . escapeshellarg($src))), (string)$antes['authorization_role'], (string)$antes['authorization_rule']],
    $rollback
);
file_put_contents($dir . '/ROLLBACK.md', $rollback);

shell_exec('cd ' . escapeshellarg($dir) . ' && sha256sum * > SHA256SUMS 2>/dev/null');
echo "\nDIR=$dir\n";
echo "sha256 module.xml ANTES = " . trim((string)shell_exec('sha256sum ' . escapeshellarg($src))) . "\n";
echo "role=" . $antes['authorization_role'] . " rule=" . $antes['authorization_rule'] . "\n";
