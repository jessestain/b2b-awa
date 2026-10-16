<?php
/** BUG A — impacto: tabelas de log/busca da Mirasvit. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function dump(string $t, array $r): void
{
    echo "\n### $t\n";
    if (!$r) { echo "(vazio)\n"; return; }
    echo implode(' | ', array_keys($r[0])) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

dump('tabelas mst_% / search', $pdo->query("SHOW TABLES LIKE '%search%'")->fetchAll(PDO::FETCH_NUM));
dump('tabelas mst_*', $pdo->query("SHOW TABLES LIKE 'mst_%'")->fetchAll(PDO::FETCH_NUM));
dump('tabelas search_query*', $pdo->query("SHOW TABLES LIKE 'search_query%'")->fetchAll(PDO::FETCH_NUM));

foreach (['mst_search_report_log', 'mst_misspell_index', 'search_query'] as $t) {
    $ex = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
    if (!$ex) { echo "\n### $t NAO EXISTE\n"; continue; }
    dump("SHOW COLUMNS $t", $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC));
    dump("COUNT $t", $pdo->query("SELECT COUNT(*) AS n FROM `$t`")->fetchAll(PDO::FETCH_ASSOC));
}

dump('search_query: top 15 por popularidade', $pdo->query(
    "SELECT query_id, query_text, num_results, popularity, is_active, redirect FROM search_query ORDER BY popularity DESC LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC));

dump('search_query: termos com num_results=0 (mais populares)', $pdo->query(
    "SELECT query_id, query_text, num_results, popularity FROM search_query WHERE num_results = 0 ORDER BY popularity DESC LIMIT 25"
)->fetchAll(PDO::FETCH_ASSOC));
