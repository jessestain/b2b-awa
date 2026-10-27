<?php
// Diagnóstico P0.1 - Conta 9068 - Somente leitura
// Usa bootstrap do Magento para consultar customer data

require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$customerRegistry = $om->get(\Magento\Customer\Model\CustomerRegistry::class);
$groupRepository = $om->get(\Magento\Customer\Api\GroupRepositoryInterface::class);
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);

function getCustomer($customerRegistry, $groupRepository, $entityId) {
    try {
        $customer = $customerRegistry->retrieve($entityId);
        $groupId = $customer->getGroupId();
        try {
            $group = $groupRepository->getById($groupId);
            $groupName = $group->getCode();
        } catch (\Exception $e) {
            $groupName = 'N/A (erro: ' . $e->getMessage() . ')';
        }
        return [
            'entity_id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'group_id' => $groupId,
            'group_name' => $groupName,
            'store_id' => $customer->getStoreId(),
            'website_id' => $customer->getWebsiteId(),
            'is_active' => $customer->getIsActive(),
            'created_at' => $customer->getCreatedAt(),
        ];
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        return ['error' => 'Cliente ' . $entityId . ' nao encontrado'];
    } catch (\Exception $e) {
        return ['error' => 'Erro ao consultar cliente ' . $entityId . ': ' . $e->getMessage()];
    }
}

echo "=== DIAGNOSTICO P0.1 - CONTA 9068 ===\n";
echo "Data: " . date('Y-m-d H:i:s T') . "\n\n";

// Conta 9068 (alvo)
$acc9068 = getCustomer($customerRegistry, $groupRepository, 9068);
echo "--- CONTA 9068 (ALVO) ---\n";
if (isset($acc9068['error'])) {
    echo "ERRO: " . $acc9068['error'] . "\n";
} else {
    foreach ($acc9068 as $k => $v) {
        echo "$k: $v\n";
    }
}
echo "\n";

// Conta 9066 (referencia - foi encontrada no snapshot anterior)
$acc9066 = getCustomer($customerRegistry, $groupRepository, 9066);
echo "--- CONTA 9066 (REFERENCIA) ---\n";
if (isset($acc9066['error'])) {
    echo "ERRO: " . $acc9066['error'] . "\n";
} else {
    foreach ($acc9066 as $k => $v) {
        echo "$k: $v\n";
    }
}
echo "\n";

// Listar todos os grupos de cliente
echo "--- GRUPOS DE CLIENTE DISPONIVEIS ---\n";
$searchCriteria = $om->get(\Magento\Framework\Api\SearchCriteriaInterface::class);
$groups = $groupRepository->getList($searchCriteria);
foreach ($groups->getItems() as $group) {
    echo "ID=" . $group->getId() . " Code=" . $group->getCode() . " Tax=" . $group->getTaxClassId() . "\n";
}
echo "\n";

// Verificar tabelas B2B no banco
echo "--- TABELAS B2B NO BANCO ---\n";
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$dbName = $connection->getDatabaseName();
echo "Database: $dbName\n";

$tables = $connection->fetchAll("SHOW TABLES LIKE '%price_list%'");
echo "Tabelas price_list: " . (empty($tables) ? 'NENHUMA' : implode(', ', array_values($tables[0]))) . "\n";

$tables2 = $connection->fetchAll("SHOW TABLES LIKE '%b2b%'");
echo "Tabelas b2b: " . (empty($tables2) ? 'NENHUMA' : implode(', ', array_values($tables2[0]))) . "\n";

$tables3 = $connection->fetchAll("SHOW TABLES LIKE '%group_price%'");
echo "Tabelas group_price: " . (empty($tables3) ? 'NENHUMA' : implode(', ', array_values($tables3[0]))) . "\n";

$tables4 = $connection->fetchAll("SHOW TABLES LIKE '%erp%'");
echo "Tabelas erp: " . (empty($tables4) ? 'NENHUMA' : implode(', ', array_values($tables4[0]))) . "\n";

// Verificar customer_group_price se existir
$checkTable = $connection->fetchAll("SHOW TABLES LIKE 'customer_group_price'");
if (!empty($checkTable)) {
    echo "\n--- customer_group_price (primeiras 10 linhas) ---\n";
    $rows = $connection->fetchAll("SELECT * FROM customer_group_price LIMIT 10");
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
}

// Verificar se ha modulo customizado de B2B
echo "\n--- MODULOS B2B/PRICE/GROUP/QUOTE CUSTOMIZADOS ---\n";
$moduleList = $om->get(\Magento\Framework\Module\ModuleListInterface::class);
$allModules = $moduleList->getAll();
foreach ($allModules as $name => $module) {
    if (preg_match('/b2b|price|group|quote|erp|awamotos|rexis/i', $name)) {
        echo "Modulo: $name (status=" . ($module['status'] ?? 'unknown') . ")\n";
    }
}

echo "\n=== FIM DO DIAGNOSTICO ===\n";
