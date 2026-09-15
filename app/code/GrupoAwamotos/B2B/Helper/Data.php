<?php

/**
 * B2B Helper Data
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Helper;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\ScopeInterface;
use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Catalog\Model\Product;

class Data extends AbstractHelper
{
    /**
     * Config paths
     */
    public const XML_PATH_ENABLED = 'grupoawamotos_b2b/general/enabled';
    public const XML_PATH_B2B_MODE = 'grupoawamotos_b2b/general/b2b_mode';
    public const XML_PATH_HIDE_PRICES = 'grupoawamotos_b2b/price_visibility/hide_price_guests';
    public const XML_PATH_REQUIRE_APPROVAL = 'grupoawamotos_b2b/customer_approval/require_approval';
    public const XML_PATH_QUOTE_ENABLED = 'grupoawamotos_b2b/quote_request/enabled';
    public const XML_PATH_QUOTE_EXPIRY = 'grupoawamotos_b2b/quote_request/expiry_days';

    // Order Approval
    public const XML_PATH_ORDER_APPROVAL_ENABLED = 'grupoawamotos_b2b/order_approval/enabled';
    public const XML_PATH_THRESHOLD_MANAGER = 'grupoawamotos_b2b/order_approval/threshold_manager';
    public const XML_PATH_THRESHOLD_FINANCE = 'grupoawamotos_b2b/order_approval/threshold_finance';
    public const XML_PATH_THRESHOLD_DIRECTOR = 'grupoawamotos_b2b/order_approval/threshold_director';

    /**
     * B2B Customer Groups (fallback defaults)
     */
    public const GROUP_B2B_ATACADO = 4;
    public const GROUP_B2B_VIP = 5;
    public const GROUP_B2B_REVENDEDOR = 6;
    public const GROUP_B2B_PENDENTE = 7;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var B2BConfig
     */
    private $b2bConfig;

    /**
     * @var PriceVisibilityInterface
     */
    private $priceVisibility;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var array|null
     */
    private $b2bGroupsCache = null;

    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        B2BConfig $b2bConfig,
        PriceVisibilityInterface $priceVisibility,
        PriceCurrencyInterface $priceCurrency
    ) {
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->b2bConfig = $b2bConfig;
        $this->priceVisibility = $priceVisibility;
        $this->priceCurrency = $priceCurrency;
        parent::__construct($context);
    }

    /**
     * Check if B2B module is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get B2B mode
     *
     * @return string
     */
    public function getMode(): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_B2B_MODE,
            ScopeInterface::SCOPE_STORE
        ) ?: 'mixed';
    }

    /**
     * Check if prices should be hidden for guests
     *
     * @return bool
     */
    public function shouldHidePricesForGuests(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HIDE_PRICES,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if approval is required for B2B customers
     *
     * @return bool
     */
    public function isApprovalRequired(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_APPROVAL,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if quote system is enabled
     *
     * @return bool
     */
    public function isQuoteEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_QUOTE_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get quote expiry days
     *
     * @return int
     */
    public function getQuoteExpiryDays(): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_QUOTE_EXPIRY,
            ScopeInterface::SCOPE_STORE
        ) ?: 7;
    }

    /**
     * Get config value
     *
     * @param string $path
     * @return mixed
     */
    public function getConfigValue(string $path)
    {
        return $this->scopeConfig->getValue(
            'grupoawamotos_b2b/' . $path,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if order approval is enabled
     */
    public function isOrderApprovalEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PATH_ORDER_APPROVAL_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get order approval threshold for Manager level
     */
    public function getThresholdManager(): float
    {
        return (float)($this->scopeConfig->getValue(
            self::XML_PATH_THRESHOLD_MANAGER,
            ScopeInterface::SCOPE_STORE
        ) ?: 2000);
    }

    /**
     * Get order approval threshold for Finance level
     */
    public function getThresholdFinance(): float
    {
        return (float)($this->scopeConfig->getValue(
            self::XML_PATH_THRESHOLD_FINANCE,
            ScopeInterface::SCOPE_STORE
        ) ?: 10000);
    }

    /**
     * Get order approval threshold for Director level
     */
    public function getThresholdDirector(): float
    {
        return (float)($this->scopeConfig->getValue(
            self::XML_PATH_THRESHOLD_DIRECTOR,
            ScopeInterface::SCOPE_STORE
        ) ?: 50000);
    }

    /**
     * Format a customer/company name for display.
     *
     * Registros importados do ERP frequentemente chegam 100% em caixa alta
     * (ex.: "FERNANDO", "JOSE PAVAO & CIA LTDA"), resultando em saudações
     * como "Olá, FERNANDO" no header B2B. Aplicamos title-case somente em
     * nomes totalmente maiúsculos (assinatura do import ERP), preservando
     * intocado qualquer nome já digitado corretamente pelo próprio cliente
     * (ex.: "Maria Silva" no autocadastro).
     *
     * @param string|null $name
     * @return string
     */
    public function formatDisplayName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || $name !== mb_strtoupper($name, 'UTF-8')) {
            return $name;
        }

        return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Check if current customer is B2B
     *
     * @return bool
     */
    public function isB2BCustomer(): bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }

        $groupId = (int)$this->customerSession->getCustomerGroupId();
        return in_array($groupId, $this->getB2BGroupIds());
    }

    /**
     * Check if a specific customer is B2B by ID
     *
     * @param int $customerId
     * @return bool
     */
    public function isB2BCustomerById(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $groupId = (int)$customer->getGroupId();
            return in_array($groupId, $this->getB2BGroupIds());
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if current customer is approved B2B
     *
     * @return bool
     */
    public function isApprovedB2BCustomer(): bool
    {
        if (!$this->isB2BCustomer()) {
            return false;
        }

        $groupId = (int)$this->customerSession->getCustomerGroupId();

        // Pendente não está aprovado
        if ($groupId === self::GROUP_B2B_PENDENTE) {
            return false;
        }

        return true;
    }

    /**
     * Whether the logged-in customer is approved in EAV (not just session group).
     */
    public function isCurrentCustomerApproved(): bool
    {
        return $this->getApprovalStatus() === ApprovalStatus::STATUS_APPROVED;
    }

    /**
     * Get current customer B2B group
     *
     * @return int|null
     */
    public function getCustomerB2BGroup(): ?int
    {
        if (!$this->isB2BCustomer()) {
            return null;
        }

        return (int)$this->customerSession->getCustomerGroupId();
    }

    /**
     * Get B2B group name
     *
     * @param int $groupId
     * @return string
     */
    public function getB2BGroupName(int $groupId): string
    {
        $names = [
            self::GROUP_B2B_ATACADO => 'B2B Atacado',
            self::GROUP_B2B_VIP => 'B2B VIP',
            self::GROUP_B2B_REVENDEDOR => 'B2B Revendedor',
            self::GROUP_B2B_PENDENTE => 'B2B Pendente'
        ];

        return $names[$groupId] ?? 'Cliente';
    }

    /**
     * Get discount percentage for customer group.
     * Pricing is managed exclusively by the ERP (GroupPricePlugin).
     * No group-based discounts are applied.
     *
     * @param int $groupId
     * @return float
     */
    public function getGroupDiscount(int $groupId): float
    {
        return 0.0;
    }

    /**
     * Get all B2B group IDs (reads from admin config with fallback)
     *
     * @return array
     */
    public function getB2BGroupIds(): array
    {
        if ($this->b2bGroupsCache !== null) {
            return $this->b2bGroupsCache;
        }

        $groups = [];
        $wholesaleId = $this->b2bConfig->getWholesaleGroupId();
        $vipId = $this->b2bConfig->getVipGroupId();
        $defaultId = $this->b2bConfig->getDefaultB2BGroupId();

        if ($wholesaleId) {
            $groups[] = $wholesaleId;
        }
        if ($vipId) {
            $groups[] = $vipId;
        }
        if ($defaultId && !in_array($defaultId, $groups)) {
            $groups[] = $defaultId;
        }

        // Always include pending group
        $pendingId = $this->getPendingGroupId();
        if ($pendingId && !in_array($pendingId, $groups)) {
            $groups[] = $pendingId;
        }

        // Always include B2B Revendedor group
        $revendedorId = $this->b2bConfig->getRevendedorGroupId() ?: self::GROUP_B2B_REVENDEDOR;
        if (!in_array($revendedorId, $groups)) {
            $groups[] = $revendedorId;
        }

        // Fallback to hardcoded if no config set
        if (empty($groups)) {
            $groups = [
                self::GROUP_B2B_ATACADO,
                self::GROUP_B2B_VIP,
                self::GROUP_B2B_REVENDEDOR,
                self::GROUP_B2B_PENDENTE
            ];
        }

        $this->b2bGroupsCache = array_unique(array_map('intval', $groups));
        return $this->b2bGroupsCache;
    }

    /**
     * Get pending group ID
     *
     * @return int
     */
    public function getPendingGroupId(): int
    {
        return $this->b2bConfig->getPendingGroupId() ?: self::GROUP_B2B_PENDENTE;
    }

    /**
     * Check if a group ID is a B2B group
     *
     * @param int $groupId
     * @return bool
     */
    public function isB2BGroup(int $groupId): bool
    {
        return in_array($groupId, $this->getB2BGroupIds());
    }

    /**
     * Get group ID by code
     *
     * @param string $code
     * @return int|null
     */
    public function getGroupIdByCode(string $code): ?int
    {
        $mapping = [
            'b2b_atacado' => $this->b2bConfig->getWholesaleGroupId() ?: self::GROUP_B2B_ATACADO,
            'b2b_vip' => $this->b2bConfig->getVipGroupId() ?: self::GROUP_B2B_VIP,
            'b2b_revendedor' => $this->b2bConfig->getRevendedorGroupId() ?: self::GROUP_B2B_REVENDEDOR,
            'b2b_pendente' => $this->b2bConfig->getPendingGroupId() ?: self::GROUP_B2B_PENDENTE,
        ];

        return $mapping[$code] ?? null;
    }

    /**
     * Check if the current visitor can view prices.
     */
    public function canViewPrices(): bool
    {
        return $this->priceVisibility->canViewPrices();
    }

    /**
     * Check if the current visitor can add items to cart.
     */
    public function canAddToCart(): bool
    {
        return $this->priceVisibility->canAddToCart();
    }

    /**
     * Return a compact state used by the B2B gate UI.
     */
    public function getPriceGateState(): string
    {
        if ($this->canAddToCart()) {
            return 'open';
        }

        if (!$this->customerSession->isLoggedIn()) {
            return 'guest';
        }

        if ($this->priceVisibility->isApprovedPendingErp()) {
            return 'erp_pending';
        }

        return match ($this->getApprovalStatus()) {
            'rejected' => 'rejected',
            'suspended' => 'suspended',
            default => 'pending',
        };
    }

    /**
     * Headline shown in gated product cards.
     */
    public function getPriceGateHeadline(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => 'Preços exclusivos para revendedores',
            'erp_pending' => 'Sua tabela de preços está sendo liberada',
            'rejected' => 'Seu cadastro precisa de revisão',
            'suspended' => 'Seu acesso comercial está suspenso',
            'pending' => 'Cadastro em análise comercial',
            default => 'Acesso comercial liberado',
        };
    }

    /**
     * Supporting description shown in gated product cards.
     */
    public function getPriceGateDescription(): string
    {
        return $this->sanitizeGateMessage(
            $this->priceVisibility->getPriceReplacementMessage(),
            $this->getPriceGateBannerDescription()
        );
    }

    /**
     * Long-form copy for PLP/search gate banner (not card CTA snippets).
     */
    public function getPriceGateBannerDescription(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => 'Cadastre-se gratuitamente em 2 min para consultar preços e comprar no atacado.',
            'erp_pending' => 'Sua empresa já foi aprovada e nossa equipe está concluindo a tabela comercial no ERP.',
            'rejected' => 'Fale com a equipe comercial para revisar os dados da sua empresa e liberar o acesso novamente.',
            'suspended' => 'Seu acesso comercial está pausado. Nossa equipe pode orientar os próximos passos.',
            'pending' => 'Estamos validando os dados da sua empresa para liberar condições comerciais e compra recorrente.',
            default => 'Acesso comercial liberado.',
        };
    }

    /**
     * Short-form copy for the price-gate badge inside product grid cards.
     *
     * O card tem espaço limitado (ao contrário do banner institucional do
     * topo da PLP/busca, que já mostra a explicação completa). Reaproveita a
     * mesma redação curta do CTA principal para manter a mensagem consistente
     * entre o texto do card e o botão de ação.
     */
    public function getPriceGateCardMessage(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => 'Cadastre-se para ver o preço',
            'erp_pending' => 'Tabela de preços em liberação',
            'rejected' => 'Cadastro precisa de revisão',
            'suspended' => 'Acesso comercial suspenso',
            'pending' => 'Cadastro em análise',
            default => 'Acesso comercial liberado',
        };
    }

    /**
     * Primary CTA URL used by gated product cards.
     */
    public function getPriceGatePrimaryUrl(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => $this->_getUrl('b2b/register'),
            default => $this->_getUrl('b2b/account/dashboard'),
        };
    }

    /**
     * Primary CTA label used by gated product cards.
     */
    public function getPriceGatePrimaryLabel(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => 'Cadastrar para ver preços',
            'erp_pending' => 'Acompanhar minha conta',
            'rejected' => 'Minha conta',
            'suspended' => 'Minha conta',
            'pending' => 'Ver status do cadastro',
            default => 'Minha conta',
        };
    }

    /**
     * Secondary CTA URL used by gated product cards.
     */
    public function getPriceGateSecondaryUrl(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => $this->_getUrl('b2b/account/login'),
            default => $this->_getUrl('contact'),
        };
    }

    /**
     * Secondary CTA label used by gated product cards.
     */
    public function getPriceGateSecondaryLabel(): string
    {
        return match ($this->getPriceGateState()) {
            'guest' => 'Já tenho conta',
            default => 'Falar com vendas',
        };
    }

    /**
     * Load the current approval status from the repository when possible.
     */
    private function getApprovalStatus(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById((int) $this->customerSession->getCustomerId());
            $attribute = $customer->getCustomAttribute('b2b_approval_status');

            if ($attribute === null || $attribute->getValue() === null || $attribute->getValue() === '') {
                return null;
            }

            return (string) $attribute->getValue();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Normalize configured messages for card output.
     */
    private function sanitizeGateMessage(string $message, string $fallback): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim(strip_tags($message))) ?: '';

        return $normalized !== '' ? $normalized : $fallback;
    }

    /**
     * Get frontend URL to view an order.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return string
     */
    /**
     * Get a "Price Teaser" for products when prices are hidden.
     * Shows something like "A partir de R$ XX,XX" for guest users.
     *
     * @param Product $product
     * @return string
     */
    public function getPriceTeaser(Product $product): string
    {
        if ($this->getPriceGateState() !== 'guest') {
            return '';
        }

        // Use the final price as a reference (this might be the B2C price)
        $price = $product->getFinalPrice();
        if (!$price) {
            return '';
        }

        $formattedPrice = $this->priceCurrency->format($price, false);
        return (string) __('A partir de %1', $formattedPrice);
    }

    public function getOrderViewUrl(\Magento\Sales\Model\Order $order): string
    {
        return $this->_getUrl('sales/order/view', ['order_id' => $order->getId()]);
    }
}
