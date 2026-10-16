<?php
/**
 * task-72 — PROVA DIRIGIDA.
 *
 * (1) Um lote de N linhas do mesmo tipo gera 1 notificacao, nao N.
 *     Avalia a MESMA condicao do AlertProcessor (getCriticalAlerts() REAL + status==='open'
 *     + occurrences <= 3 + cooldown de 6h por notified_at) sobre:
 *       - o estado ANTES (as 15 linhas, reconstruidas do backup)
 *       - o estado DEPOIS (real)
 * (2) Um problema NOVO continua gerando alerta.
 *     Dentro de uma TRANSACAO REVERTIDA (rollback): insere um alerta sintetico severity=high,
 *     status=open, occurrences=1, notified_at=NULL e mostra que o AlertProcessor o veria (1
 *     candidato) e que o dedupe o encontraria (2a ocorrencia incrementaria, nao duplicaria).
 *     ROLLBACK no fim: nada persiste => nenhum e-mail real pode sair.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$COOLDOWN = 6 * 3600;
$rc = $om->get('Magento\\Framework\\App\\ResourceConnection');
$conn = $rc->getConnection();
$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');

/** replica isWithinNotificationCooldown() do AlertProcessor */
$emCooldown = static function ($notifiedAt) use ($COOLDOWN): bool {
    if (empty($notifiedAt)) {
        return false;
    }
    $ts = strtotime((string) $notifiedAt);
    return $ts !== false && (time() - $ts) < $COOLDOWN;
};

/** conta quantos alertas o AlertProcessor NOTIFICARIA agora (usa o metodo REAL getCriticalAlerts) */
$contarNotificaveis = static function (bool $simularCooldownExpirado) use ($repo, $emCooldown): array {
    $detalhe = [];
    foreach ($repo->getCriticalAlerts() as $a) {
        if ($a->getStatus() !== 'open' || $a->getOccurrences() > 3) {
            continue;
        }
        $notifiedAt = $a->getData('notified_at');
        if (!$simularCooldownExpirado && $emCooldown($notifiedAt)) {
            continue;
        }
        $detalhe[] = $a->getEntityId();
    }
    return $detalhe;
};

echo "############ (1) N linhas do mesmo tipo => 1 notificacao ############\n\n";

$backup = glob($root . '/_backups/logalert-consolidacao-*/awa_log_alerts-afetadas.csv');
$antes = [];
if ($backup) {
    $fh = fopen(end($backup), 'r');
    $cab = fgetcsv($fh);
    while (($l = fgetcsv($fh)) !== false) {
        $antes[] = array_combine($cab, $l);
    }
    fclose($fh);
}
$antesElegiveis = [];
foreach ($antes as $row) {
    if ($row['status'] === 'open' && (int) $row['occurrences'] <= 3) {
        $antesElegiveis[] = $row['entity_id'];
    }
}
echo 'ANTES  (do backup, ' . count($antes) . " linhas):\n";
echo '  linhas open com occurrences <= 3 (elegiveis no proximo ciclo pos-cooldown): '
    . count($antesElegiveis) . ' -> ids ' . implode(',', $antesElegiveis) . "\n";
echo '  => notificavam ' . count($antesElegiveis) . " e-mails por janela de 6h\n\n";

$depoisCiclo = $contarNotificaveis(true);
$depoisAgora = $contarNotificaveis(false);
echo "DEPOIS (real, via getCriticalAlerts() do proprio servico):\n";
echo '  notifyaveis AGORA (cooldown valendo): ' . count($depoisAgora)
    . ($depoisAgora ? ' -> ids ' . implode(',', $depoisAgora) : '') . "\n";
echo '  notifyaveis no proximo ciclo (cooldown expirado): ' . count($depoisCiclo)
    . ($depoisCiclo ? ' -> ids ' . implode(',', $depoisCiclo) : '') . "\n";
echo '  => ' . count($antesElegiveis) . ' -> ' . count($depoisCiclo) . " notificacoes\n\n";

echo "############ (2) problema NOVO continua gerando alerta (transacao revertida) ############\n";
$conn->beginTransaction();
try {
    $conn->insert('awa_log_alerts', [
        'alert_type' => 'teste_task72_prova',
        'severity' => 'high',
        'title' => 'PROVA task-72 (nao persistir)',
        'message' => 'alerta sintetico dentro de transacao revertida',
        'context_data' => '{"teste":true}',
        'source' => 'erp_analyzer',
        'status' => 'open',
        'occurrences' => 1,
        'first_occurrence' => date('Y-m-d H:i:s'),
        'last_occurrence' => date('Y-m-d H:i:s'),
        'notified_at' => null,
    ]);
    $novoId = (int) $conn->lastInsertId('awa_log_alerts');
    echo "  alerta sintetico inserido (id=$novoId, severity=high, occurrences=1, notified_at=NULL)\n";

    $visiveis = $contarNotificaveis(false);
    $achou = in_array((int) $novoId, array_map('intval', $visiveis), true);
    echo '  o AlertProcessor o veria? ' . var_export($achou, true) . ' (candidatos agora: ' . count($visiveis) . ")\n";

    $dedupe = $repo->getLatestOpenAlert('teste_task72_prova', 'high', 'erp_analyzer');
    echo '  o dedupe o encontra (2a ocorrencia incrementaria em vez de duplicar)? '
        . var_export($dedupe !== null && (int) $dedupe->getEntityId() === $novoId, true) . "\n";

    $c = (int) $conn->fetchOne("SELECT COUNT(*) FROM awa_log_alerts WHERE alert_type='teste_task72_prova'");
    echo "  linhas do tipo sintetico dentro da transacao: $c\n";
} finally {
    $conn->rollBack();
    echo "  ROLLBACK executado.\n";
}

$persistiu = (int) $conn->fetchOne("SELECT COUNT(*) FROM awa_log_alerts WHERE alert_type='teste_task72_prova'");
echo '  linhas do tipo sintetico APOS o rollback: ' . $persistiu . " (esperado 0)\n";

echo "\n############ estado final ############\n";
foreach ($conn->fetchAll(
    "SELECT alert_type, severity, source, status, COUNT(*) n, SUM(occurrences) occ
     FROM awa_log_alerts GROUP BY alert_type, severity, source, status ORDER BY n DESC"
) as $r) {
    printf("  %-22s | %-8s | %-16s | %-12s | n=%-4s occ=%s\n",
        $r['alert_type'], $r['severity'], $r['source'], $r['status'], $r['n'], $r['occ']);
}
