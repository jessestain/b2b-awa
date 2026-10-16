<?php
/**
 * task-42 — REPRODUZ a falha e TESTA a correcao, sem enviar e-mail.
 * getTransport() renderiza o template inteiro (e portanto resolve header/footer).
 * SOMENTE LEITURA (nao envia, nao grava).
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$customerId = (int)($argv[1] ?? 9293);
$store = $om->get('Magento\\Store\\Model\\StoreManagerInterface')->getStore(1);
$customer = $om->get('Magento\\Customer\\Api\\CustomerRepositoryInterface')->getById($customerId);

echo "cliente de teste: #{$customerId} {$customer->getEmail()}\n\n";

function tenta($om, $store, $customer, string $area, string $rotulo): void
{
    echo "=========== $rotulo  (area = $area)\n";
    try {
        $tb = $om->create('Magento\\Framework\\Mail\\Template\\TransportBuilder');
        $tb->setTemplateIdentifier('grupoawamotos_b2b_admin_new_customer')
            ->setTemplateOptions(['area' => $area, 'store' => $store->getId()])
            ->setTemplateVars([
                'customer' => $customer,
                'customer_name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                'customer_email' => $customer->getEmail(),
                'cnpj' => '00.000.000/0000-00',
                'razao_social' => 'EMPRESA DE TESTE',
                'phone' => '(11) 90000-0000',
                'store_name' => $store->getName(),
                'approval_url' => $store->getBaseUrl() . 'admin/customer/index',
            ])
            ->setFromByScope('general')
            ->addTo('auditoria.a11y.b2b@example.com');
        $transport = $tb->getTransport();
        echo "  RESULTADO: OK — o template renderizou (header/footer resolvidos)\n";
        echo '  classe do transporte: ' . get_class($transport) . "\n";
        $body = method_exists($transport, 'getMessage') ? (string)$transport->getMessage()->getBody() : '';
        echo '  tamanho do corpo renderizado: ' . strlen($body) . " bytes\n";
        if ($body !== '') {
            echo "  --- primeiros 300 caracteres ---\n  " . substr(preg_replace('/\s+/', ' ', $body), 0, 300) . "\n";
        }
    } catch (\Throwable $e) {
        echo '  RESULTADO: *** FALHOU *** ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

tenta($om, $store, $customer, 'adminhtml', 'ANTES (como o codigo esta hoje)');
tenta($om, $store, $customer, 'frontend', 'DEPOIS (area corrigida)');
