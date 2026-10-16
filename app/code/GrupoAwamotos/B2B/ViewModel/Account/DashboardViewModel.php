<?php

/**
 * ViewModel for B2B Customer Dashboard
 * Extracted from Block\Account\Dashboard to follow ViewModel pattern.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\ViewModel\Account;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\ResourceModel\CreditLimit\CollectionFactory as CreditCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest\CollectionFactory as QuoteCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\ShoppingList\CollectionFactory as ShoppingListCollectionFactory;
use Psr\Log\LoggerInterface;

class DashboardViewModel implements ArgumentInterface
{
    private const XML_PATH_QUICK_ORDER_ENABLED = 'grupoawamotos_b2b/features/quick_order_enabled';

    private CustomerSession $customerSession;
    private OrderCollectionFactory $orderCollectionFactory;
    private QuoteCollectionFactory $quoteCollectionFactory;
    private CreditCollectionFactory $creditCollectionFactory;
    private B2BHelper $b2bHelper;
    private CustomerRepositoryInterface $customerRepository;
    private AttendantManager $attendantManager;
    private ShoppingListCollectionFactory $shoppingListCollectionFactory;
    private ScopeConfigInterface $scopeConfig;
    private TimezoneInterface $timezone;
    private LoggerInterface $logger;

    private ?CustomerInterface $cachedCustomer = null;
    private ?OrderCollection $cachedRecentOrders = null;

    public function __construct(
        CustomerSession $customerSession,
        OrderCollectionFactory $orderCollectionFactory,
        QuoteCollectionFactory $quoteCollectionFactory,
        CreditCollectionFactory $creditCollectionFactory,
        B2BHelper $b2bHelper,
        CustomerRepositoryInterface $customerRepository,
        AttendantManager $attendantManager,
        ShoppingListCollectionFactory $shoppingListCollectionFactory,
        ScopeConfigInterface $scopeConfig,
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->customerSession = $customerSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->creditCollectionFactory = $creditCollectionFactory;
        $this->b2bHelper = $b2bHelper;
        $this->customerRepository = $customerRepository;
        $this->attendantManager = $attendantManager;
        $this->shoppingListCollectionFactory = $shoppingListCollectionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * Get current customer
     */
    public function getCustomer(): ?CustomerInterface
    {
        if ($this->cachedCustomer === null) {
            $customerId = $this->customerSession->getCustomerId();
            if ($customerId) {
                try {
                    $this->cachedCustomer = $this->customerRepository->getById($customerId);
                } catch (\Exception $e) {
                    $this->logger->error('[B2B Dashboard] Failed to load customer: ' . $e->getMessage(), [
                        'customer_id' => $customerId,
                    ]);
                    $this->cachedCustomer = null;
                }
            }
        }
        return $this->cachedCustomer;
    }

    /**
     * Check if customer is B2B
     */
    public function isB2BCustomer(): bool
    {
        return $this->b2bHelper->isB2BCustomer();
    }

    /**
     * Check if customer is approved
     */
    public function isApproved(): bool
    {
        $customer = $this->getCustomer();
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_approval_status');
            return $attr && $attr->getValue() === 'approved';
        }
        return false;
    }

    /**
     * FIX F-02 (2026-10-14): texto do checklist coerente com o status real.
     * Rejeitado/suspenso não podem ver "Aguardando aprovação...".
     */
    public function getApprovalStatusText(): string
    {
        $customer = $this->getCustomer();
        $status = '';
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_approval_status');
            $status = $attr ? (string) $attr->getValue() : '';
        }

        if ($status === 'rejected') {
            return (string) __('Cadastro não aprovado — fale com o atendimento');
        }
        if ($status === 'suspended') {
            return (string) __('Conta suspensa — fale com o atendimento');
        }

        return (string) __('Aguardando aprovação...');
    }

    /**
     * Get customer group name
     */
    public function getCustomerGroupName(): string
    {
        $customer = $this->getCustomer();
        if ($customer) {
            return $this->b2bHelper->getB2BGroupName((int) $customer->getGroupId());
        }
        return 'Cliente';
    }

    /**
     * Get discount percentage for customer group
     */
    public function getDiscountPercentage(): float
    {
        $customer = $this->getCustomer();
        if ($customer && $this->isApproved()) {
            return $this->b2bHelper->getGroupDiscount((int) $customer->getGroupId());
        }
        return 0;
    }

    /**
     * Get CNPJ
     */
    public function getCnpj(): string
    {
        $customer = $this->getCustomer();
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_cnpj');
            return $attr ? (string) $attr->getValue() : '';
        }
        return '';
    }

    /**
     * Get Razão Social
     */
    public function getRazaoSocial(): string
    {
        $customer = $this->getCustomer();
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_razao_social');
            return $attr ? (string) $attr->getValue() : '';
        }
        return '';
    }

    /**
     * Get credit limit
     */
    public function getCreditLimit(): ?array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }

        $collection = $this->creditCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('created_at', 'DESC')
            ->setPageSize(1);

        $credit = $collection->getFirstItem();
        if ($credit->getId()) {
            return [
                'limit' => (float) $credit->getCreditLimit(),
                'used' => (float) $credit->getUsedCredit(),
                'available' => (float) $credit->getCreditLimit() - (float) $credit->getUsedCredit()
            ];
        }

        return null;
    }

    /**
     * Get total orders amount for last 30 days
     */
    public function getTotalOrdersAmount(): float
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return 0.0;
        }

        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('created_at', ['from' => $thirtyDaysAgo])
            ->addFieldToFilter('state', ['neq' => 'canceled']);

        $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['total' => new Expression('SUM(grand_total)')]);

        return (float) ($collection->getFirstItem()->getData('total') ?? 0);
    }

    /**
     * Get recent orders
     */
    public function getRecentOrders(int $limit = 5): OrderCollection
    {
        if ($limit === 5 && $this->cachedRecentOrders !== null) {
            return $this->cachedRecentOrders;
        }

        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('created_at', 'DESC')
            ->setPageSize($limit);

        if ($limit === 5) {
            $this->cachedRecentOrders = $collection;
        }

        return $collection;
    }

    /**
     * Get purchase history data for charts (last 6 months)
     */
    public function getPurchaseHistoryData(): array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return [];
        }

        $timezone = $this->timezone->getConfigTimezone();
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

        $collection = $this->orderCollectionFactory->create();
        $sixMonthsAgo = $now->modify('first day of -5 months')->format('Y-m-d 00:00:00');

        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('created_at', ['from' => $sixMonthsAgo])
            ->addFieldToFilter('state', ['neq' => 'canceled']);

        $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns([
                'month_label' => new Expression("DATE_FORMAT(created_at, '%Y-%m')"),
                'monthly_total' => new Expression('SUM(grand_total)'),
            ])
            ->group(new Expression("DATE_FORMAT(created_at, '%Y-%m')"));

        $results = [];
        foreach ($collection as $row) {
            $results[$row->getData('month_label')] = (float) $row->getData('monthly_total');
        }

        $data = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthKey = $now->modify("-$i months")->format('Y-m');
            $monthLabel = $now->modify("-$i months")->format('M/y');
            $data[] = [
                'label' => $monthLabel,
                'value' => $results[$monthKey] ?? 0.0,
            ];
        }

        return $data;
    }

    /**
     * Get quote requests
     */
    public function getQuoteRequests(int $limit = 5)
    {
        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('created_at', 'DESC')
            ->setPageSize($limit);

        return $collection;
    }

    /**
     * Get pending quotes count
     */
    public function getPendingQuotesCount(): int
    {
        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('status', 'pending');

        return $collection->getSize();
    }

    /**
     * Get approved quotes count
     */
    public function getApprovedQuotesCount(): int
    {
        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('status', 'approved');

        return $collection->getSize();
    }

    /**
     * Get quote status label
     */
    public function getStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'Pendente',
            'processing' => 'Em Análise',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'expired' => 'Expirado',
            'converted' => 'Convertido em Pedido'
        ];
        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Get status CSS class
     */
    public function getStatusClass(string $status): string
    {
        $classes = [
            'pending' => 'warning',
            'processing' => 'info',
            'approved' => 'success',
            'rejected' => 'danger',
            'expired' => 'secondary',
            'converted' => 'primary'
        ];
        return $classes[$status] ?? 'secondary';
    }

    /**
     * Check if requesting a new quote is currently available.
     */
    public function isQuoteRequestAvailable(): bool
    {
        return $this->b2bHelper->isEnabled() && $this->b2bHelper->isQuoteEnabled();
    }

    /**
     * Check if the customer already has quote history.
     */
    public function hasQuoteHistory(): bool
    {
        return $this->getQuoteRequests(1)->getSize() > 0;
    }

    /**
     * Decide if quote actions should be shown on the dashboard.
     */
    public function shouldShowQuoteActions(): bool
    {
        return $this->isQuoteRequestAvailable() || $this->hasQuoteHistory();
    }

    /**
     * Get the most appropriate quote action path for the current feature state.
     */
    public function getQuoteActionPath(): string
    {
        return $this->isQuoteRequestAvailable() ? 'b2b/quote/index' : 'b2b/quote/history';
    }

    /**
     * Get the dashboard label for the primary quote action.
     */
    public function getQuoteActionLabel(): \Magento\Framework\Phrase
    {
        return $this->isQuoteRequestAvailable()
            ? __('Solicitar Cotação')
            : __('Ver Cotações');
    }

    /**
     * Check if quick order is currently available for customers.
     */
    public function isQuickOrderAvailable(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_QUICK_ORDER_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get attendant info for current customer
     */
    public function getAttendantInfo(): ?array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }

        try {
            return $this->attendantManager->getCustomerAttendant((int) $customerId);
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Dashboard] getAttendantInfo error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get onboarding checklist for new B2B customers
     */
    public function getOnboardingChecklist(): array
    {
        if (!$this->getCustomer()) {
            return [];
        }

        return [
            $this->buildChecklistItem('cnpj', 'CNPJ validado pela Receita Federal', !empty($this->getCnpj()), 'Validar CNPJ'),
            $this->buildChecklistItem('approval', 'Conta B2B aprovada', $this->isApproved(), $this->getApprovalStatusText()),
            $this->buildChecklistItem('address', 'Endereço de entrega cadastrado', $this->customerHasAddress(), 'Adicionar Endereço'),
            $this->buildChecklistItem('order', 'Primeiro pedido realizado', $this->getRecentOrders(1)->getSize() > 0, 'Ver Catálogo'),
            $this->buildChecklistItem('list', 'Lista de compras criada', $this->hasShoppingLists(), 'Criar Lista'),
        ];
    }

    /**
     * Build a single checklist item array (without URL — template adds it)
     */
    private function buildChecklistItem(string $id, string $label, bool $done, string $actionLabel): array
    {
        return [
            'id' => $id,
            'label' => __($label),
            'done' => $done,
            'action_label' => $done ? '' : __($actionLabel),
            'action_path' => $done ? '' : $this->resolveChecklistActionPath($id),
        ];
    }

    private function resolveChecklistActionPath(string $id): string
    {
        return match ($id) {
            'cnpj' => 'b2b/register',
            'address' => 'customer/address/new',
            'order' => 'catalogsearch/result/?q=',
            'list' => 'b2b/shoppinglist',
            default => '',
        };
    }

    /**
     * Get onboarding completion percentage
     */
    public function getOnboardingProgress(): int
    {
        $checklist = $this->getOnboardingChecklist();
        if (empty($checklist)) {
            return 0;
        }
        $done = array_filter($checklist, fn($item) => $item['done']);
        return (int) round((count($done) / count($checklist)) * 100);
    }

    /**
     * Check if onboarding is complete (all steps done)
     */
    public function isOnboardingComplete(): bool
    {
        return $this->getOnboardingProgress() === 100;
    }

    /**
     * Get smart suggestions based on customer profile
     */
    public function getSmartSuggestions(): array
    {
        if (!$this->getCustomer()) {
            return [];
        }

        $suggestions = array_filter([
            $this->buildQuoteSuggestion(),
            $this->buildQuickOrderSuggestion(),
            $this->buildAttendantSuggestion(),
            $this->buildShoppingListSuggestion(),
            $this->buildCatalogSuggestion(),
        ]);

        usort($suggestions, fn($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));
        return array_slice(array_values($suggestions), 0, 4);
    }

    private function buildQuoteSuggestion(): ?array
    {
        if (!$this->isQuoteRequestAvailable()) {
            return null;
        }

        if ($this->getPendingQuotesCount() > 0 || $this->getApprovedQuotesCount() > 0) {
            return null;
        }
        return [
            'type' => 'quote',
            'title' => __('Solicite sua primeira cotação'),
            'description' => __('Envie uma lista de produtos e receba preços personalizados para sua empresa.'),
            'action_label' => __('Solicitar Cotação'),
            'action_path' => 'b2b/quote/index',
            'priority' => 1,
        ];
    }

    private function buildQuickOrderSuggestion(): ?array
    {
        if (!$this->isApproved() || !$this->isQuickOrderAvailable()) {
            return null;
        }
        return [
            'type' => 'quickorder',
            'title' => __('Pedido Rápido por Código'),
            'description' => __('Já sabe o código do produto? Adicione direto ao carrinho por código ou referência.'),
            'action_label' => __('Fazer Pedido Rápido'),
            'action_path' => 'b2b/quickorder',
            'priority' => 2,
        ];
    }

    private function buildAttendantSuggestion(): ?array
    {
        $attendant = $this->getAttendantInfo();
        if (!$attendant) {
            return null;
        }
        if ($this->getRecentOrders(1)->getSize() > 0) {
            return null;
        }
        return [
            'type' => 'attendant',
            'title' => __('Fale com seu atendente'),
            'description' => __('%1 do departamento %2 pode ajudar com suas primeiras compras.', $attendant['name'] ?? '', $attendant['department'] ?? 'Comercial'),
            'action_label' => __('Enviar WhatsApp'),
            'action_path' => '',
            'priority' => 3,
            'external' => true,
            'whatsapp' => $attendant['whatsapp'] ?? null,
            'email' => $attendant['email'] ?? null,
        ];
    }

    private function buildShoppingListSuggestion(): ?array
    {
        if ($this->hasShoppingLists()) {
            return null;
        }
        return [
            'type' => 'shoppinglist',
            'title' => __('Organize seus produtos favoritos'),
            'description' => __('Crie listas de compras para agilizar pedidos recorrentes da sua empresa.'),
            'action_label' => __('Criar Lista de Compras'),
            'action_path' => 'b2b/shoppinglist',
            'priority' => 4,
        ];
    }

    private function buildCatalogSuggestion(): ?array
    {
        if (!$this->isApproved() || $this->getRecentOrders(1)->getSize() > 0) {
            return null;
        }
        return [
            'type' => 'catalog',
            'title' => __('Explore nosso catálogo'),
            'description' => __('Conheça as peças e acessórios mais vendidos com preço especial para sua empresa.'),
            'action_label' => __('Ver Produtos'),
            'action_path' => 'catalogsearch/result/?q=',
            'priority' => 5,
        ];
    }

    /**
     * Build attendant contact URL (WhatsApp or email)
     */
    public function buildAttendantContactUrl(array $attendant): string
    {
        if (!empty($attendant['whatsapp'])) {
            $countryCode = (string) $this->scopeConfig->getValue('grupoawamotos_b2b/whatsapp/country_code', ScopeInterface::SCOPE_STORE) ?: '55';
            return 'https://wa.me/' . $countryCode . preg_replace('/\D/', '', $attendant['whatsapp']);
        }
        return 'mailto:' . ($attendant['email'] ?? '');
    }

    /**
     * Get account registration date formatted
     */
    public function getRegistrationDate(): string
    {
        $customer = $this->getCustomer();
        if ($customer && $customer->getCreatedAt()) {
            return date('d/m/Y', strtotime($customer->getCreatedAt()));
        }
        return '';
    }

    /**
     * Check if this is a recently registered customer (within 30 days)
     */
    public function isNewCustomer(): bool
    {
        $customer = $this->getCustomer();
        if ($customer && $customer->getCreatedAt()) {
            $registrationDate = strtotime($customer->getCreatedAt());
            $thirtyDaysAgo = strtotime('-30 days');
            return $registrationDate > $thirtyDaysAgo;
        }
        return false;
    }

    /**
     * Check if customer has any address
     */
    public function customerHasAddress(): bool
    {
        $customer = $this->getCustomer();
        if (!$customer) {
            return false;
        }
        $addresses = $customer->getAddresses();
        return !empty($addresses);
    }

    /**
     * Check if customer has shopping lists
     */
    public function hasShoppingLists(): bool
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return false;
        }

        try {
            $collection = $this->shoppingListCollectionFactory->create();
            $collection->addFieldToFilter('customer_id', $customerId)
                ->setPageSize(1);
            return $collection->getSize() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
