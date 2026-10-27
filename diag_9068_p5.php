<?php
// Diagnóstico P0.1 - Parte 5: oc_customer_b2b_confirmed e ValidatorChecker
// Somente leitura

require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "=== DIAGNOSTICO P0.1 - PARTE 5: VALIDATOR CHECKER ===\n";
echo "Data: " . date('Y-m-d H:i:s T') . "\n\n";

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// 1. oc_customer_b2b_confirmed - tabela usada pelo ValidatorChecker
echo "--- oc_customer_b2b_confirmed (estrutura) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE oc_customer_b2b_confirmed");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";

    // Buscar customer_id=9068
    $rows = $connection->fetchAll("SELECT * FROM oc_customer_b2b_confirmed WHERE customer_id = 9068 LIMIT 5");
    echo "Registros para customer_id=9068: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }

    echo "\nTotal na tabela: " . $connection->fetchOne("SELECT COUNT(*) FROM oc_customer_b2b_confirmed") . "\n";

    // Ultimos 5 registrados
    echo "\nUltimos 5 customer_ids confirmados:\n";
    $rows2 = $connection->fetchAll("SELECT * FROM oc_customer_b2b_confirmed ORDER BY customer_id DESC LIMIT 5");
    foreach ($rows2 as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 2. Verificar grupoawamotos_b2b_customer para customer_id=9068
echo "--- grupoawamotos_b2b_customer (customer_id=9068) ---\n";
try {
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_customer WHERE customer_id = 9068 LIMIT 5");
    echo "Registros: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    }

    // Se vazio, listar um exemplo para entender a estrutura
    if (empty($rows)) {
        echo "\nNENHUM registro para 9068. Exemplo de registro existente:\n";
        $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_customer LIMIT 1");
        foreach ($rows as $row) {
            echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
        }
        echo "Total na tabela: " . $connection->fetchOne("SELECT COUNT(*) FROM grupoawamotos_b2b_customer") . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Verificar CustomerPriceProvider - getCustomerPriceListCode e getCustomerPrice
echo "--- TESTE: CustomerPriceProvider para conta 9068 ---\n";
try {
    $customerPriceProvider = $om->get(\GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider::class);

    // getCustomerPriceListCode
    $listCode = $customerPriceProvider->getCustomerPriceListCode(26061);
    echo "getCustomerPriceListCode(26061): " . var_export($listCode, true) . "\n";

    // getCustomerPrice para um SKU de teste
    // Pegar um SKU ativo
    $productCollection = $om->get(\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class)->create();
    $productCollection->addAttributeToSelect('sku')
        ->setPageSize(1)
        ->addAttributeToFilter('status', 1)
        ->addAttributeToFilter('visibility', ['neq' => 1]);
    $product = $productCollection->getFirstItem();
    if ($product->getId()) {
        $testSku = $product->getSku();
        echo "SKU de teste: $testSku\n";
        $price = $customerPriceProvider->getCustomerPrice(26061, $testSku);
        echo "getCustomerPrice(26061, $testSku): " . var_export($price, true) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Verificar ErpCodeResolver
echo "--- TESTE: ErpCodeResolver para customer 9068 ---\n";
try {
    $erpCodeResolver = $om->get(\GrupoAwamotos\B2B\Model\ErpCodeResolver::class);
    $erpCode = $erpCodeResolver->resolveForCustomerId(9068);
    echo "resolveForCustomerId(9068): " . var_export($erpCode, true) . "\n";
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Verificar se o modulo B2B esta habilitado
echo "--- CONFIG: B2B habilitado? ---\n";
try {
    $config = $om->get(\GrupoAwamotos\B2B\Helper\Config::class);
    echo "isEnabled(): " . var_export($config->isEnabled(), true) . "\n";
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. Default price list
echo "--- CONFIG: Default Price List ---\n";
try {
    $erpHelper = $om->get(\GrupoAwamotos\ERPIntegration\Helper\Data::class);
    echo "getDefaultPriceList(): " . var_export($erpHelper->getDefaultPriceList(), true) . "\n";
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
