<?php
/**
 * BUG B — reproduz o off-by-one usando a MESMA colecao da pagina de busca.
 * SOMENTE LEITURA.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$termo = $argv[1] ?? '0047 PR';

echo 'classe Layer\\Search existe? ' . var_export(class_exists('Magento\\CatalogSearch\\Model\\Layer\\Search'), true) . "\n";
foreach (glob($root . '/vendor/magento/module-catalog-search/Model/Layer/*') as $f) {
    echo '  Layer/: ' . basename($f) . (is_dir($f) ? '/' : '') . "\n";
}

$store = $om->get('Magento\\Store\\Model\\StoreManagerInterface')->getStore(1);
$col = $om->get('Magento\\CatalogSearch\\Model\\ResourceModel\\Fulltext\\CollectionFactory')->create();
$col->setStore($store);
$col->addSearchFilter($termo);

$size = $col->getSize();
echo "\nTERMO = '$termo'\n";
echo "getSize() (toolbar) = $size\n";

$ids = $col->getAllIds();
echo 'getAllIds() (o que o engine devolveu) = ' . count($ids) . ' -> ' . implode(',', $ids) . "\n";

$col->clear();
$col->setStore($store);
$col->addSearchFilter($termo);
$col->setPageSize(60)->setCurPage(1);
$loaded = [];
foreach ($col as $p) {
    $loaded[$p->getId()] = $p;
}
echo 'CARREGADOS (grade) = ' . count($loaded) . "\n";
echo 'DIFF = ' . ($size - count($loaded)) . "\n\n";

echo "faltantes (no engine, nao carregados): ";
$faltantes = array_diff($ids, array_keys($loaded));
echo ($faltantes ? implode(',', $faltantes) : '(nenhum)') . "\n\n";

echo "itens carregados:\n";
foreach ($loaded as $id => $p) {
    printf("  id=%-7s sku=%-30s status=%-3s vis=%-3s tipo=%-12s nome=%s\n",
        $id, $p->getSku(), $p->getStatus(), $p->getVisibility(), $p->getTypeId(), mb_substr((string)$p->getName(), 0, 45));
}

if ($faltantes) {
    $conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
    echo "\n=== o(s) produto(s) faltante(s) no banco ===\n";
    foreach ($faltantes as $fid) {
        $r = $conn->fetchAll(
            "SELECT e.entity_id, e.sku, e.type_id, a_status.value AS status, a_vis.value AS visibility,
                    s.qty, s.is_in_stock,
                    (SELECT COUNT(*) FROM catalog_product_website w WHERE w.product_id = e.entity_id) AS websites
             FROM catalog_product_entity e
             LEFT JOIN catalog_product_entity_int a_status ON a_status.entity_id = e.entity_id AND a_status.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4) AND a_status.store_id = 0
             LEFT JOIN catalog_product_entity_int a_vis ON a_vis.entity_id = e.entity_id AND a_vis.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='visibility' AND entity_type_id=4) AND a_vis.store_id = 0
             LEFT JOIN cataloginventory_stock_item s ON s.product_id = e.entity_id
             WHERE e.entity_id = " . (int)$fid
        );
        foreach ($r as $row) {
            echo '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
