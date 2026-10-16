<?php
/**
 * task-67 — PARTE 1 (corrigida): sendOrFail() por reflexao.
 * O script anterior estava errado: $casos era o proprio retorno, nao o par [retorno, esperaThrow].
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$consumer = $om->get('GrupoAwamotos\\B2B\\Model\\Notification\\WhatsAppConsumer');
$ref = new ReflectionMethod($consumer, 'sendOrFail');
$ref->setAccessible(true);

/* cada caso: [ rotulo, retorno do servico, deve lancar? ] */
$casos = [
    ['folha OK — sendText/notifyCustomerApproval', ['success' => true, 'message' => 'ok'], false],
    ['folha RECUSADA — Z-API / telefone invalido', ['success' => false, 'message' => 'Invalid phone number'], true],
    ['folha RECUSADA — WhatsApp desabilitado', ['success' => false, 'message' => 'WhatsApp desabilitado'], true],
    ['LISTA 2x OK — notifyTeam (new_registration/new_quote)', [['success' => true], ['success' => true]], false],
    ['LISTA 1 OK + 1 RECUSA — notifyTeam parcial', [['success' => true], ['success' => false, 'message' => 'Z-API 400']], true],
    ['ANINHADO team OK + customer OK — notifyNewOrder', ['team' => [['success' => true]], 'customer' => ['success' => true]], false],
    ['ANINHADO team OK + customer RECUSA', ['team' => [['success' => true]], 'customer' => ['success' => false, 'message' => 'Invalid phone number']], true],
    ['ANINHADO team OK + customer VAZIO (sem telefone)', ['team' => [['success' => true]], 'customer' => []], false],
    ['VAZIO — nenhum numero configurado', [], true],
];

$ok = 0;
foreach ($casos as [$rotulo, $retorno, $esperaThrow]) {
    try {
        $ref->invoke($consumer, 'send_text', $retorno, ['phone' => 'teste']);
        $lancou = false;
        $msgErro = '';
    } catch (\Throwable $e) {
        $lancou = true;
        $msgErro = $e->getMessage();
    }
    $passou = ($lancou === $esperaThrow);
    $ok += $passou ? 1 : 0;
    printf("%s  %-56s lanca=%-5s esperado=%-5s%s\n",
        $passou ? 'PASS' : 'FAIL', $rotulo, var_export($lancou, true), var_export($esperaThrow, true),
        ($lancou ? '  <- ' . substr($msgErro, 0, 55) : ''));
}

echo "\nPASSOU $ok de " . count($casos) . "\n";
exit($ok === count($casos) ? 0 : 1);
