<?php
/** BUG A — impacto real medido no log de busca. SOMENTE LEITURA. */
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

dump('janela do log', $pdo->query(
    "SELECT MIN(created_at) primeiro, MAX(created_at) ultimo, COUNT(*) n, COUNT(DISTINCT query) termos FROM mst_search_report_log"
)->fetchAll(PDO::FETCH_ASSOC));

dump('resumo geral', $pdo->query(
    "SELECT COUNT(*) buscas,
            SUM(results = 0) zero_resultado,
            SUM(misspell_query IS NOT NULL) via_misspell,
            SUM(fallback_query IS NOT NULL) via_fallback,
            SUM((misspell_query IS NOT NULL OR fallback_query IS NOT NULL)) redirecionadas,
            SUM((misspell_query IS NOT NULL OR fallback_query IS NOT NULL) AND clicks > 0) redir_com_clique,
            SUM(results = 0) - SUM((misspell_query IS NOT NULL OR fallback_query IS NOT NULL)) zero_sem_sugestao
     FROM mst_search_report_log"
)->fetchAll(PDO::FETCH_ASSOC));

dump('por source', $pdo->query(
    "SELECT source, COUNT(*) n, SUM(results=0) zero, SUM(misspell_query IS NOT NULL) miss, SUM(fallback_query IS NOT NULL) fb
     FROM mst_search_report_log GROUP BY source ORDER BY n DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC));

dump('TOP 25 termos redirecionados (misspell/fallback) com cliques', $pdo->query(
    "SELECT query, misspell_query, fallback_query, COUNT(*) n, SUM(clicks) cliques, SUM(clicks>0) buscas_com_clique
     FROM mst_search_report_log
     WHERE misspell_query IS NOT NULL OR fallback_query IS NOT NULL
     GROUP BY query, misspell_query, fallback_query
     ORDER BY n DESC LIMIT 25"
)->fetchAll(PDO::FETCH_ASSOC));

dump('TOP 20 termos com 0 resultado e SEM sugestao (nao redirecionados)', $pdo->query(
    "SELECT query, COUNT(*) n, SUM(clicks) cliques
     FROM mst_search_report_log
     WHERE results = 0 AND misspell_query IS NULL AND fallback_query IS NULL
     GROUP BY query ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));

foreach (['pneu', 'relacao', 'relacao-de-transmissao', 'pret', 'remocao'] as $termo) {
    $st = $pdo->prepare("SELECT query, misspell_query, fallback_query, results, clicks, source, created_at
        FROM mst_search_report_log WHERE query = ? ORDER BY created_at DESC LIMIT 6");
    $st->execute([$termo]);
    dump("historico de '$termo'", $st->fetchAll(PDO::FETCH_ASSOC));
}

dump('mst_misspell_suggest: colunas + contagem', $pdo->query('SHOW COLUMNS FROM mst_misspell_suggest')->fetchAll(PDO::FETCH_ASSOC));
dump('mst_misspell_suggest: total', $pdo->query('SELECT COUNT(*) n FROM mst_misspell_suggest')->fetchAll(PDO::FETCH_ASSOC));
dump('mst_misspell_suggest: amostra', $pdo->query('SELECT * FROM mst_misspell_suggest LIMIT 8')->fetchAll(PDO::FETCH_ASSOC));

dump('search_query: termos 0-resultado mais populares', $pdo->query(
    "SELECT query_id, query_text, num_results, popularity FROM search_query WHERE num_results = 0 ORDER BY popularity DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));
dump('search_query: total e com redirect configurado', $pdo->query(
    "SELECT COUNT(*) total, SUM(num_results=0) zero, SUM(redirect IS NOT NULL AND redirect <> '') com_redirect FROM search_query"
)->fetchAll(PDO::FETCH_ASSOC));
