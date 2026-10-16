<?php
/**
 * task-55 — REQUISITO 3: parar de usar o mapa como metrica de "pedido no ERP".
 *
 * CustomerPullManagement (health check "pending_orders") contava pedidos SEM linha no mapa.
 * O mapa e o VINCULO DE SYNC, nao a metrica — quem marca "esta no ERP" e sectra_import_status.
 * Medido antes: mapa=8, sectra_import_status=7 (a diferenca era o pedido 83, 'imported' sem mapa).
 *
 * Uso: php fix-metrica.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/ERPIntegration/Model/Api/CustomerPullManagement.php';
$apply = in_array('--apply', $argv, true);
$bakDir = $root . '/_backups/erp-map-reconcile-' . gmdate('Ymd\THis\Z');

$anchor = <<<'TXT'
            $pendingOrders = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')
                 AND entity_id NOT IN (SELECT magento_entity_id FROM grupoawamotos_erp_entity_map WHERE entity_type='order')"
            );
TXT;

$novo = <<<'TXT'
            /* task-55 (11/10/2026): a metrica era "nao tem linha no mapa". O mapa e o VINCULO DE
               SYNC de status, nao a marca de importacao — quem diz "esta no ERP" e
               sectra_import_status, e os dois divergiam (medido: mapa=8 x status=7). */
            $pendingOrders = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')
                 AND COALESCE(sectra_import_status, '') <> 'imported'"
            );
TXT;

$src = file_get_contents($target);
if (substr_count($src, $anchor) !== 1) {
    $a2 = str_replace("\n", "\r\n", $anchor);
    if (substr_count($src, $a2) === 1) {
        $anchor = $a2;
        $novo = str_replace("\n", "\r\n", $novo);
    }
}
$n = substr_count($src, $anchor);
echo "ancoras exatas = $n\n";
echo 'construtores antes = ' . substr_count($src, 'function __construct') . "\n";
if ($n !== 1) {
    fwrite(STDERR, "ABORTA: esperava 1 ancora, achei $n\n");
    exit(2);
}

$new = str_replace($anchor, $novo, $src);
file_put_contents('/tmp/metrica.new.php', $new);
file_put_contents('/tmp/metrica.old.php', $src);

$out = [];
exec('php -l /tmp/metrica.new.php 2>&1', $out, $rc);
echo 'php -l (novo) => rc=' . $rc . ' :: ' . implode(' | ', $out) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: nao passa no lint; nada escrito\n");
    exit(3);
}

echo "\n--- DIFF ---\n";
system('diff -u /tmp/metrica.old.php /tmp/metrica.new.php | head -25');

if (!$apply) {
    echo "\nDRY-RUN: nada escrito.\n";
    exit(0);
}

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
    fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
    exit(4);
}
copy($target, $bakDir . '/CustomerPullManagement.php.orig');
file_put_contents($bakDir . '/ANTES-metrica.txt', sprintf(
    "arquivo: %s\nsha256: %s\nbytes: %d\n",
    $target, hash_file('sha256', $target), filesize($target)
));

file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nBACKUP = $bakDir\n";
echo 'APLICADO: sha256 ' . hash_file('sha256', $target) . ' | bytes=' . filesize($target)
    . ' | dono/grupo/modo=' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
echo 'construtores depois = ' . substr_count(file_get_contents($target), 'function __construct') . "\n";
