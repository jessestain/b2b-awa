<?php
/**
 * BUG B — total do engine (a toolbar) x IDs que a grade renderiza. SOMENTE LEITURA.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$termo = $argv[1] ?? '0047 PR';
$grade = array_map('strval', array_slice($argv, 2));   // IDs que a pagina renderizou

$criteria = $om->create('Magento\\Framework\\Api\\Search\\SearchCriteria');
$criteria->setRequestName('quick_search_container');
$filter = $om->get('Magento\\Framework\\Api\\FilterBuilder')->setField('search_term')->setValue($termo)->create();
$group = $om->create('Magento\\Framework\\Api\\Search\\FilterGroup');
$group->setFilters([$filter]);
$criteria->setFilterGroups([$group]);

$result = $om->get('Magento\\Search\\Api\\SearchInterface')->search($criteria);
echo "TERMO = '$termo'\n";
echo 'getTotalCount() do engine (o numero da toolbar) = ' . $result->getTotalCount() . "\n";
echo 'documentos devolvidos = ' . count($result->getItems()) . "\n\n";

$ids = [];
foreach ($result->getItems() as $item) {
    $id = (string)$item->getId();
    $ids[] = $id;
    $sku = '-';
    $attr = $item->getCustomAttribute('sku');
    if ($attr !== null) {
        $sku = (string)$attr->getValue();
    }
    $extra = [];
    foreach ($item->getCustomAttributes() as $ca) {
        if ($ca->getAttributeCode() !== 'sku') {
            $extra[] = $ca->getAttributeCode() . '=' . substr((string)$ca->getValue(), 0, 40);
        }
    }
    printf("  _id=%-8s sku=%-30s %s\n", $id, $sku, implode(' ', array_slice($extra, 0, 4)));
}
echo "\nIDs do engine: " . implode(',', $ids) . "\n";

if ($grade) {
    echo 'IDs da GRADE : ' . implode(',', $grade) . "\n";
    $faltantes = array_values(array_diff($ids, $grade));
    $sobra = array_values(array_diff($grade, $ids));
    echo 'no engine mas NAO na grade: ' . (($faltantes) ? implode(',', $faltantes) : '(nenhum)') . "\n";
    echo 'na grade mas NAO no engine: ' . (($sobra) ? implode(',', $sobra) : '(nenhum)') . "\n";

    if ($faltantes) {
        $conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
        echo "\n=== produto(s) faltante(s) no banco ===\n";
        foreach ($faltantes as $fid) {
            $rows = $conn->fetchAll(
                "SELECT e.entity_id, e.sku, e.type_id,
                        ast.value AS status, avis.value AS visibility, v.value AS name,
                        s.qty, s.is_in_stock,
                        (SELECT COUNT(*) FROM catalog_product_website w WHERE w.product_id=e.entity_id) AS sites
                 FROM catalog_product_entity e
                 LEFT JOIN catalog_product_entity_int ast ON ast.entity_id=e.entity_id AND ast.store_id=0
                    AND ast.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
                 LEFT JOIN catalog_product_entity_int avis ON avis.entity_id=e.entity_id AND avis.store_id=0
                    AND avis.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='visibility' AND entity_type_id=4)
                 LEFT JOIN catalog_product_entity_varchar v ON v.entity_id=e.entity_id AND v.store_id=0
                    AND v.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4)
                 LEFT JOIN cataloginventory_stock_item s ON s.product_id=e.entity_id
                 WHERE e.entity_id=" . (int)$fid
            );
            foreach ($rows as $r) {
                echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
}
