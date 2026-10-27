<?php
// Diagnóstico P0.1 - Parte 2: Módulos B2B, ERP, preço e tabelas
// Somente leitura

require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "=== DIAGNOSTICO P0.1 - PARTE 2: MODULOS E TABELAS ===\n";
echo "Data: " . date('Y-m-d H:i:s T') . "\n\n";

// Listar módulos B2B/Price/ERP customizados
echo "--- MODULOS B2B/PRICE/ERP/QUOTE CUSTOMIZADOS ---\n";
$moduleList = $om->get(\Magento\Framework\Module\ModuleListInterface::class);
$allModules = $moduleList->getAll();
foreach ($allModules as $name => $module) {
    if (preg_match('/b2b|price|group|quote|erp|awamotos|rexis/i', $name)) {
        echo "Modulo: $name (status=" . ($module['status'] ?? 'unknown') . ")\n";
    }
}
echo "\n";

// Verificar tabelas no banco
echo "--- TABELAS RELACIONADAS A PRECO/ERP NO BANCO ---\n";
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

$patterns = ['%price_list%', '%b2b%', '%group_price%', '%erp%', '%rexis%', '%awamotos%'];
foreach ($patterns as $pattern) {
    try {
        $tables = $connection->fetchAll("SHOW TABLES LIKE '$pattern'");
        if (!empty($tables)) {
            foreach ($tables as $t) {
                $tableName = array_values($t)[0];
                echo "Tabela encontrada: $tableName\n";
                // Se for uma tabela de price_list, mostrar primeiras linhas
                if (strpos($tableName, 'price_list') !== false) {
                    $count = $connection->fetchOne("SELECT COUNT(*) FROM `$tableName`");
                    echo "  Linhas: $count\n";
                    $rows = $connection->fetchAll("SELECT * FROM `$tableName` LIMIT 5");
                    foreach ($rows as $row) {
                        echo "  " . json_encode($row) . "\n";
                    }
                }
            }
        }
    } catch (\Exception $e) {
        echo "Erro ao buscar $pattern: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// Verificar customer_entity para a conta 9068 - atributos customizados
echo "--- ATRIBUTOS CUSTOMIZADOS DA CONTA 9068 ---\n";
try {
    $customerRegistry = $om->get(\Magento\Customer\Model\CustomerRegistry::class);
    $customer = $customerRegistry->retrieve(9068);
    
    // Verificar atributos comuns de B2B
    $attrsToCheck = [
        'lista_preco', 'price_list_id', 'b2b_price_list', 'erp_price_list',
        'b2b_list', 'erp_list', 'customer_price_list', 'group_price_list',
        'lista', 'price_list', 'b2b_status', 'erp_code', 'erp_customer_code',
        'b2b_approved', 'company_id', 'b2b_company', 'customer_number'
    ];
    
    foreach ($attrsToCheck as $attr) {
        $value = $customer->getData($attr);
        if ($value !== null) {
            echo "$attr: $value\n";
        }
    }
    
    // Também listar todos os atributos não-nulos do customer
    echo "\n--- TODOS OS ATRIBUTOS NAO-NULOS DA CONTA 9068 ---\n";
    $data = $customer->getData();
    foreach ($data as $key => $val) {
        if ($val !== null && $val !== '' && $key !== 'password_hash') {
            echo "$key: $val\n";
        }
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
