<?php
/**
 * task-42 (E) — TESTE DE VERDADE: chama o METODO IMPLANTADO notifyAdminNewCustomer()
 * e verifica se o e-mail sai. Registra o tamanho do exception.log antes/depois.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$customerId = (int)($argv[1] ?? 9293);
$logBefore = $root . '/var/log/exception.log';
$sizeBefore = file_exists($logBefore) ? filesize($logBefore) : 0;
$mtimeBefore = file_exists($logBefore) ? filemtime($logBefore) : 0;

echo "exception.log ANTES: $sizeBefore bytes (mtime " . date('c', $mtimeBefore) . ")\n";

$approval = $om->get('GrupoAwamotos\\B2B\\Model\\CustomerApproval');
echo 'classe: ' . get_class($approval) . "\n";
echo "chamando notifyAdminNewCustomer($customerId) — o metodo REAL, sem simulacao...\n\n";

$t0 = microtime(true);
try {
    $ok = $approval->notifyAdminNewCustomer($customerId);
    $dt = round((microtime(true) - $t0) * 1000);
    echo ($ok ? "*** RESULTADO: TRUE — o e-mail foi ENVIADO ***\n" : "RESULTADO: FALSE — nao enviou (ver log)\n");
    echo "duracao: {$dt} ms\n";
} catch (\Throwable $e) {
    echo 'EXCECAO: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

clearstatcache(true, $logBefore);
$sizeAfter = file_exists($logBefore) ? filesize($logBefore) : 0;
echo "\nexception.log DEPOIS: $sizeAfter bytes\n";
echo 'cresceu? ' . (($sizeAfter > $sizeBefore) ? '*** SIM (+' . ($sizeAfter - $sizeBefore) . ' bytes) ***' : 'NAO (nenhuma excecao nova)') . "\n";

if ($sizeAfter > $sizeBefore) {
    $fh = fopen($logBefore, 'r');
    fseek($fh, $sizeBefore);
    echo "--- conteudo novo ---\n" . stream_get_contents($fh) . "\n";
    fclose($fh);
}

echo "\n=== ultimas linhas do debug.log (o catch registraria aqui) ===\n";
$dbg = $root . '/var/log/debug.log';
$lines = array_slice(file($dbg), -4);
foreach ($lines as $l) {
    echo '  ' . substr($l, 0, 220) . "\n";
}
