<?php
/**
 * task-49 — PROVA DIRETA do caminho corrigido (o gatilho natural pode nao disparar).
 *
 * 1) mostra os numeros do analisador e se o limiar (>10) foi atingido;
 * 2) invoca o METODO PRIVADO createAndSaveAlert por reflexao, 2x, com o tipo real
 *    -> com o codigo ANTIGO isso criaria 2 linhas novas;
 *    -> com o codigo NOVO deve incrementar occurrences da mesma linha.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function estado(PDO $pdo, string $rot): void
{
    $r = $pdo->query("SELECT COUNT(*) l, SUM(occurrences) s, MAX(entity_id) m FROM awa_log_alerts")->fetch(PDO::FETCH_ASSOC);
    $a = $pdo->query("SELECT occurrences FROM awa_log_alerts WHERE entity_id = 157")->fetchColumn();
    printf("%-22s linhas=%s soma_occ=%s id_max=%s occurrences_do_157=%s\n", $rot, $r['l'], $r['s'], $r['m'], var_export($a, true));
}

echo "=== 1) por que o gatilho natural nao disparou? ===\n";
/** @var \GrupoAwamotos\LogMonitoring\Service\LogAnalyzer\ErpAnalyzer $erp */
$erp = $om->get('GrupoAwamotos\\LogMonitoring\\Service\\LogAnalyzer\\ErpAnalyzer');
$analysis = $erp->analyze();
foreach ($analysis as $file => $data) {
    if (isset($data['error'])) {
        echo "  $file => ERRO: {$data['error']}\n";
        continue;
    }
    printf("  %-14s nosuchentity_errors=%s  (limiar do alerta: >10)  sync_errors=%s\n",
        $file, $data['erp_specific']['nosuchentity_errors'] ?? '?', $data['erp_specific']['sync_errors'] ?? '?');
}
estado($pdo, 'apos analyze()');

echo "\n=== 2) PROVA DIRETA: createAndSaveAlert() por reflexao ===\n";
$ref = new ReflectionMethod($erp, 'createAndSaveAlert');
$ref->setAccessible(true);

estado($pdo, 'ANTES');

for ($i = 1; $i <= 2; $i++) {
    $ref->invoke($erp, 'nosuchentity_spike', 'high', 'High NoSuchEntityException Count',
        "chamada direta de teste task-49 #$i", ['file' => 'exception.log', 'count' => 64, 'teste' => $i]);
    estado($pdo, "DEPOIS da chamada $i");
}

echo "\n=== 3) a linha 157 ===\n";
foreach ($pdo->query("SELECT entity_id, occurrences, status, notified_at, last_occurrence, message
                      FROM awa_log_alerts WHERE entity_id = 157") as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== 4) linhas novas (id > 157)? ===\n";
echo '  ' . $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE entity_id > 157")->fetchColumn() . "\n";

echo "\n=== 5) o AlertProcessor continua vendo a mesma linha (nao duplicou)? ===\n";
$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');
$a = $repo->getLatestOpenAlert('nosuchentity_spike', 'high', 'erp_analyzer');
echo '  getLatestOpenAlert() => id=' . $a->getEntityId() . ' occurrences=' . $a->getOccurrences()
    . ' notified_at=' . var_export($a->getData('notified_at'), true) . "\n";

echo "\n=== 6) estado final ===\n";
foreach ($pdo->query("SELECT status, COUNT(*) n, SUM(occurrences) soma FROM awa_log_alerts GROUP BY status") as $r) {
    echo "  {$r['status']} | {$r['n']} | {$r['soma']}\n";
}
