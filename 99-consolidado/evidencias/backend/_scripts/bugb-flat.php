<?php
/** BUG B — flat: 1324 esta na tabela? SOMENTE LEITURA. */
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

dump('colunas de catalog_product_flat_1 (nome + tipo), so as relevantes', $pdo->query(
    "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='catalog_product_flat_1'
       AND COLUMN_NAME IN ('entity_id','sku','status','visibility','name','price','type_id','required_options')
     ORDER BY COLUMN_NAME"
)->fetchAll(PDO::FETCH_ASSOC));

$todos = [1626, 390, 406, 414, 423, 1324, 1333, 1334, 1813, 170];
$in = implode(',', $todos);

foreach ([1, 2] as $store) {
    $tab = "catalog_product_flat_$store";
    dump("$tab: os 10 (entity_id, sku, status, visibility)", $pdo->query(
        "SELECT entity_id, sku, visibility FROM `$tab` WHERE entity_id IN ($in) ORDER BY entity_id"
    )->fetchAll(PDO::FETCH_ASSOC));
    $presentes = array_column($pdo->query("SELECT entity_id FROM `$tab` WHERE entity_id IN ($in)")->fetchAll(PDO::FETCH_ASSOC), 'entity_id');
    $faltam = array_values(array_diff($todos, array_map('intval', $presentes)));
    echo 'AUSENTES no flat store ' . $store . ': ' . (($faltam) ? implode(',', $faltam) : '(nenhum)') . "\n";
    $n1324 = (int)$pdo->query("SELECT COUNT(*) FROM `$tab` WHERE entity_id=1324")->fetchColumn();
    echo "1324 presente em $tab? " . ($n1324 ? 'SIM' : 'NAO') . "\n";
}

dump('total de linhas por flat', $pdo->query(
    "SELECT 'flat_1' t, COUNT(*) n FROM catalog_product_flat_1 UNION ALL SELECT 'flat_2', COUNT(*) FROM catalog_product_flat_2"
)->fetchAll(PDO::FETCH_ASSOC));

dump('flat_cl (changelog pendente)', $pdo->query("SELECT * FROM catalog_product_flat_cl LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));
dump('flat_cl total', $pdo->query("SELECT COUNT(*) n FROM catalog_product_flat_cl")->fetchAll(PDO::FETCH_ASSOC));

dump('indexer_state', $pdo->query(
    "SELECT indexer_id, status, updated FROM indexer_state
     WHERE indexer_id IN ('catalog_product_flat','catalogsearch_fulltext','cataloginventory_stock','catalog_product_price')"
)->fetchAll(PDO::FETCH_ASSOC));
