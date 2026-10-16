<?php
/**
 * task-55 — PROVA DIRETA do produtor novo (mesmo rigor da task-49):
 * invoca syncOrderEntityMap() por reflexao (o metodo privado real) e mede.
 * NAO altera status de pedido em momento algum.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$rc = $om->get('Magento\\Framework\\App\\ResourceConnection');
$conn = $rc->getConnection();

$cnt = static fn(): int => (int) $conn->fetchOne("SELECT COUNT(*) FROM grupoawamotos_erp_entity_map WHERE entity_type='order'");

echo "=== estado antes ===\n";
echo 'linhas do mapa (order): ' . $cnt() . "\n";
$estados = $conn->fetchAll("SELECT entity_id, state, status, sectra_import_status FROM sales_order WHERE entity_id IN (26,27,83,87,108) ORDER BY entity_id");
foreach ($estados as $e) {
    echo '  pedido ' . json_encode($e) . "\n";
}

$gate = $om->get('GrupoAwamotos\\B2B\\Model\\Sectra\\OrderImportGate');
$ref = new ReflectionMethod($gate, 'syncOrderEntityMap');
$ref->setAccessible(true);

echo "\n=== caso 1: ids SEM codigo no ERP (83, 87) — deve degradar com aviso, sem lancar ===\n";
try {
    $r = $ref->invoke($gate, [83, 87]);
    echo "  retorno = $r (esperado 0)\n";
} catch (\Throwable $e) {
    echo '  EXCECAO: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
echo '  linhas do mapa agora: ' . $cnt() . " (nao deve mudar)\n";

echo "\n=== caso 2: ids JA mapeados (26,27,28) — upsert idempotente (branch UPDATE) ===\n";
try {
    $r = $ref->invoke($gate, [26, 27, 28]);
    echo "  retorno = $r (esperado 3)\n";
} catch (\Throwable $e) {
    echo '  EXCECAO: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
echo '  linhas do mapa agora: ' . $cnt() . " (nao deve mudar — sao UPDATE, nao INSERT)\n";

echo "\n=== caso 3: lista vazia ===\n";
try {
    echo '  retorno = ' . var_export($ref->invoke($gate, []), true) . " (esperado 0)\n";
} catch (\Throwable $e) {
    echo '  EXCECAO: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n=== os pedidos NAO foram tocados? ===\n";
foreach ($conn->fetchAll("SELECT entity_id, state, status, sectra_import_status FROM sales_order WHERE entity_id IN (26,27,83,87,108) ORDER BY entity_id") as $e) {
    echo '  pedido ' . json_encode($e) . "\n";
}

echo "\n=== mapa final ===\n";
foreach ($conn->fetchAll("SELECT erp_code, magento_entity_id, last_sync_at FROM grupoawamotos_erp_entity_map WHERE entity_type='order' ORDER BY magento_entity_id") as $r) {
    echo '  ' . json_encode($r) . "\n";
}
echo 'total: ' . $cnt() . "\n";
