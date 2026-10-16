<?php
/**
 * task-49 — VALIDACAO POR MEDICAO.
 * Invoca AnalyzerPool::analyzeAll() — exatamente o caminho que PERSISTE alertas
 * (ErpAnalyzer::analyze -> generateErpAlerts -> createAndSaveAlert) — sem o resto do
 * grupo de cron e sem disparar notificacao.
 *
 * Uso: php dedupe-medir.php [n_de_rodadas]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$rodadas = (int)($argv[1] ?? 2);

$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function estado(PDO $pdo, string $rotulo): array
{
    $r = $pdo->query("SELECT COUNT(*) linhas, SUM(occurrences) soma, SUM(status='open') abertos,
                             MAX(entity_id) id_max, SUM(status='open' AND occurrences > 3) abertos_acima3
                      FROM awa_log_alerts")->fetch(PDO::FETCH_ASSOC);
    printf("%-26s linhas=%-4s soma_occ=%-4s abertos=%-3s id_max=%-4s abertos_occ>3=%s\n",
        $rotulo, $r['linhas'], $r['soma'], $r['abertos'], $r['id_max'], $r['abertos_acima3']);
    return $r;
}

echo "=== ALVO: alert_type=nosuchentity_spike, source=erp_analyzer ===\n\n";
$antes = estado($pdo, 'ANTES');

echo "\n=== o metodo novo resolve e responde? ===\n";
$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');
echo 'classe concreta: ' . get_class($repo) . "\n";
$alvo = $repo->getLatestOpenAlert('nosuchentity_spike', 'high', 'erp_analyzer');
if ($alvo === null) {
    echo "getLatestOpenAlert() => NULL (nao ha alerta aberto)\n";
} else {
    printf("getLatestOpenAlert() => id=%s occurrences=%s notified_at=%s last_occurrence=%s\n",
        $alvo->getEntityId(), $alvo->getOccurrences(), var_export($alvo->getData('notified_at'), true), $alvo->getLastOccurrence());
}
echo "getLatestOpenAlert('tipo_que_nao_existe','high','erp_analyzer') => "
    . var_export($repo->getLatestOpenAlert('tipo_que_nao_existe', 'high', 'erp_analyzer'), true) . "\n";

echo "\n=== prova de que o codigo NAO escreve notified_at ===\n";
if ($alvo !== null) {
    $antesNotified = $alvo->getData('notified_at');
    $alvo->setOccurrences(((int)$alvo->getOccurrences()) + 1);
    $alvo->setLastOccurrence(date('Y-m-d H:i:s'));
    echo '  (em memoria, sem salvar) notified_at antes=' . var_export($antesNotified, true)
        . ' depois=' . var_export($alvo->getData('notified_at'), true) . "\n";
    echo '  iguais? ' . var_export($antesNotified === $alvo->getData('notified_at'), true) . "\n";
    unset($alvo);
}
$grep = [];
exec('grep -rn "setData(.notified_at\|setNotifiedAt" ' . escapeshellarg($root . '/app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/') . ' ' . escapeshellarg($root . '/app/code/GrupoAwamotos/LogMonitoring/Model/AlertRepository.php') . ' 2>/dev/null', $grep);
echo '  setter de notified_at nos arquivos alterados: ' . (count($grep) ? implode(' | ', $grep) : 'NENHUM') . "\n";

$pool = $om->get('GrupoAwamotos\\LogMonitoring\\Service\\LogAnalyzer\\AnalyzerPool');

for ($i = 1; $i <= $rodadas; $i++) {
    echo "\n=== RODADA $i: AnalyzerPool::analyzeAll() ===\n";
    $res = $pool->analyzeAll();
    foreach ($res as $tipo => $r) {
        echo "  analyzer $tipo => " . (isset($r['error']) ? 'ERRO: ' . $r['error'] : 'ok (' . count($r) . ' arquivos)') . "\n";
    }
    estado($pdo, "DEPOIS da rodada $i");
}

echo "\n=== a linha que foi incrementada ===\n";
foreach ($pdo->query("SELECT entity_id, occurrences, status, notified_at, last_occurrence, first_occurrence, LEFT(message,70) msg
                      FROM awa_log_alerts WHERE alert_type='nosuchentity_spike' ORDER BY entity_id DESC LIMIT 3") as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== linhas NOVAS (id > id_max do inicio)? ===\n";
$novas = $pdo->query("SELECT COUNT(*) n FROM awa_log_alerts WHERE entity_id > " . (int)$antes['id_max'])->fetchColumn();
echo "  criadas durante a medicao: $novas\n";
echo "\n=== distribuicao final por status ===\n";
foreach ($pdo->query("SELECT status, COUNT(*) n, SUM(occurrences) soma FROM awa_log_alerts GROUP BY status") as $r) {
    echo "  {$r['status']} | {$r['n']} | {$r['soma']}\n";
}
