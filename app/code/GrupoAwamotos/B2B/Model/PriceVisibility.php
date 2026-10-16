<?php

/**
 * Price Visibility Service
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Model\PurchaseHistory;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class PriceVisibility implements PriceVisibilityInterface
{
    private const CONTEXT_APPROVAL_STATUS = 'b2b_approval_status';
    private const CONTEXT_CUSTOMER_ID = 'customer_id';
    private const CONTEXT_PRICE_LIST = 'erp_price_list';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var SyncLogResource
     */
    private $syncLogResource;

    /**
     * @var LoggerInterface
     */
    private $logger;
    private ?ErpCodeResolver $erpCodeResolver;
    private ?PurchaseHistory $purchaseHistory;
    private HttpContext $httpContext;
    private PhpCookieManager $cookieManager;

    /**
     * @var bool|null
     */
    private $canViewPricesCache = null;

    /**
     * @var bool|null
     */
    private $canAddToCartCache = null;

    /** @var int|null|false */
    private $erpCodeCache = false;

    public function __construct(
        Config $config,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        HttpContext $httpContext,
        UrlInterface $urlBuilder,
        PhpCookieManager $cookieManager,
        ?SyncLogResource $syncLogResource = null,
        ?LoggerInterface $logger = null,
        ?ErpCodeResolver $erpCodeResolver = null,
        ?PurchaseHistory $purchaseHistory = null
    ) {
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->httpContext = $httpContext;
        $this->urlBuilder = $urlBuilder;
        $this->cookieManager = $cookieManager;
        $this->syncLogResource = $syncLogResource;
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->erpCodeResolver = $erpCodeResolver;
        $this->purchaseHistory = $purchaseHistory;
    }

    /**
     * @inheritDoc
     */
    public function canViewPrices(): bool
    {
        if ($this->canViewPricesCache !== null) {
            return $this->canViewPricesCache;
        }

        // Se módulo desabilitado, mostrar preços
        if (!$this->config->isEnabled()) {
            $this->canViewPricesCache = true;
            $this->logDebug('canViewPrices', 'allowed_module_disabled');
            return true;
        }

        // Se usuário está logado
        if ($this->isLoggedInContext()) {
            // Usar repository para garantir que custom attributes EAV sejam carregados
            $approvalStatus = $this->getCustomerApprovalStatus();

            // Se não há status definido, considerar como aprovado (compatibilidade)
            if (empty($approvalStatus)) {
                $this->canViewPricesCache = true;
                $this->logDebug('canViewPrices', 'allowed_logged_in_without_status');
                return true;
            }

            // Cliente aprovado, mas ainda sem código ERP vinculado — tabela de preços
            // não está definida, então não deve ver preços (bug: este check estava
            // ausente, permitindo vazamento de preços para clientes não vinculados ao ERP).
            if ($approvalStatus === ApprovalStatus::STATUS_APPROVED) {
                if ($this->isApprovedCustomerMissingErp()) {
                    $this->canViewPricesCache = false;
                    $this->logDebug('canViewPrices', 'blocked_approved_missing_erp');
                    return false;
                }
                $this->canViewPricesCache = true;
                $this->logDebug('canViewPrices', 'allowed_approved_customer');
                return true;
            }

            // Cliente pendente - depende da configuração
            if ($approvalStatus === ApprovalStatus::STATUS_PENDING) {
                $this->canViewPricesCache = $this->config->showPriceForPending();
                $this->logDebug(
                    'canViewPrices',
                    $this->canViewPricesCache ? 'allowed_pending_by_config' : 'blocked_pending_by_config'
                );
                return $this->canViewPricesCache;
            }

            // Cliente rejeitado ou suspenso não vê preços
            $this->canViewPricesCache = false;
            $this->logDebug('canViewPrices', 'blocked_rejected_or_suspended');
            return false;
        }

        // Modo strict: visitantes NUNCA veem preços
        if ($this->config->isStrictB2B()) {
            $this->canViewPricesCache = false;
            $this->logDebug('canViewPrices', 'blocked_guest_strict_mode');
            return false;
        }

        // Modo mixed: visitante - depende da configuração
        $this->canViewPricesCache = !$this->config->hidePriceForGuests();
        $this->logDebug(
            'canViewPrices',
            $this->canViewPricesCache ? 'allowed_guest_mixed_mode' : 'blocked_guest_hidden_by_config'
        );
        return $this->canViewPricesCache;
    }

    /**
     * @inheritDoc
     */
    public function canAddToCart(): bool
    {
        if ($this->canAddToCartCache !== null) {
            return $this->canAddToCartCache;
        }

        // Se módulo desabilitado, permitir
        if (!$this->config->isEnabled()) {
            $this->canAddToCartCache = true;
            return true;
        }

        // Se usuário está logado
        if ($this->isLoggedInContext()) {
            if (!$this->isCustomerApproved()) {
                $this->canAddToCartCache = false;
                return false;
            }
            // Mesma regra de canViewPrices(): aprovado sem ERP vinculado não pode
            // comprar, pois não há tabela de preços/condições comerciais definidas.
            if ($this->isApprovedCustomerMissingErp()) {
                $this->canAddToCartCache = false;
                return false;
            }
            $this->canAddToCartCache = true;
            return true;
        }

        // Modo strict: visitantes NUNCA podem adicionar ao carrinho
        if ($this->config->isStrictB2B()) {
            $this->canAddToCartCache = false;
            return false;
        }

        // Modo mixed: visitante - depende da configuração
        $this->canAddToCartCache = !$this->config->hideAddToCartForGuests();
        return $this->canAddToCartCache;
    }

    /**
     * @inheritDoc
     */
    public function getPriceReplacementMessage(): string
    {
        if ($this->isLoggedInContext()) {
            $approvalStatus = $this->getCustomerApprovalStatus();

            // Aprovado mas sem código ERP — tabela de preços em definição
            if ($approvalStatus === ApprovalStatus::STATUS_APPROVED
                && $this->isApprovedCustomerMissingErp()
            ) {
                $msg = $this->config->getPendingErpMessage();
                return !empty($msg) ? $msg : 'Sua tabela de preços está sendo definida. Consulte o departamento de vendas.';
            }

            // FIX F-02 (2026-10-14): rejeitado/suspenso recebem mensagem própria com canal
            // de contato. Antes caíam na mensagem genérica de "conta em análise/será aprovada",
            // o que é falso para quem foi recusado ou bloqueado.
            if ($approvalStatus === ApprovalStatus::STATUS_REJECTED) {
                $rejectedMsg = $this->config->getRejectedMessage();
                if (!empty($rejectedMsg)) {
                    return $rejectedMsg;
                }
                return 'Seu cadastro B2B não foi aprovado. Fale com nosso time pelo WhatsApp (16) 99736-7588 para revisar seu caso.';
            }

            if ($approvalStatus === ApprovalStatus::STATUS_SUSPENDED) {
                $suspendedMsg = $this->config->getSuspendedMessage();
                if (!empty($suspendedMsg)) {
                    return $suspendedMsg;
                }
                return 'Sua conta B2B está suspensa. Fale com nosso time pelo WhatsApp (16) 99736-7588 para regularizar o acesso.';
            }

            // Pendente / revisão de cadastro
            if ($approvalStatus && $approvalStatus !== ApprovalStatus::STATUS_APPROVED) {
                $pendingMsg = $this->config->getPendingMessage();
                if (!empty($pendingMsg)) {
                    return $pendingMsg;
                }
                return 'Sua conta está pendente de aprovação.';
            }
        }

        $message = $this->config->getLoginMessage();

        if (empty($message)) {
            $message = '<a href="{{login_url}}">' . (string) __('Entrar') . '</a> '
                . (string) __('para ver os preços');
        }

        // Substituir placeholders
        $loginUrl = $this->config->isStrictB2B()
            ? $this->urlBuilder->getUrl('b2b/account/login')
            : $this->urlBuilder->getUrl('customer/account/login');
        $registerUrl = $this->config->isEnabled()
            ? $this->urlBuilder->getUrl('b2b/register')
            : $this->urlBuilder->getUrl('customer/account/create');

        $message = str_replace(
            ['{{login_url}}', '{{register_url}}'],
            [$loginUrl, $registerUrl],
            $message
        );

        // Guest PDP/PLP: CTA de cadastro real no HTML (não via CSS content).
        if (!$this->isLoggedInContext()
            && !preg_match('#/(?:b2b/register|customer/account/create)(?:/|"|\\s|>|\\?)#i', $message)
        ) {
            $safeRegisterUrl = htmlspecialchars($registerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $registerLabel = htmlspecialchars(
                (string) __('Novo cliente? Cadastre-se grátis →'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $message .= sprintf(
                ' <a href="%s" class="b2b-register-cta" style="background:transparent!important;color:var(--awa-primary,#b73337)!important;-webkit-text-fill-color:var(--awa-primary,#b73337)!important;text-decoration:underline!important">%s</a>',
                $safeRegisterUrl,
                $registerLabel
            );
        }

        return $message;
    }

    /**
     * @inheritDoc
     */
    public function isCustomerApproved(): bool
    {
        if (!$this->isLoggedInContext()) {
            return false;
        }

        $approvalStatus = $this->getCustomerApprovalStatus();
        if ($approvalStatus !== null) {
            // Fail-closed: sem status explícito não deve comprar como B2B.
            return $approvalStatus === ApprovalStatus::STATUS_APPROVED;
        }

        try {
            // Buscar dados atualizados diretamente do banco, não da sessão
            $customerId = $this->customerSession->getCustomerId();
            $customer = $this->customerRepository->getById($customerId);
            $approvalStatusAttr = $customer->getCustomAttribute('b2b_approval_status');
            $approvalStatus = $approvalStatusAttr ? $approvalStatusAttr->getValue() : null;

            // Fail-closed: sem status explícito não deve comprar como B2B.
            if (empty($approvalStatus)) {
                return false;
            }

            return $approvalStatus === ApprovalStatus::STATUS_APPROVED;
        } catch (\Exception $e) {
            // Fail-closed: erro ao consultar aprovação não deve liberar compra B2B.
            $this->logger->error('[B2B PriceVisibility] isCustomerApproved error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get customer approval status
     *
     * @return string|null
     */
    public function getCustomerApprovalStatus(): ?string
    {
        if (!$this->isLoggedInContext()) {
            return null;
        }

        $contextApprovalStatus = $this->getContextApprovalStatus();
        if ($contextApprovalStatus !== null) {
            return $contextApprovalStatus;
        }

        try {
            $customerId = $this->getCurrentCustomerId();
            if ($customerId <= 0) {
                return null;
            }
            $customer = $this->customerRepository->getById($customerId);
            $approvalStatusAttr = $customer->getCustomAttribute('b2b_approval_status');
            return $approvalStatusAttr ? $approvalStatusAttr->getValue() : null;
        } catch (\Exception $e) {
            $this->logger->error('[B2B PriceVisibility] getCustomerApprovalStatus error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function isApprovedPendingErp(): bool
    {
        if (!$this->isLoggedInContext()) {
            return false;
        }
        $status = $this->getCustomerApprovalStatus();
        return $status === ApprovalStatus::STATUS_APPROVED
            && $this->isApprovedCustomerMissingErp();
    }

    /**
     * Get customer ERP code from attribute or entity_map fallback
     */
    private function getCustomerErpCode(): ?int
    {
        if ($this->erpCodeCache !== false) {
            return $this->erpCodeCache;
        }

        if ($this->hasMissingErpContext()) {
            $this->erpCodeCache = null;
            return null;
        }

        try {
            $customerId = $this->getCurrentCustomerId();
            if ($customerId <= 0) {
                $this->erpCodeCache = null;
                return null;
            }
            $customer = $this->customerRepository->getById($customerId);

            if ($this->erpCodeResolver !== null) {
                $this->erpCodeCache = $this->erpCodeResolver->resolveForCustomerId($customerId, $customer);
                if ($this->erpCodeCache !== null) {
                    return $this->erpCodeCache;
                }
            }

            // Primary: erp_code attribute
            $attr = $customer->getCustomAttribute('erp_code');
            $erpCode = ($attr && $attr->getValue()) ? $attr->getValue() : null;

            // Fallback: entity_map table
            if ($erpCode === null && $this->syncLogResource !== null) {
                $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
            }

            if ($erpCode === null) {
                $erpCode = $this->resolveErpCodeByDocument($customer);
            }

            $this->erpCodeCache = ($erpCode !== null && is_numeric($erpCode)) ? (int) $erpCode : null;
        } catch (\Exception $e) {
            $this->logger->error('[B2B PriceVisibility] getCustomerErpCode error: ' . $e->getMessage());
            $this->erpCodeCache = null;
        }

        return $this->erpCodeCache;
    }

    private function resolveErpCodeByDocument(CustomerInterface $customer): ?int
    {
        if ($this->purchaseHistory === null) {
            return null;
        }

        $cnpj = '';

        $sessionCustomer = $this->customerSession->getCustomer();
        if ($sessionCustomer && (int) $sessionCustomer->getId() > 0) {
            $cnpj = (string) $sessionCustomer->getData('b2b_cnpj');
            if ($cnpj === '') {
                $cnpj = (string) ($sessionCustomer->getTaxvat() ?? '');
            }
        }

        if ($cnpj === '') {
            $cnpjAttr = $customer->getCustomAttribute('b2b_cnpj');
            $cnpj = is_object($cnpjAttr) && method_exists($cnpjAttr, 'getValue')
                ? (string) $cnpjAttr->getValue()
                : '';
            if ($cnpj === '') {
                $cnpj = (string) ($customer->getTaxvat() ?? '');
            }
        }

        if ($cnpj === '') {
            return null;
        }

        $erpCode = $this->purchaseHistory->getCustomerCodeByCnpj($cnpj);
        return ($erpCode !== null && is_numeric($erpCode)) ? (int) $erpCode : null;
    }

    /**
     * Clear cached values (useful after login/logout)
     */
    public function clearCache(): void
    {
        $this->canViewPricesCache = null;
        $this->canAddToCartCache = null;
        $this->erpCodeCache = false;
    }

    /**
     * Small structured debug log to help diagnose visibility decisions in production.
     */
    private function logDebug(string $operation, string $decision): void
    {
        $this->logger->info('[B2B PriceVisibility] decision', [
            'operation' => $operation,
            'decision' => $decision,
            'customer_id' => $this->getCurrentCustomerId(),
            'is_logged_in' => $this->isLoggedInContext(),
            'approval_status' => $this->getContextApprovalStatus(),
            'erp_price_list' => $this->httpContext->getValue(self::CONTEXT_PRICE_LIST),
        ]);
    }

    private function isLoggedInContext(): bool
    {
        $contextCustomerId = (int) $this->httpContext->getValue(self::CONTEXT_CUSTOMER_ID);
        $contextLoggedIn = $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH);

        // Context::setValue(CONTEXT_AUTH, false, false) removes the key when value===default,
        // so getValue returns null for guests even after HttpContextPlugin ran.
        if ($contextLoggedIn !== null) {
            return (bool) $contextLoggedIn || $contextCustomerId > 0;
        }

        // Null = guest (removed as value==default) OR context not yet populated.
        // Cookie check avoids session_start() for anonymous requests.
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return false;
        }

        return (bool) $this->customerSession->isLoggedIn();
    }

    private function getCurrentCustomerId(): int
    {
        $contextCustomerId = (int) $this->httpContext->getValue(self::CONTEXT_CUSTOMER_ID);
        if ($contextCustomerId > 0) {
            return $contextCustomerId;
        }
        // Avoid session_start() for guests — cookie check first.
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return 0;
        }
        return (int) $this->customerSession->getCustomerId();
    }

    private function getContextApprovalStatus(): ?string
    {
        $approvalStatus = $this->httpContext->getValue(self::CONTEXT_APPROVAL_STATUS);
        if (!is_string($approvalStatus) || $approvalStatus === '' || $approvalStatus === 'guest') {
            return null;
        }

        return $approvalStatus;
    }

    private function hasMissingErpContext(): bool
    {
        if (!$this->isLoggedInContext() || !$this->config->hidePriceForNoErp()) {
            return false;
        }

        return $this->httpContext->getValue(self::CONTEXT_PRICE_LIST) === 'logged_in';
    }

    /**
     * HOTFIX B2B-AUTH (13/10): regra explicita por cliente (REST/GraphQL).
     * Replica canAddToCart() sem depender da sessao/contexto do frontend.
     */
    public function canAddToCartForCustomer(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        if (!$this->config->isEnabled()) {
            return true;
        }
        try {
            $customer = $this->customerRepository->getById($customerId);
            $status = $customer->getCustomAttribute('b2b_approval_status');
            $status = $status ? (string) $status->getValue() : '';
            if ($status !== ApprovalStatus::STATUS_APPROVED) {
                return false;
            }
            if ($this->config->hidePriceForNoErp()
                && $this->erpCodeResolver !== null
                && $this->erpCodeResolver->resolveForCustomerId($customerId, $customer) === null
            ) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logDebug('canAddToCartForCustomer', 'error_' . $e->getMessage());
            return false;
        }
    }

    private function isApprovedCustomerMissingErp(): bool
    {
        $contextPriceList = $this->httpContext->getValue(self::CONTEXT_PRICE_LIST);

        if (!$this->config->hidePriceForNoErp()) {
            return false;
        }

        if ($this->isLoggedInContext() && is_string($contextPriceList) && $contextPriceList !== '' && $contextPriceList !== '0') {
            return $contextPriceList === 'logged_in';
        }

        return $this->getCustomerErpCode() === null;
    }
}
