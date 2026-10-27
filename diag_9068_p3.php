<?php
// Diagnóstico P0.1 - Parte 3: Preço B2B, ERP sync e módulo GrupoAwamotos_B2B
// Somente leitura

require __DIR__ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "=== DIAGNOSTICO P0.1 - PARTE 3: PRECO B2B E ERP ===\n";
echo "Data: " . date('Y-m-d H:i:s T') . "\n\n";

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// 1. b2b_customer_price - verificar se há preço para a conta 9068
echo "--- b2b_customer_price (estrutura) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE b2b_customer_price");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    $count = $connection->fetchOne("SELECT COUNT(*) FROM b2b_customer_price");
    echo "Total de linhas: $count\n";
    
    // Verificar se há registros para customer_id 9068
    $rows9068 = $connection->fetchAll("SELECT * FROM b2b_customer_price WHERE customer_id = 9068 LIMIT 10");
    echo "Registros para customer_id=9068: " . count($rows9068) . "\n";
    foreach ($rows9068 as $row) {
        echo "  " . json_encode($row) . "\n";
    }
    
    // Verificar se a tabela usa entity_id em vez de customer_id
    if (empty($rows9068)) {
        $rowsAlt = $connection->fetchAll("SELECT * FROM b2b_customer_price WHERE entity_id = 9068 LIMIT 10");
        echo "Registros para entity_id=9068: " . count($rowsAlt) . "\n";
        foreach ($rowsAlt as $row) {
            echo "  " . json_encode($row) . "\n";
        }
    }
    
    // Primeiras 5 linhas gerais
    echo "Primeiras 5 linhas da tabela:\n";
    $rows = $connection->fetchAll("SELECT * FROM b2b_customer_price LIMIT 5");
    foreach ($rows as $row) {
        echo "  " . json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 2. grupoawamotos_b2b_customer - dados B2B do cliente
echo "--- grupoawamotos_b2b_customer (dados B2B da conta 9068) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_b2b_customer");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
    
    // Buscar por customer_id ou entity_id
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_customer WHERE customer_id = 9068 OR entity_id = 9068 LIMIT 5");
    echo "Registros para 9068: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. grupoawamotos_erp_customer_price_state - estado de sincronização de preços
echo "--- grupoawamotos_erp_customer_price_state ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_erp_customer_price_state");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
    
    // Buscar por customer_id ou erp_code relacionado a 9068
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_erp_customer_price_state WHERE customer_id = 9068 OR erp_code = '26061' OR erp_code = 26061 LIMIT 10");
    echo "Registros para 9068/erp_code=26061: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. grupoawamotos_erp_price_sync_state - estado de sync de preços
echo "--- grupoawamotos_erp_price_sync_state ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_erp_price_sync_state");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
    
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_erp_price_sync_state WHERE customer_id = 9068 OR erp_code = '26061' OR erp_code = 26061 LIMIT 10");
    echo "Registros para 9068/erp_code=26061: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. grupoawamotos_erp_entity_map - mapeamento de entidades ERP
echo "--- grupoawamotos_erp_entity_map (para 9068) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_erp_entity_map");
    foreach ($cols as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
    
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_erp_entity_map WHERE customer_id = 9068 OR erp_code = '26061' OR erp_code = 26061 LIMIT 10");
    echo "Registros para 9068/erp_code=26061: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 6. vw_sectra_clientes_b2b - view de clientes B2B do Sectra/ERP
echo "--- vw_sectra_clientes_b2b (dados ERP para conta 9068) ---\n";
try {
    // Verificar se a view existe e tem dados
    $rows = $connection->fetchAll("SELECT * FROM vw_sectra_clientes_b2b WHERE cliente_codigo = '26061' OR cliente_codigo = 26061 OR codigo = '26061' OR codigo = 26061 LIMIT 5");
    echo "Registros para erp_code=26061: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
    
    if (empty($rows)) {
        // Tentar ver a estrutura da view
        $cols = $connection->fetchAll("DESCRIBE vw_sectra_clientes_b2b");
        echo "Colunas da view:\n";
        foreach ($cols as $col) {
            echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }
        // Tentar primeiras 3 linhas
        $rows = $connection->fetchAll("SELECT * FROM vw_sectra_clientes_b2b LIMIT 3");
        echo "Primeiras 3 linhas:\n";
        foreach ($rows as $row) {
            echo "  " . json_encode($row) . "\n";
        }
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. grupoawamotos_b2b_erp_alias - alias ERP
echo "--- grupoawamotos_b2b_erp_alias ---\n";
try {
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_erp_alias WHERE customer_id = 9068 OR erp_code = '26061' OR erp_code = 26061 LIMIT 5");
    echo "Registros para 9068/erp_code=26061: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
echo "\n";

// 8. grupoawamotos_b2b_sectra_sync_log - logs de sincronização
echo "--- grupoawamotos_b2b_sectra_sync_log (ultimos 5 para erp_code=26061) ---\n";
try {
    $cols = $connection->fetchAll("DESCRIBE grupoawamotos_b2b_sectra_sync_log");
    $colNames = array_column($cols, 'Field');
    echo "Colunas: " . implode(', ', $colNames) . "\n";
    
    // Buscar coluna que pode conter erp_code ou customer_id
    $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_sectra_sync_log WHERE erp_code LIKE '%26061%' OR customer_id = 9068 ORDER BY id DESC LIMIT 5");
    if (empty($rows)) {
        // Tentar sem filtro
        $rows = $connection->fetchAll("SELECT * FROM grupoawamotos_b2b_sectra_sync_log ORDER BY id DESC LIMIT 5");
    }
    echo "Últimos 5 registros:\n";
    foreach ($rows as $row) {
        echo json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
