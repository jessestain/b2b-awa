<?php
/**
 * task-115 — RECON (SOMENTE LEITURA).
 * 1) os 115 SKUs desativados com estoque no Magento
 * 2) quais tabelas de PRODUTO existem no ERP e quais colunas tem
 * NENHUMA escrita em nada. Conexao de LEITURA do ERP.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();

echo "############ 1) OS 115 SKUs (Magento) ############\n";
$skus = $conn->fetchAll(
    "SELECT e.sku, i.qty, e.entity_id
     FROM catalog_product_entity e
     JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
       AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
     JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
     WHERE s.value=2 AND i.qty>0
     ORDER BY i.qty DESC"
);
echo '  total: ' . count($skus) . "\n";
echo "  primeiros 12: ";
echo implode(', ', array_map(static fn($r) => $r['sku'] . '(' . (int) $r['qty'] . ')', array_slice($skus, 0, 12))) . "\n";
$naoNumericos = array_values(array_filter($skus, static fn($r) => !ctype_digit((string) $r['sku'])));
echo '  SKUs NAO numericos: ' . count($naoNumericos);
if ($naoNumericos) {
    echo ' -> ' . implode(', ', array_map(static fn($r) => $r['sku'], array_slice($naoNumericos, 0, 15)));
}
echo "\n";

echo "\n############ 2) TABELAS DE PRODUTO NO ERP ############\n";
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');

$tabelas = $erp->query(
    "SELECT TABLE_NAME, TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME LIKE '%PRODUTO%' OR TABLE_NAME LIKE '%PRODUCT%'
     ORDER BY TABLE_NAME"
);
echo '  encontradas: ' . count($tabelas) . "\n";
foreach ($tabelas as $t) {
    echo "    {$t['TABLE_NAME']} ({$t['TABLE_TYPE']})\n";
}
