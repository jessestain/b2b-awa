<?php
/** task-55 — metrica "pending orders": mapa x sectra_import_status. Somente leitura. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$viaMapa = (int) $pdo->query(
    "SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')
     AND entity_id NOT IN (SELECT magento_entity_id FROM grupoawamotos_erp_entity_map WHERE entity_type='order')"
)->fetchColumn();

$viaStatus = (int) $pdo->query(
    "SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')
     AND COALESCE(sectra_import_status,'') <> 'imported'"
)->fetchColumn();

$total = (int) $pdo->query("SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')")->fetchColumn();

echo "pedidos em new/pending_payment/processing: $total\n";
echo "  'pending orders' pela METRICA ATUAL (ausencia no mapa)   = $viaMapa\n";
echo "  'pending orders' por sectra_import_status <> 'imported'  = $viaStatus\n";

echo "\n=== detalhe por sectra_import_status (nesses estados) ===\n";
foreach ($pdo->query(
    "SELECT COALESCE(sectra_import_status,'(NULL)') st, COUNT(*) n,
            SUM(EXISTS(SELECT 1 FROM grupoawamotos_erp_entity_map m WHERE m.entity_type='order' AND m.magento_entity_id=so.entity_id)) no_mapa
     FROM sales_order so WHERE so.state IN ('new','pending_payment','processing')
     GROUP BY sectra_import_status ORDER BY n DESC"
) as $r) {
    echo "  {$r['st']} | n={$r['n']} | com mapa={$r['no_mapa']}\n";
}
