<?php
/** task-39 — estado de midia de um SKU (antes/depois do teste). Leitura pura. */
declare(strict_types=1);
$DOC = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $DOC . '/app/etc/env.php';
$db = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['dbname']), $db['username'], $db['password']);
$skus = array_slice($argv, 1);
$rot = getenv('ROTULO') ?: 'estado';
echo "== {$rot} (" . gmdate('Y-m-d H:i:s') . " UTC) ==\n";
foreach ($skus as $sku) {
    $eid = (int) $pdo->query("SELECT entity_id FROM catalog_product_entity WHERE sku=" . $pdo->quote($sku))->fetchColumn();
    if (!$eid) { echo "  SKU {$sku}: NAO ENCONTRADO\n"; continue; }
    echo "  SKU {$sku} (entity {$eid})\n";
    foreach (['image', 'small_image', 'thumbnail'] as $code) {
        $v = $pdo->query("SELECT v.value FROM catalog_product_entity_varchar v JOIN eav_attribute a ON a.attribute_id=v.attribute_id
                          WHERE v.entity_id=$eid AND a.attribute_code='$code' AND v.store_id=0")->fetchColumn();
        printf("     %-12s = %s\n", $code, $v === false ? '(sem linha)' : ($v === '' ? '(vazio)' : $v));
    }
    $g = $pdo->query("SELECT g.value_id, g.value FROM catalog_product_entity_media_gallery g
                      JOIN catalog_product_entity_media_gallery_value_to_entity gve ON gve.value_id=g.value_id
                      WHERE gve.entity_id=$eid ORDER BY g.value_id")->fetchAll(PDO::FETCH_ASSOC);
    printf("     galeria      = %d linha(s)%s\n", count($g), $g ? ' -> ' . implode(', ', array_map(fn($x) => $x['value_id'] . ':' . $x['value'], $g)) : '');
}

