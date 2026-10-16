<?php
/** BUG B — preco dos 10 (hipotese: a grade esconde produto sem preco). SOMENTE LEITURA. */
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
$in = implode(',', $todos);

dump('tabelas de preco existentes', $pdo->query("SHOW TABLES LIKE 'catalog_product_entity%price%'")->fetchAll(PDO::FETCH_NUM));

dump('preco (store 0) dos 10', $pdo->query(
    "SELECT e.entity_id, e.sku, pd.value AS price, pc.value AS cost,
            (SELECT COUNT(*) FROM catalog_product_entity_tier_price t WHERE t.entity_id = e.entity_id) AS tier_prices
     FROM catalog_product_entity e
     LEFT JOIN catalog_product_entity_decimal pd ON pd.entity_id=e.entity_id AND pd.store_id=0
        AND pd.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4)
     LEFT JOIN catalog_product_entity_decimal pc ON pc.entity_id=e.entity_id AND pc.store_id=0
        AND pc.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='cost' AND entity_type_id=4)
     WHERE e.entity_id IN ($in) ORDER BY e.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('1324: TODOS os atributos decimal/int/varchar (store 0)', $pdo->query(
    "SELECT 'decimal' AS tipo, a.attribute_code, d.value FROM catalog_product_entity_decimal d
       JOIN eav_attribute a ON a.attribute_id=d.attribute_id WHERE d.entity_id=1324 AND d.store_id=0
     UNION ALL
     SELECT 'int', a.attribute_code, CAST(i.value AS CHAR) FROM catalog_product_entity_int i
       JOIN eav_attribute a ON a.attribute_id=i.attribute_id WHERE i.entity_id=1324 AND i.store_id=0
     UNION ALL
     SELECT 'varchar', a.attribute_code, v.value FROM catalog_product_entity_varchar v
       JOIN eav_attribute a ON a.attribute_id=v.attribute_id WHERE v.entity_id=1324 AND v.store_id=0"
)->fetchAll(PDO::FETCH_ASSOC));

dump('tier price / preco por grupo (B2B) dos 10', $pdo->query(
    "SELECT t.entity_id, e.sku, t.all_groups, t.customer_group_id, t.qty, t.value, t.website_id
     FROM catalog_product_entity_tier_price t JOIN catalog_product_entity e ON e.entity_id=t.entity_id
     WHERE t.entity_id IN ($in) ORDER BY t.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('grupos de cliente', $pdo->query("SELECT customer_group_id, customer_group_code, tax_class_id FROM customer_group ORDER BY customer_group_id")->fetchAll(PDO::FETCH_ASSOC));

dump('1324: e um produto de catalogo (B2B) ou de vitrine? tax_class', $pdo->query(
    "SELECT e.entity_id, e.sku, tc.value AS tax_class_id FROM catalog_product_entity e
     LEFT JOIN catalog_product_entity_int tc ON tc.entity_id=e.entity_id AND tc.store_id=0
       AND tc.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='tax_class_id' AND entity_type_id=4)
     WHERE e.entity_id IN ($in) ORDER BY e.entity_id"
)->fetchAll(PDO::FETCH_ASSOC));
