<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use Psr\Log\LoggerInterface;

/**
 * Customer section: b2b_panel
 *
 * Provides all B2B header panel data for FPC pages (home, category, PDP).
 * On account pages the panel is server-rendered; here we power the JS hydration.
 * Called via /customer/section/load — never cached by FPC.
 */
class B2bPanel implements SectionSourceInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly UrlInterface $urlBuilder,
        private readonly B2BHelper $b2bHelper,
        private readonly B2BConfig $b2bConfig,
        private readonly LoggerInterface $logger,
        private readonly ?PriceVisibilityInterface $priceVisibility = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSectionData(): array
    {
        if (!$this->b2bHelper->isEnabled() || !$this->customerSession->isLoggedIn()) {
            return ['is_b2b' => false];
        }

        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();

        if (!in_array($customerGroupId, $this->b2bHelper->getB2BGroupIds(), true)) {
            return ['is_b2b' => false];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $customer = null;
        try {
            if ($customerId > 0) {
                $customer = $this->customerRepository->getById($customerId);
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                '[B2bPanel::getSectionData] Falha ao carregar cliente, seguindo sem dados: ' . $e->getMessage()
            );
        }

        $firstName = $customer ? (string) $customer->getFirstname() : '';
        $lastName = $customer ? (string) $customer->getLastname() : '';
        $fullName = trim($firstName . ' ' . $lastName);
        $firstName = $this->b2bHelper->formatDisplayName($firstName);
        $fullName = $this->b2bHelper->formatDisplayName($fullName);
        $company = $this->b2bHelper->formatDisplayName($this->resolveCompanyName($customer));
        $groupName = $this->b2bHelper->getB2BGroupName($customerGroupId);
        $badge = $this->resolveGroupBadge($customerGroupId);
        $discount = (int) $this->b2bHelper->getGroupDiscount($customerGroupId);
        $creditLimit = $this->fetchCreditLimit($customerId);
        $creditAvailable = $this->fetchAvailableCredit($customerId);
        $approvalStatus = $this->resolveApprovalStatus($customer);
        $canAddToCart = $this->priceVisibility?->canAddToCart();

        $quickActions = [
            [
                'url' => $this->urlBuilder->getUrl('b2b/account/dashboard'),
                'label' => (string) __('Painel B2B'),
                'icon' => 'tachometer',
            ],
            [
                'url' => $this->urlBuilder->getUrl('sales/order/history'),
                'label' => (string) __('Meus Pedidos'),
                'icon' => 'file-text-o',
            ],
            [
                'url' => $this->urlBuilder->getUrl('b2b/shoppinglist'),
                'label' => (string) __('Listas de Compras'),
                'icon' => 'list-ul',
            ],
        ];

        if ($this->b2bConfig->isQuoteEnabled()) {
            array_splice($quickActions, 2, 0, [[
                'url' => $this->urlBuilder->getUrl('b2b/quote'),
                'label' => (string) __('Cotações'),
                'icon' => 'calculator',
            ]]);
        }

        $section = [
            'is_b2b' => true,
            'first_name' => $firstName,
            'full_name' => $fullName,
            'company' => $company,
            'group_name' => $groupName,
            'badge_color' => $badge['color'],
            'badge_icon' => $badge['icon'],
            'discount' => $discount,
            'credit_limit' => $creditLimit,
            'credit_available' => $creditAvailable,
            'credit_available_formatted' => $creditAvailable > 0
                ? $this->priceCurrency->format($creditAvailable, false)
                : '',
            'quick_actions' => $quickActions,
            'account_url' => $this->urlBuilder->getUrl('b2b/account/dashboard'),
            'logout_url' => $this->urlBuilder->getUrl('customer/account/logout'),
            'approval_status' => $approvalStatus,
        ];
        if ($this->priceVisibility !== null) {
            $section['can_add_to_cart'] = $canAddToCart === true;
        }

        return $section;
    }

    /**
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     */
    private function resolveApprovalStatus(?object $customer): string
    {
        if ($customer === null) {
            return '';
        }
        $attr = $customer->getCustomAttribute('b2b_approval_status');
        if ($attr !== null && $attr->getValue() !== null && $attr->getValue() !== '') {
            return (string) $attr->getValue();
        }

        return ApprovalStatus::STATUS_APPROVED;
    }

    /**
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     */
    private function resolveCompanyName(?object $customer): string
    {
        if ($customer === null) {
            return '';
        }
        foreach (['b2b_razao_social', 'razao_social', 'company', 'empresa'] as $code) {
            $attr = $customer->getCustomAttribute($code);
            if ($attr !== null && $attr->getValue() !== '' && $attr->getValue() !== null) {
                return (string) $attr->getValue();
            }
        }
        return '';
    }

    private function resolveGroupBadge(int $groupId): array
    {
        $wholesaleId = $this->b2bConfig->getWholesaleGroupId() ?: B2BHelper::GROUP_B2B_ATACADO;
        $vipId = $this->b2bConfig->getVipGroupId() ?: B2BHelper::GROUP_B2B_VIP;
        $pendingId = $this->b2bConfig->getPendingGroupId() ?: B2BHelper::GROUP_B2B_PENDENTE;

        return match ($groupId) {
            $wholesaleId => ['color' => '#b73337', 'icon' => 'building'],
            $vipId => ['color' => '#7c3aed', 'icon' => 'crown'],
            $pendingId => ['color' => '#f59e0b', 'icon' => 'clock-o'],
            default => ['color' => '#059669', 'icon' => 'store'],
        };
    }

    private function fetchCreditLimit(int $customerId): float
    {
        if ($customerId === 0) {
            return 0.0;
        }
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_credit_limit');
            $result = $connection->fetchOne(
                $connection->select()->from($table, ['credit_limit'])->where('customer_id = ?', $customerId)
            );
            return $result !== false ? (float) $result : 0.0;
        } catch (\Exception) {
            return 0.0;
        }
    }

    private function fetchAvailableCredit(int $customerId): float
    {
        if ($customerId === 0) {
            return 0.0;
        }
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_credit_limit');
            $row = $connection->fetchRow(
                $connection->select()->from($table, ['credit_limit', 'used_credit'])->where('customer_id = ?', $customerId)
            );
            if ($row !== false) {
                return max(0.0, (float) $row['credit_limit'] - (float) $row['used_credit']);
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                '[B2bPanel::fetchAvailableCredit] Falha ao consultar limite de crédito: ' . $e->getMessage()
            );
        }
        return 0.0;
    }
}
