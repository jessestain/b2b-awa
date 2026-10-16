<?php
/**
 * task-72 — correcao de fidelidade: a consolidacao bumpou `last_occurrence` das 15 linhas
 * (a coluna e ON UPDATE CURRENT_TIMESTAMP). Restaura os valores originais do backup.
 * `last_occurrence` = quando o alerta foi VISTO pela ultima vez; a consolidacao nao e uma ocorrencia.
 * Nao toca status, occurrences, resolved_at, resolved_by nem notified_at.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();

$csv = end(glob($root . '/_backups/logalert-consolidacao-*/awa_log_alerts-afetadas.csv'));
echo "backup: $csv\n\n";

$fh = fopen($csv, 'r');
$cab = fgetcsv($fh, 0, ',', '"', '');
$orig = [];
while (($l = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $row = array_combine($cab, $l);
    $orig[(int) $row['entity_id']] = $row;
}
fclose($fh);

echo "ANTES da correcao (last_occurrence atual x original):\n";
foreach ($orig as $id => $row) {
    $atual = $conn->fetchOne("SELECT last_occurrence FROM awa_log_alerts WHERE entity_id = ?", [$id]);
    printf("  id=%-4s atual=%-20s original=%-20s %s\n", $id, $atual, $row['last_occurrence'],
        ($atual === $row['last_occurrence'] ? '' : '<- divergente'));
}

$conn->beginTransaction();
try {
    foreach ($orig as $id => $row) {
        $conn->update(
            'awa_log_alerts',
            ['last_occurrence' => $row['last_occurrence']],
            ['entity_id = ?' => $id]
        );
    }
    $conn->commit();
    echo "\nCOMMIT ok: last_occurrence restaurado em " . count($orig) . " linhas\n";
} catch (\Throwable $e) {
    $conn->rollBack();
    echo 'ROLLBACK: ' . $e->getMessage() . "\n";
    exit(2);
}

echo "\nDEPOIS:\n";
foreach ($orig as $id => $row) {
    $r = $conn->fetchRow("SELECT status, occurrences, last_occurrence, resolved_by, notified_at FROM awa_log_alerts WHERE entity_id = ?", [$id]);
    printf("  id=%-4s status=%-9s occ=%-3s last=%-20s notified=%-20s resolved_by=%s\n",
        $id, $r['status'], $r['occurrences'], $r['last_occurrence'], $r['notified_at'], $r['resolved_by']);
}
