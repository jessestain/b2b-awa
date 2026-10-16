<?php
/** BUG A — de ONDE vem a sugestao pneu->pret, e impacto correto no log. SOMENTE LEITURA. */
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

dump('mst_misspell_suggest: pneu / relacao / relacao', $pdo->query(
    "SELECT * FROM mst_misspell_suggest WHERE query LIKE '%pneu%' OR query LIKE '%rela%' OR suggest LIKE '%pret%' OR suggest LIKE '%remoc%'"
)->fetchAll(PDO::FETCH_ASSOC));

dump('mst_misspell_suggest: total e quantos sao identidade', $pdo->query(
    "SELECT COUNT(*) total, SUM(query = suggest) identidade, SUM(query <> suggest) mudam FROM mst_misspell_suggest"
)->fetchAll(PDO::FETCH_ASSOC));

dump('mst_misspell_suggest: os que MUDAM o termo', $pdo->query(
    "SELECT * FROM mst_misspell_suggest WHERE query <> suggest ORDER BY query LIMIT 40"
)->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== IMPACTO REAL (misspell_query/fallback_query sao '' e nao NULL) ===\n";
dump('resumo', $pdo->query(
    "SELECT COUNT(*) buscas,
            SUM(results = 0) zero_resultado,
            SUM(misspell_query <> '') entradas_via_misspell,
            SUM(fallback_query <> '') entradas_via_fallback,
            SUM(misspell_query <> '' OR fallback_query <> '') total_redirecionadas,
            SUM(clicks) cliques_totais
     FROM mst_search_report_log"
)->fetchAll(PDO::FETCH_ASSOC));

dump('TOP 20 pares original -> corrigido (o que o cliente digitou x para onde foi)', $pdo->query(
    "SELECT misspell_query AS digitou, query AS recebeu, COUNT(*) n
     FROM mst_search_report_log WHERE misspell_query <> ''
     GROUP BY misspell_query, query ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));

dump('TOP 20 pares via fallback', $pdo->query(
    "SELECT fallback_query AS digitou, query AS recebeu, COUNT(*) n
     FROM mst_search_report_log WHERE fallback_query <> ''
     GROUP BY fallback_query, query ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));

dump('quantos TERMOS distintos foram substituidos', $pdo->query(
    "SELECT COUNT(DISTINCT misspell_query) termos_substituidos FROM mst_search_report_log WHERE misspell_query <> ''"
)->fetchAll(PDO::FETCH_ASSOC));

dump('o que o cliente digitou quando a busca deu 0 e foi redirecionada', $pdo->query(
    "SELECT misspell_query AS digitou, query AS caiu_em, results AS resultados_do_destino, COUNT(*) n
     FROM mst_search_report_log
     WHERE misspell_query <> '' AND results > 0
     GROUP BY misspell_query, query, results ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC));
