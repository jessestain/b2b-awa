<?php
/** BUG B — por que 1324 (340 PR) e excluido da grade? SOMENTE LEITURA. */
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

$todos = [1626, 390, 406, 414, 423, 1324, 1333, 1334, 1813, 170];

dump('lojas/websites', $pdo->query("SELECT store_id, website_id, code, name FROM store ORDER BY store_id")->fetchAll(PDO::FETCH_ASSOC));
dump('websites', $pdo->query("SELECT website_id, code, name, is_default FROM store_website ORDER BY website_id")->fetchAll(PDO::FETCH_ASSOC));

dump('catalog_product_website dos 10', $pdo->query(
    "SELECT product_id, GROUP_CONCAT(website_id ORDER BY website_id) AS websites
     FROM catalog_product_website WHERE product_id IN (" . implode(',', $todos) . ")
     GROUP BY product_id ORDER BY product_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('cataloginventory_stock_status (indexado por website) dos 10', $pdo->query(
    "SELECT ss.product_id, ss.website_id, ss.stock_id, ss.qty, ss.stock_status
     FROM cataloginventory_stock_status ss WHERE ss.product_id IN (" . implode(',', $todos) . ")
     ORDER BY ss.product_id, ss.website_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('cataloginventory_stock_item (global) dos 10', $pdo->query(
    "SELECT product_id, stock_id, qty, is_in_stock, manage_stock, use_config_manage_stock
     FROM cataloginventory_stock_item WHERE product_id IN (" . implode(',', $todos) . ") ORDER BY product_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('1324: TODAS as linhas relevantes', $pdo->query(
    "SELECT e.entity_id, e.sku, e.type_id, e.attribute_set_id,
            (SELECT value FROM catalog_product_entity_int WHERE entity_id=e.entity_id AND store_id=0
              AND attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)) AS status,
            (SELECT value FROM catalog_product_entity_int WHERE entity_id=e.entity_id AND store_id=0
              AND attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='visibility' AND entity_type_id=4)) AS visibility
     FROM catalog_product_entity e WHERE e.entity_id=1324"
)->fetchAll(PDO::FETCH_ASSOC));

dump('1324 no catalogo: categorias', $pdo->query(
    "SELECT ccp.category_id, ccp.position FROM catalog_category_product ccp WHERE ccp.product_id=1324"
)->fetchAll(PDO::FETCH_ASSOC));

dump('config: mostrar fora de estoque?', $pdo->query(
    "SELECT path, value FROM core_config_data WHERE path IN ('cataloginventory/options/show_out_of_stock','cataloginventory/item_options/manage_stock','catalog/search/engine','catalog/frontend/list_allow_all')"
)->fetchAll(PDO::FETCH_ASSOC));

dump('os 9 que renderizam: status/visibilidade/stock_status website 1', $pdo->query(
    "SELECT e.entity_id, e.sku, e.type_id,
            s1.stock_status AS stock_w1, ss.website_id, ss.stock_status
     FROM catalog_product_entity e
     LEFT JOIN cataloginventory_stock_status ss ON ss.product_id = e.entity_id
     LEFT JOIN cataloginventory_stock_status s1 ON s1.product_id = e.entity_id AND s1.website_id = 1
     WHERE e.entity_id IN (" . implode(',', $todos) . ") ORDER BY e.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));
