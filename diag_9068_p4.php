<?php
// Diagnóstico P0.1 - Parte 4: b2b_customer, sync logs, entity_map, module code
// Somente leitura

require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "=== DIAGNOSTICO P0.1 - PARTE 4: B2B CUSTOMER E SYNC ===\n";
echo "Data: " . date('Y-m-d H:i:s T') . "\n\n";

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// 1. grupoawamotos_b2b_customer para customer_id=9068
echo "--- grupoawamotos_b2b_customer (customer_id=9068) ---\n";
try {
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_customer WHERE customer_id = 9068 LIMIT 5");
    echo "Registros: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 2. grupoawamotos_erp_entity_map para erp_code='26061'
echo "--- grupoawamotos_erp_entity_map (erp_code='26061') ---\n";
try {
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_erp_entity_map WHERE erp_code = '26061' LIMIT 10");
    echo "Registros: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. grupoawamotos_b2b_sectra_sync_log para customer_id=9068 (colunas corretas: log_id, event_type, level, customer_id, order_id, cnpj, sectra_chave, message, created_at)
echo "--- grupoawamotos_b2b_sectra_sync_log (customer_id=9068, ultimos 10) ---\n";
try {
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_sectra_sync_log WHERE customer_id = 9068 ORDER BY log_id DESC LIMIT 10");
    echo "Registros: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. grupoawamotos_b2b_sectra_poll_state
echo "--- grupoawamotos_b2b_sectra_poll_state ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_b2b_sectra_poll_state");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_sectra_poll_state LIMIT 5");
    echo "\nDados:\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Verificar todos os customers no estado de preço - quantos tem lista 45?
echo "--- grupoawamotos_erp_customer_price_state (resumo) ---\n";
try {
    $count = $connection->fetchOne("SELECT COUNT(*) FROM grupoawamotos_erp_customer_price_state");
    echo "Total de customers com price_state: $count\n";
    
    // Quantos tem price_list_code=45
    $count45 = $connection->fetchOne("SELECT COUNT(*) FROM grupoawamotos_erp_customer_price_state WHERE price_list_code = 45");
    echo "Customers com price_list_code=45: $count45\n";
    
    // Listar price_list_codes distintos
    $lists = $connection->fetchAll("SELECT price_list_code, COUNT(*) as cnt FROM grupoawamotos_erp_customer_price_state GROUP BY price_list_code ORDER BY price_list_code");
    echo "Distribuicao de price_list_code:\n";
    foreach ($lists as $row) {
        echo "  Lista " . $row['price_list_code'] . ": " . $row['cnt'] . " customers\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. Verificar vw_sectra_clientes_b2b - estrutura
echo "--- vw_sectra_clientes_b2b (estrutura e busca por 26061) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE vw_sectra_clientes_b2b");
    echo "Colunas:\n";
    foreach ($cols as $col) {
        echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\nPrimeiras 3 linhas:\n";
    $rows = $connection->fetchAll("SELECT * FROM vw_sectra_clientes_b2b LIMIT 3");
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Procurar o codigo que gera "Fail-closed: authorized price unavailable"
echo "--- BUSCA POR 'fail-closed' NOS MODULOS ---\n";
$moduleDir = BP . '/app/code/GrupoAwamotos';
$searchPaths = [
    $moduleDir . '/B2B',
    $moduleDir . '/ERPIntegration',
];

foreach ($searchPaths as $path) {
    if (!is_dir($path)) {
        echo "Diretorio nao encontrado: $path\n";
        continue;
    }
    
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        if (preg_match('/fail.?closed|authorized price unavailable|ErpQuotePrice/i', $content)) {
            echo "ARQUIVO: " . str_replace(BP . '/', '', $file->getPathname()) . "\n";
            // Extrair linhas relevantes
            $lines = explode("\n", $content);
            foreach ($lines as $i => $line) {
                if (preg_match('/fail.?closed|authorized price unavailable|ErpQuotePrice/i', $line)) {
                    echo "  L" . ($i+1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}

echo "\n=== FIM ===\n";
