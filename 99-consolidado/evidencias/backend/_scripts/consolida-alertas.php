<?php
/**
 * task-72 — CONSOLIDAÇÃO do passivo de alertas.
 *
 * 15 linhas `open` do MESMO alert_type/severity/source = UM incidente.
 * Criterio:
 *   - chave do grupo: (alert_type, severity, source) com status='open' e COUNT(*) > 1
 *   - canonica: MAX(entity_id)  [deterministico; mesmo criterio do dedupe da task-49,
 *     que escolhe last_occurrence DESC, entity_id DESC]
 *   - a canonica recebe a SOMA das occurrences do grupo (preserva o total real)
 *   - TODAS as linhas do grupo viram 'resolved' (com resolved_at/resolved_by)
 *
 * POR QUE RESOLVER TAMBEM A CANONICA (e nao deixa-la 'open'):
 *   o AlertProcessor so notifica `status='open' AND occurrences <= 3`. A canonica ficaria com
 *   occurrences=17 (> 3) => silencio permanente. E o dedupe da task-49 casa por status='open',
 *   entao uma RECORRENCIA incrementaria essa mesma linha para sempre, sem nunca notificar —
 *   criariamos uma falha silenciosa nova. Resolvendo todas, a proxima recorrencia nao encontra
 *   alerta aberto => nasce UMA linha nova (occurrences=1, notified_at NULL) => UMA notificacao.
 *
 * Backup das linhas afetadas em _backups/logalert-consolidacao-<UTC>/.
 * Uso: php consolida-alertas.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$apply = in_array('--apply', $argv, true);
$MARKER = 'task-72-consolidacao';
$bakDir = $root . '/_backups/logalert-consolidacao-' . gmdate('Ymd\THis\Z');

$rc = $om->get('Magento\\Framework\\App\\ResourceConnection');
$conn = $rc->getConnection();
$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');

echo "=== ANTES ===\n";
foreach ($conn->fetchAll(
    "SELECT alert_type, severity, source, status, COUNT(*) n, SUM(occurrences) occ
     FROM awa_log_alerts GROUP BY alert_type, severity, source, status ORDER BY n DESC"
) as $r) {
    printf("  %-22s | %-8s | %-16s | %-12s | n=%-4s occ=%s\n",
        $r['alert_type'], $r['severity'], $r['source'], $r['status'], $r['n'], $r['occ']);
}

$grupos = $conn->fetchAll(
    "SELECT alert_type, severity, source, COUNT(*) n, SUM(occurrences) occ, MAX(entity_id) canonica
     FROM awa_log_alerts
     WHERE status = 'open'
     GROUP BY alert_type, severity, source
     HAVING COUNT(*) > 1
     ORDER BY n DESC"
);

echo "\n=== GRUPOS DUPLICADOS (open, mesmo tipo/severidade/fonte) ===\n";
if ($grupos === []) {
    echo "  (nenhum — nada a consolidar)\n";
    exit(0);
}

$plano = [];
foreach ($grupos as $g) {
    $ids = $conn->fetchCol(
        "SELECT entity_id FROM awa_log_alerts
         WHERE status='open' AND alert_type=? AND severity=? AND source=? ORDER BY entity_id",
        [$g['alert_type'], $g['severity'], $g['source']]
    );
    $plano[] = ['grupo' => $g, 'ids' => $ids];
    printf("  %s | %s | %s : %d linhas (ids %s..%s) occ_total=%s -> canonica id=%s recebe %s\n",
        $g['alert_type'], $g['severity'], $g['source'], $g['n'],
        min($ids), max($ids), $g['occ'], $g['canonica'], $g['occ']);
}

// -------- BACKUP das linhas afetadas
$todosIds = [];
foreach ($plano as $p) {
    $todosIds = array_merge($todosIds, array_map('intval', $p['ids']));
}
$todosIds = array_values(array_unique($todosIds));
$in = implode(',', $todosIds);

$linhas = $conn->fetchAll("SELECT * FROM awa_log_alerts WHERE entity_id IN ($in) ORDER BY entity_id");
if ($apply) {
    if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
        fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
        exit(1);
    }
    $sql = "-- backup task-72 ($MARKER) — " . gmdate('c') . "\n";
    foreach ($linhas as $l) {
        $cols = array_keys($l);
        $vals = array_map(static function ($v) {
            return $v === null ? 'NULL' : "'" . str_replace("'", "''", (string) $v) . "'";
        }, array_values($l));
        $sql .= "INSERT INTO `awa_log_alerts` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
    }
    file_put_contents($bakDir . '/awa_log_alerts-afetadas.sql', $sql);
    $fh = fopen($bakDir . '/awa_log_alerts-afetadas.csv', 'w');
    fputcsv($fh, array_keys($linhas[0]));
    foreach ($linhas as $l) {
        fputcsv($fh, $l);
    }
    fclose($fh);
    file_put_contents($bakDir . '/PLANO.txt', print_r($plano, true));
    echo "\nBACKUP: $bakDir (" . count($linhas) . " linhas)\n";
}

if (!$apply) {
    echo "\nDRY-RUN: nada alterado. ($MARKER)\n";
    exit(0);
}

// -------- CONSOLIDA (transacional)
echo "\n=== APPLY ===\n";
$conn->beginTransaction();
try {
    foreach ($plano as $p) {
        $canonica = (int) $p['grupo']['canonica'];
        $soma = (int) $p['grupo']['occ'];

        $alert = $repo->getById($canonica);
        $alert->setOccurrences($soma);
        $repo->save($alert);

        foreach ($p['ids'] as $id) {
            if ((int) $id === $canonica) {
                continue;
            }
            $repo->resolveAlert((int) $id, $MARKER);
        }
        $repo->resolveAlert($canonica, $MARKER);
        printf("  grupo consolidado: canonica=%d occurrences=%d, %d linhas resolvidas\n",
            $canonica, $soma, count($p['ids']));
    }
    $conn->commit();
    echo "COMMIT ok\n";
} catch (\Throwable $e) {
    $conn->rollBack();
    echo 'ROLLBACK: ' . $e->getMessage() . "\n";
    exit(2);
}

echo "\n=== DEPOIS ===\n";
foreach ($conn->fetchAll(
    "SELECT alert_type, severity, source, status, COUNT(*) n, SUM(occurrences) occ
     FROM awa_log_alerts GROUP BY alert_type, severity, source, status ORDER BY n DESC"
) as $r) {
    printf("  %-22s | %-8s | %-16s | %-12s | n=%-4s occ=%s\n",
        $r['alert_type'], $r['severity'], $r['source'], $r['status'], $r['n'], $r['occ']);
}
echo "\ncanonica final:\n";
foreach ($conn->fetchAll("SELECT entity_id, status, occurrences, resolved_by, resolved_at, notified_at FROM awa_log_alerts WHERE resolved_by = ?", [$MARKER]) as $r) {
    echo '  ' . json_encode($r) . "\n";
}
echo "BACKUP=$bakDir\n";
