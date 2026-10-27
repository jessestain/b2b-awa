<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Whatsapp;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use GrupoAwamotos\B2B\Model\ResourceModel\CreditLimit\CollectionFactory as CreditCollectionFactory;
use GrupoAwamotos\B2B\Model\WhatsApp\BotCommand;
use GrupoAwamotos\ERPIntegration\Helper\Data as ErpHelper;
use GrupoAwamotos\ERPIntegration\Model\WhatsApp\ZApiClient;
use Psr\Log\LoggerInterface;

/**
 * WhatsApp Webhook Controller
 *
 * Handles incoming messages from Z-API (WhatsApp Gateway)
 * Provides automated responses for B2B customers.
 */
class Webhook extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private JsonFactory $resultJsonFactory;
    private CustomerCollectionFactory $customerCollectionFactory;
    private OrderCollectionFactory $orderCollectionFactory;
    private CreditCollectionFactory $creditCollectionFactory;
    private ZApiClient $zapiClient;
    private ErpHelper $erpHelper;
    private LoggerInterface $logger;
    private StoreManagerInterface $storeManager;
    private \Magento\Eav\Model\Config $eavConfig;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CustomerCollectionFactory $customerCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
        CreditCollectionFactory $creditCollectionFactory,
        ZApiClient $zapiClient,
        ErpHelper $erpHelper,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        \Magento\Eav\Model\Config $eavConfig
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->creditCollectionFactory = $creditCollectionFactory;
        $this->zapiClient = $zapiClient;
        $this->erpHelper = $erpHelper;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->verifyWebhookToken()) {
            $this->logger->warning('[WhatsApp Bot] Webhook rejected: invalid or missing Client-Token');
            return $result->setHttpResponseCode(403)
                ->setData(['status' => 'forbidden']);
        }

        $payload = $this->getRequest()->getContent();
        $data = json_decode($payload, true);

        $bot = new BotCommand();
        if (!is_array($data) || $bot->shouldIgnore($data) || !isset($data['text']['message'])) {
            return $result->setData(['status' => 'ignored']);
        }

        // Só comando explícito (MENU, PEDIDOS, LIMITE). Conversa normal fica com
        // a equipe humana, que atende neste mesmo número.
        $command = $bot->parse((string) $data['text']['message']);
        if ($command === null) {
            return $result->setData(['status' => 'not_a_command']);
        }

        $from = (string) $data['phone'];
        $customer = $this->identifyCustomer($from);
        if (!$customer) {
            $response = 'Não encontrei um cadastro B2B ligado a este número. '
                . 'Nossa equipe vai te atender por aqui mesmo — é só escrever a sua dúvida.';
        } else {
            $response = match ($command) {
                BotCommand::ORDERS => $this->getOrderStatusResponse($customer),
                BotCommand::CREDIT => $this->getCreditLimitResponse($customer),
                default            => $this->getHelpResponse($customer),
            };
        }

        // 3. Send Response
        if ($response) {
            try {
                $this->zapiClient->sendTextMessage($from, $response);
            } catch (\Exception $e) {
                $this->logger->error('[WhatsApp Bot] Failed to send response: ' . $e->getMessage());
            }
        }

        return $result->setData(['status' => 'processed']);
    }

    /**
     * Identify customer by phone number.
     *
     * FIX (19/10/2026): a versao anterior filtrava por atributos EAV que NAO existem
     * no customer ("whatsapp"/"celular"/"telephone") -> LocalizedException 500 em todo
     * comando (PEDIDOS/LIMITE). Agora usamos apenas atributos existentes (b2b_phone etc.)
     * e procuramos nos dois formatos: digitos e formatado "(DD) XXXXX-XXXX".
     */
    private function identifyCustomer($phone)
    {
        if (empty($phone)) {
            return null;
        }

        // Clean phone (keep only digits)
        $cleanPhone = preg_replace('/\D/', '', $phone);
        // Remove country code if present (e.g. 55)
        if (str_starts_with($cleanPhone, '55')) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        if ($cleanPhone === '') {
            return null;
        }

        $patterns = ['%' . $cleanPhone . '%'];
        // Telefone B2B fica salvo formatado pelo B2bPhoneNormalizer: "(16) 99999-8888".
        $len = strlen($cleanPhone);
        if ($len === 11) {
            $patterns[] = sprintf('(%s) %s-%s', substr($cleanPhone, 0, 2), substr($cleanPhone, 2, 5), substr($cleanPhone, 7, 4));
        } elseif ($len === 10) {
            $patterns[] = sprintf('(%s) %s-%s', substr($cleanPhone, 0, 2), substr($cleanPhone, 2, 4), substr($cleanPhone, 6, 4));
        }

        $conditions = [];
        foreach (['b2b_phone', 'whatsapp', 'celular', 'telephone'] as $attributeCode) {
            if (!$this->customerAttributeExists($attributeCode)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                $conditions[] = ['attribute' => $attributeCode, 'like' => $pattern];
            }
        }

        if ($conditions === []) {
            $this->logger->warning('[WhatsApp Bot] Nenhum atributo de telefone disponivel no customer.');
            return null;
        }

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['firstname', 'lastname', 'group_id'])
            ->addAttributeToFilter($conditions);

        if ($collection->getSize() === 1) {
            return $collection->getFirstItem();
        }

        return null;
    }

    private function customerAttributeExists(string $attributeCode): bool
    {
        try {
            return $this->eavConfig->getAttribute(\Magento\Customer\Model\Customer::ENTITY, $attributeCode)->getId() !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get orders status response
     */
    private function getOrderStatusResponse($customer)
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customer->getId())
            ->setOrder('created_at', 'DESC')
            ->setPageSize(3);

        if ($collection->getSize() === 0) {
            // Os pedidos B2B entram pelo ERP (vendedor/balcão), não pelo site:
            // "você não possui pedidos" seria falso para quem compra todo mês.
            return "Olá {$customer->getFirstname()}! Não encontrei pedidos feitos pelo site. "
                . "Para pedidos feitos com o seu vendedor, a nossa equipe te passa o status por aqui — "
                . "é só escrever o número do pedido.";
        }

        $response = "Olá {$customer->getFirstname()}! Aqui estão seus últimos pedidos:\n\n";
        foreach ($collection as $order) {
            $status = $order->getStatusLabel();
            $total = number_format((float)$order->getGrandTotal(), 2, ',', '.');
            $date = date('d/m/Y', strtotime($order->getCreatedAt()));
            $response .= "📦 *#{$order->getIncrementId()}* ({$date})\n";
            $response .= "Status: *{$status}*\n";
            $response .= "Total: R$ {$total}\n\n";
        }
        $response .= "Acesse o portal para mais detalhes: " . $this->getDashboardUrl();

        return $response;
    }

    /**
     * Get credit limit response
     */
    private function getCreditLimitResponse($customer)
    {
        $collection = $this->creditCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customer->getId())
            ->setOrder('created_at', 'DESC')
            ->setPageSize(1);

        $credit = $collection->getFirstItem();
        if (!$credit || !$credit->getId()) {
            return "Olá {$customer->getFirstname()}, não encontramos informações de limite de crédito para sua conta.";
        }

        $limit = number_format((float)$credit->getCreditLimit(), 2, ',', '.');
        $used = number_format((float)$credit->getUsedCredit(), 2, ',', '.');
        $available = number_format((float)$credit->getCreditLimit() - (float)$credit->getUsedCredit(), 2, ',', '.');

        return "Olá {$customer->getFirstname()}! Seu status de crédito atual:\n\n"
            . "💳 *Limite Total:* R$ {$limit}\n"
            . "🔴 *Utilizado:* R$ {$used}\n"
            . "🟢 *Disponível:* R$ {$available}\n\n"
            . "Boas compras!";
    }

    /**
     * Build the B2B account dashboard URL using the current store's base URL.
     */
    private function getDashboardUrl(): string
    {
        try {
            return rtrim($this->storeManager->getStore()->getBaseUrl(), '/') . '/b2b/account/dashboard';
        } catch (\Exception $e) {
            $this->logger->error('[WhatsApp Bot] Failed to resolve store base URL: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get help response
     */
    private function getHelpResponse($customer)
    {
        return "Olá {$customer->getFirstname()}! Sou o assistente automático da AWA Motos. 🏍️\n\n"
            . "Envie só a palavra:\n"
            . "👉 *PEDIDOS* — status das suas compras pelo site\n"
            . "👉 *LIMITE* — seu limite de crédito\n\n"
            . "Para falar com a nossa equipe, é só escrever normalmente.";
    }

    /**
     * Valida a origem do webhook.
     *
     * Caminho 1 (preferido): headers Client-Token / X-Webhook-Token.
     * Caminho 2 (fallback): assinatura na URL — ?sig=sha256(client_token).
     * O Z-API NAO envia headers customizados nos webhooks e o painel nao permite
     * configura-los; a assinatura e derivada do token (hash inforjavel sem o segredo)
     * e nunca expoe o token em log/proxy/referer.
     */
    private function verifyWebhookToken(): bool
    {
        $expected = $this->erpHelper->getZApiClientToken();
        if ($expected === '') {
            $this->logger->warning('[WhatsApp Bot] Webhook rejected: ZAPI_CLIENT_TOKEN not configured');
            return false;
        }

        $request = $this->getRequest();
        $token = (string) $request->getHeader('Client-Token');
        if ($token === '') {
            $token = (string) $request->getHeader('X-Webhook-Token');
        }

        if ($token !== '') {
            return hash_equals($expected, $token);
        }

        // Fallback assinado na URL (Z-API nao suporta headers customizados em webhooks).
        // Aceita assinatura completa (sha256, 64 hex) OU prefixo de 16 hex — 64 bits de
        // entropia, suficiente e imune a limite de tamanho do campo de URL do painel.
        $sig = (string) $request->getParam('sig', '');
        if ($sig !== '') {
            $expectedSig = hash('sha256', $expected);
            if (hash_equals($expectedSig, $sig) || hash_equals(substr($expectedSig, 0, 16), $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
