<?php
/**
 * task-67 — PROVA DIRIGIDA.
 *  Parte 1: sendOrFail() por reflexao, com os 3 formatos de retorno do WhatsAppService + os casos de borda.
 *  Parte 2: publica UMA mensagem de teste (send_text, telefone VAZIO -> 'Invalid phone number', SEM chamar a API
 *           e SEM falar com cliente) para provar o caminho end-to-end ate o status da fila.
 *  Parte 3: stub de sucesso (subclasse anonima) -> process() NAO lanca.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$consumer = $om->get('GrupoAwamotos\\B2B\\Model\\Notification\\WhatsAppConsumer');
$ref = new ReflectionMethod($consumer, 'sendOrFail');
$ref->setAccessible(true);

echo "############ PARTE 1 — sendOrFail() com os formatos REAIS do WhatsAppService ############\n\n";

$casos = [
    'folha OK (sendText/notifyCustomerApproval)'        => ['success' => true, 'message' => 'ok'],
    'folha RECUSADA (Z-API / telefone invalido)'        => ['success' => false, 'message' => 'Invalid phone number'],
    'folha RECUSADA (desabilitado)'                     => ['success' => false, 'message' => 'WhatsApp desabilitado'],
    'LISTA 2x OK (notifyTeam de new_registration)'      => [['success' => true], ['success' => true]],
    'LISTA 1 OK + 1 RECUSA (notifyTeam parcial)'        => [['success' => true], ['success' => false, 'message' => 'Z-API 400']],
    'ANINHADO team OK + customer OK (notifyNewOrder)'   => ['team' => [['success' => true]], 'customer' => ['success' => true]],
    'ANINHADO team OK + customer RECUSA'                => ['team' => [['success' => true]], 'customer' => ['success' => false, 'message' => 'Invalid phone number']],
    'ANINHADO team OK + customer VAZIO (sem telefone)'  => ['team' => [['success' => true]], 'customer' => []],
    'VAZIO (nenhum numero configurado)'                 => [],
];

$ok = 0;
foreach ($casos as $rotulo => [$retorno, $esperaThrow]) {
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
    printf("%s  %-52s lanca=%s esperado=%s%s\n",
        $passou ? 'PASS' : 'FAIL', $rotulo, var_export($lancou, true), var_export($esperaThrow, true),
        ($lancou ? '  <- ' . substr($msgErro, 0, 60) : ''));
}
echo "\nPASSOU $ok de " . count($casos) . "\n";

echo "\n############ PARTE 3 — stub de SUCESSO: process() NAO lanca ############\n";
$logger = $om->get('Psr\\Log\\LoggerInterface');
$svcOk = new class(
    $om->get('GrupoAwamotos\\SmartSuggestions\\Api\\WhatsappSenderInterface'),
    $om->get('Magento\\Framework\\App\\Config\\ScopeConfigInterface'),
    $logger
) extends \GrupoAwamotos\B2B\Model\Notification\WhatsAppService {
    public function sendText(string $phone, string $message): array
    {
        return ['success' => true, 'message' => 'stub de teste task-67'];
    }
};
$msgOk = $om->get('GrupoAwamotos\\B2B\\Api\\Data\\WhatsAppMessageInterfaceFactory')->create();
$msgOk->setType('send_text')->setPayload((string) json_encode(['phone' => '11999999999', 'message_text' => 'teste']));
try {
    (new \GrupoAwamotos\B2B\Model\Notification\WhatsAppConsumer($svcOk, $logger))->process($msgOk);
    echo "PASS  envio OK -> process() completou SEM lancar (a mensagem segue para COMPLETE)\n";
} catch (\Throwable $e) {
    echo 'FAIL  envio OK lancou: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "\n############ PARTE 2 — mensagem de TESTE publicada na fila real ############\n";
$publisher = $om->get('GrupoAwamotos\\B2B\\Model\\Notification\\WhatsAppPublisher');
$publisher->publishText('', 'teste task-67 — nao deve chegar a ninguem');
echo "publicada: tipo=send_text, phone='' (vazio)\n";
echo "esperado: o sender devolve ['success'=>false,'message'=>'Invalid phone number'] SEM chamar a API\n";
echo "          -> sendOrFail lanca -> o MQ marca ERROR (6) em vez de COMPLETE (4)\n";

$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$row = $conn->fetchRow(
    "SELECT qm.id AS message_id, qms.id AS status_id, qms.status, qms.number_of_trials, LEFT(qm.body,110) AS body
     FROM queue_message qm
     JOIN queue_message_status qms ON qms.message_id = qm.id
     JOIN queue q ON q.id = qms.queue_id
     WHERE q.name = 'grupoawamotos.b2b.whatsapp.send'
     ORDER BY qm.id DESC LIMIT 1"
);
echo "\nna fila agora:\n  " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
echo "  (status=2 = NEW; depois de consumir deve virar 6 = ERROR)\n";
echo "\nANOTE: status_id=" . $row['status_id'] . " message_id=" . $row['message_id'] . "\n";
