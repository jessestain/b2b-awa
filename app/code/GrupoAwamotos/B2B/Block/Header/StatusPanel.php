<?php

/**
 * B2B Status Panel Block for Header
 * Professional B2B status indicator with dropdown panel
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Header;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Helper\Config as B2BConfig;

class StatusPanel extends Template
{
    private CustomerSession $customerSession;
    private B2BHelper $b2bHelper;
    private B2BConfig $b2bConfig;
    private PriceCurrencyInterface $priceCurrency;
    private ResourceConnection $resourceConnection;
    private CustomerRepositoryInterface $customerRepository;

    protected $_template = 'GrupoAwamotos_B2B::header/status-panel.phtml';

    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        B2BHelper $b2bHelper,
        PriceCurrencyInterface $priceCurrency,
        B2BConfig $b2bConfig,
        ResourceConnection $resourceConnection,
        CustomerRepositoryInterface $customerRepository,
        array $data = []
    ) {
        $this->customerSession = $customerSession;
        $this->b2bHelper = $b2bHelper;
        $this->b2bConfig = $b2bConfig;
        $this->priceCurrency = $priceCurrency;
        $this->resourceConnection = $resourceConnection;
        $this->customerRepository = $customerRepository;
        parent::__construct($context, $data);
    }

    /**
     * Check if B2B panel should be displayed
     */
    public function shouldDisplay(): bool
    {
        return $this->b2bHelper->isEnabled()
            && $this->customerSession->isLoggedIn()
            && $this->isB2BCustomer();
    }

    /**
     * Check if current customer is B2B
     */
    public function isB2BCustomer(): bool
    {
        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();
        $b2bGroups = $this->b2bHelper->getB2BGroupIds();
        return in_array($customerGroupId, $b2bGroups);
    }

    /**
     * Get customer data for display
     */
    public function getCustomerData(): array
    {
        $customer = null;
        $customerId = (int) $this->customerSession->getCustomerId();
        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();

        if ($customerId > 0) {
            try {
                $customer = $this->customerRepository->getById($customerId);
            } catch (\Exception $e) {
                $customer = null;
            }
        }

        $firstName = $customer ? (string) $customer->getFirstname() : '';
        $lastName = $customer ? (string) $customer->getLastname() : '';
        $fullName = trim($firstName . ' ' . $lastName);

        return [
            'first_name' => $this->b2bHelper->formatDisplayName($firstName),
            'full_name' => $this->b2bHelper->formatDisplayName($fullName),
            'email' => $customer ? (string) $customer->getEmail() : '',
            'company' => $this->b2bHelper->formatDisplayName($this->getCompanyName()),
            'group_id' => $customerGroupId,
            'group_name' => $this->getGroupName($customerGroupId),
            'group_badge' => $this->getGroupBadge($customerGroupId),
            'discount' => $this->getDiscountPercentage($customerGroupId),
            'credit_limit' => $this->getCreditLimit(),
            'credit_available' => $this->getAvailableCredit(),
        ];
    }

    /**
     * Get company name from b2b_razao_social custom attribute
     */
    private function getCompanyName(): string
    {
        try {
            $customerId = $this->customerSession->getCustomerId();
            if ($customerId) {
                $customer = $this->customerRepository->getById($customerId);
                $attr = $customer->getCustomAttribute('b2b_razao_social');
                if ($attr && $attr->getValue()) {
                    return (string) $attr->getValue();
                }
                // Fallback to other attribute names
                foreach (['razao_social', 'company', 'empresa'] as $attrCode) {
                    $fallback = $customer->getCustomAttribute($attrCode);
                    if ($fallback && $fallback->getValue()) {
                        return (string) $fallback->getValue();
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->warning('[StatusPanel::getCompanyName] Exceção ignorada: ' . $e->getMessage());
        }
        return '';
    }

    /**
     * Get customer group name (uses Helper for consistent naming)
     */
    private function getGroupName(int $groupId): string
    {
        return $this->b2bHelper->getB2BGroupName($groupId);
    }

    /**
     * Get group badge color/style
     */
    private function getGroupBadge(int $groupId): array
    {
        $wholesaleId = $this->b2bConfig->getWholesaleGroupId() ?: B2BHelper::GROUP_B2B_ATACADO;
        $vipId = $this->b2bConfig->getVipGroupId() ?: B2BHelper::GROUP_B2B_VIP;
        $pendingId = $this->b2bConfig->getPendingGroupId() ?: B2BHelper::GROUP_B2B_PENDENTE;

        if ($groupId === $wholesaleId) {
            return ['color' => '#b73337', 'icon' => 'building', 'label' => 'Atacado'];
        }
        if ($groupId === $vipId) {
            return ['color' => '#7c3aed', 'icon' => 'crown', 'label' => 'VIP'];
        }
        if ($groupId === $pendingId) {
            return ['color' => '#f59e0b', 'icon' => 'clock-o', 'label' => 'Pendente'];
        }

        // Other B2B groups (Revendedor, etc.)
        return ['color' => '#059669', 'icon' => 'store', 'label' => $this->getGroupName($groupId)];
    }

    /**
     * Get discount percentage for customer group (reads from admin config)
     */
    private function getDiscountPercentage(int $groupId): int
    {
        return (int) $this->b2bHelper->getGroupDiscount($groupId);
    }

    /**
     * Get credit limit from grupoawamotos_b2b_credit_limit table
     */
    private function getCreditLimit(): float
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return 0.0;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('grupoawamotos_b2b_credit_limit');
            $select = $connection->select()
                ->from($tableName, ['credit_limit'])
                ->where('customer_id = ?', (int) $customerId);
            $result = $connection->fetchOne($select);
            return $result !== false ? (float) $result : 0.0;
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * Get available credit (credit_limit - used_credit)
     */
    private function getAvailableCredit(): float
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return 0.0;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('grupoawamotos_b2b_credit_limit');
            $select = $connection->select()
                ->from($tableName, ['credit_limit', 'used_credit'])
                ->where('customer_id = ?', (int) $customerId);
            $row = $connection->fetchRow($select);
            if ($row) {
                return (float) $row['credit_limit'] - (float) $row['used_credit'];
            }
        } catch (\Exception $e) {
            $this->_logger->warning('[StatusPanel::getAvailableCredit] Exceção ignorada: ' . $e->getMessage());
        }
        return 0.0;
    }

    /**
     * Format price
     */
    public function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format($amount, false);
    }

    /**
     * Get quick action links
     */
    public function getQuickActions(): array
    {
        $actions = [
            [
                'url' => $this->getUrl('b2b/account/dashboard'),
                'label' => __('Painel B2B'),
                'icon' => 'tachometer',
            ],
            [
                'url' => $this->getUrl('sales/order/history'),
                'label' => __('Meus Pedidos'),
                'icon' => 'file-text-o',
            ],
            [
                'url' => $this->getUrl('b2b/shoppinglist'),
                'label' => __('Listas de Compras'),
                'icon' => 'list-ul',
            ],
        ];

        if ($this->b2bConfig->isQuoteEnabled()) {
            array_splice($actions, 2, 0, [[
                'url' => $this->getUrl('b2b/quote'),
                'label' => __('Cotações'),
                'icon' => 'calculator',
            ]]);
        }

        return $actions;
    }

    /**
     * Make this block customer-specific so FPC caches it per customer.
     * Without this, block_html cache serves the guest-rendered HTML to
     * every logged-in user — customer name never appears after login.
     */
    public function getCacheKeyInfo(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        $groupId    = (int) $this->customerSession->getCustomerGroupId();
        return array_merge(parent::getCacheKeyInfo(), [$customerId, $groupId]);
    }
}
