<?php
/**
 * task-55 — BACKFILL pontual: grava as linhas do mapa para os pedidos 'imported' que nao tem
 * linha em grupoawamotos_erp_entity_map.
 *
 * SOMENTE insere/atualiza grupoawamotos_erp_entity_map.
 * NAO altera pedido, status, pagamento, estoque nem o ERP (usa a conexao de LEITURA do ERP).
 * Idempotente. Transacional.
 *
 * Uso: php backfill-mapa.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$apply = in_array('--apply', $argv, true);
$OFFSET = 200000;

$rc = $om->get('Magento\\Framework\\App\\ResourceConnection');
$conn = $rc->getConnection();

$count = static function () use ($conn): int {
    return (int) $conn->fetchOne(
        "SELECT COUNT(*) FROM grupoawamotos_erp_entity_map WHERE entity_type='order'"
    );
};

echo "=== ANTES ===\n";
$antes = $count();
echo "linhas do mapa (entity_type='order'): $antes\n";

$rows = $conn->fetchAll(
    "SELECT so.entity_id, so.increment_id, so.state, so.sectra_import_status
     FROM sales_order so
     WHERE so.sectra_import_status = 'imported'
       AND NOT EXISTS (
           SELECT 1 FROM grupoawamotos_erp_entity_map m
           WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id
       )
     ORDER BY so.entity_id"
);
echo 'pedidos imported SEM mapa: ' . count($rows) . "\n";
foreach ($rows as $r) {
    printf("  id=%-4s inc=%-12s state=%-11s\n", $r['entity_id'], $r['increment_id'], $r['state']);
}

if ($rows === []) {
    echo "\nNada a fazer.\n";
    exit(0);
}

$ids = array_map(static fn(array $r): int => (int) $r['entity_id'], $rows);
$webIds = array_map(static fn(int $i): string => (string) ($i + $OFFSET), $ids);

echo "\n=== consulta ao ERP (CONEXAO DE LEITURA — nenhuma escrita) ===\n";
$erp = $om->get('GrupoAwamotos\\ERPIntegration\\Api\\ConnectionInterface');
$ph = implode(',', array_fill(0, count($webIds), '?'));
$erpRows = $erp->query(
    "SELECT p.CODIGO, p.PEDIDOWEB FROM VE_PEDIDO p WHERE p.PEDIDOWEB IN ($ph)",
    $webIds
);
echo 'linhas do ERP: ' . count($erpRows) . "\n";

$codeByWeb = [];
foreach ($erpRows as $r) {
    $w = trim((string) ($r['PEDIDOWEB'] ?? ''));
    $c = trim((string) ($r['CODIGO'] ?? ''));
    if ($w !== '' && $c !== '' && ctype_digit($c) && (int) $c > 0) {
        $codeByWeb[$w] = $c;
    }
}

$resolvidos = [];
$semCodigo = [];
foreach ($ids as $id) {
    $w = (string) ($id + $OFFSET);
    if (isset($codeByWeb[$w])) {
        $resolvidos[$id] = $codeByWeb[$w];
    } else {
        $semCodigo[] = $id;
    }
}
echo 'com CODIGO: ' . count($resolvidos) . ' | sem CODIGO no ERP: ' . count($semCodigo)
    . (($semCodigo) ? ' (' . implode(',', $semCodigo) . ')' : '') . "\n";

if ($resolvidos === []) {
    echo "\nABORTA: nenhum CODIGO resolvido; nada a inserir.\n";
    exit(1);
}

if (!$apply) {
    echo "\n=== DRY-RUN: as linhas que SERIAM inseridas ===\n";
    foreach ($resolvidos as $id => $code) {
        echo "  entity_type=order erp_code=$code magento_entity_id=$id\n";
    }
    echo "\nDRY-RUN: nada escrito.\n";
    exit(0);
}

echo "\n=== APPLY (transacional) ===\n";
$inseridos = 0;
$atualizados = 0;
$conn->beginTransaction();
try {
    $now = (new \DateTime())->format('Y-m-d H:i:s');
    foreach ($resolvidos as $id => $code) {
        $existing = $conn->fetchOne(
            "SELECT map_id FROM grupoawamotos_erp_entity_map WHERE entity_type='order' AND erp_code = ?",
            [$code]
        );
        if ($existing) {
            $conn->update(
                'grupoawamotos_erp_entity_map',
                ['magento_entity_id' => $id, 'last_sync_at' => $now],
                ['map_id = ?' => $existing]
            );
            $atualizados++;
        } else {
            $conn->insert('grupoawamotos_erp_entity_map', [
                'entity_type' => 'order',
                'erp_code' => $code,
                'magento_entity_id' => $id,
                'last_sync_at' => $now,
                'sync_hash' => null,
            ]);
            $inseridos++;
        }
    }
    $conn->commit();
    echo "COMMIT ok. inseridos=$inseridos atualizados=$atualizados\n";
} catch (\Throwable $e) {
    $conn->rollBack();
    echo 'ROLLBACK: ' . $e->getMessage() . "\n";
    exit(2);
}

echo "\n=== DEPOIS ===\n";
$depois = $count();
echo "linhas do mapa (entity_type='order'): $antes -> $depois (delta " . ($depois - $antes) . ")\n";

echo "\n=== pedidos imported ainda sem mapa ===\n";
$resto = $conn->fetchAll(
    "SELECT so.entity_id, so.increment_id, so.state FROM sales_order so
     WHERE so.sectra_import_status='imported'
       AND NOT EXISTS (SELECT 1 FROM grupoawamotos_erp_entity_map m
                       WHERE m.entity_type='order' AND m.magento_entity_id = so.entity_id)
     ORDER BY so.entity_id"
);
echo count($resto) . " restantes\n";
foreach ($resto as $r) {
    printf("  id=%-4s inc=%-12s state=%-11s\n", $r['entity_id'], $r['increment_id'], $r['state']);
}

echo "\n=== o consumidor passaria a ver estes pedidos? (query do getPendingOrdersForSync) ===\n";
$n = (int) $conn->fetchOne(
    "SELECT COUNT(*) FROM grupoawamotos_erp_entity_map em
     INNER JOIN sales_order so ON so.entity_id = em.magento_entity_id
     WHERE em.entity_type='order'
       AND so.state NOT IN ('complete','canceled','closed')
       AND em.erp_code REGEXP '^[0-9]+$'
       AND CAST(em.erp_code AS UNSIGNED) > 0"
);
echo "  pedidos elegiveis ao sync de status: $n\n";
