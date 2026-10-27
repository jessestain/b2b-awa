<?php

/**
 * Block para Dashboard B2B do cliente
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest\CollectionFactory as QuoteCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CreditLimit\CollectionFactory as CreditCollectionFactory;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Helper\Image as ImageHelper;
use GrupoAwamotos\B2B\Model\ResourceModel\ShoppingList\CollectionFactory as ShoppingListCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\DB\Sql\Expression;

class Dashboard extends Template
{
    private const XML_PATH_QUICK_ORDER_ENABLED = 'grupoawamotos_b2b/features/quick_order_enabled';
    /**
     * Cached customer instance — avoids repeated customerRepository->getById() calls per request
     *
     * @var \Magento\Customer\Api\Data\CustomerInterface|false|null
     */
    private $cachedCustomer = null;
    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var OrderCollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var QuoteCollectionFactory
     */
    private $quoteCollectionFactory;

    /**
     * @var CreditCollectionFactory
     */
    private $creditCollectionFactory;

    /**
     * @var B2BHelper
     */
    private $b2bHelper;

    /**
     * @var PricingHelper
     */
    private $pricingHelper;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var AttendantManager
     */
    private $attendantManager;

    /**
     * @var ProductCollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var ImageHelper
     */
    private $imageHelper;

    /**
     * @var ShoppingListCollectionFactory
     */
    private $shoppingListCollectionFactory;
    private ScopeConfigInterface $scopeConfig;
    private TimezoneInterface $timezone;
    private ?\Magento\Sales\Model\ResourceModel\Order\Collection $cachedRecentOrders = null;

    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        OrderCollectionFactory $orderCollectionFactory,
        QuoteCollectionFactory $quoteCollectionFactory,
        CreditCollectionFactory $creditCollectionFactory,
        B2BHelper $b2bHelper,
        PricingHelper $pricingHelper,
        CustomerRepositoryInterface $customerRepository,
        AttendantManager $attendantManager,
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ImageHelper $imageHelper,
        ShoppingListCollectionFactory $shoppingListCollectionFactory,
        ScopeConfigInterface|array|null $scopeConfig = null,
        array $data = [],
        ?TimezoneInterface $timezone = null
    ) {
        if (is_array($scopeConfig) && $data === []) {
            // Backward compatibility: stale generated metadata may pass $data in this position.
            $data = $scopeConfig;
            $scopeConfig = null;
        }

        $this->customerSession = $customerSession;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->quoteCollectionFactory = $quoteCollectionFactory;
        $this->creditCollectionFactory = $creditCollectionFactory;
        $this->b2bHelper = $b2bHelper;
        $this->pricingHelper = $pricingHelper;
        $this->customerRepository = $customerRepository;
        $this->attendantManager = $attendantManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->imageHelper = $imageHelper;
        $this->shoppingListCollectionFactory = $shoppingListCollectionFactory;
        $this->timezone = $timezone ?? $context->getLocaleDate();
        $this->scopeConfig = $scopeConfig instanceof ScopeConfigInterface
            ? $scopeConfig
            : $context->getScopeConfig();
        parent::__construct($context, $data);
    }

    /**
     * Format customer/company name for display (title-case for ERP all-caps imports).
     *
     * @param string|null $name
     * @return string
     */
    public function formatDisplayName(?string $name): string
    {
        return $this->b2bHelper->formatDisplayName($name);
    }

    /**
     * Get current customer
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    public function getCustomer()
    {
        if ($this->cachedCustomer === null) {
            $customerId = $this->customerSession->getCustomerId();
            if ($customerId) {
                try {
                    $this->cachedCustomer = $this->customerRepository->getById($customerId);
                } catch (\Exception $e) {
                    $this->_logger->error('[B2B Dashboard] Failed to load customer: ' . $e->getMessage(), [
                        'customer_id' => $customerId,
                    ]);
                    $this->cachedCustomer = false;
                }
            } else {
                $this->cachedCustomer = false;
            }
        }
        return $this->cachedCustomer ?: null;
    }

    /**
     * Check if customer is B2B
     *
     * @return bool
     */
    public function isB2BCustomer(): bool
    {
        return $this->b2bHelper->isB2BCustomer();
    }

    /**
     * Check if customer is approved
     *
     * @return bool
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return float
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
     *
     * @return string
     */
    public function getCnpj(): string
    {
        $customer = $this->getCustomer();
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_cnpj');
            return $attr ? (string)$attr->getValue() : '';
        }
        return '';
    }

    /**
     * Get Razão Social
     *
     * @return string
     */
    public function getRazaoSocial(): string
    {
        $customer = $this->getCustomer();
        if ($customer) {
            $attr = $customer->getCustomAttribute('b2b_razao_social');
            return $attr ? (string)$attr->getValue() : '';
        }
        return '';
    }

    /**
     * Get credit limit
     *
     * @return array|null
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
        if ($credit && $credit->getId()) {
            return [
                'limit' => (float)$credit->getCreditLimit(),
                'used' => (float)$credit->getUsedCredit(),
                'available' => (float)$credit->getCreditLimit() - (float)$credit->getUsedCredit()
            ];
        }

        return null;
    }

    /**
    /**
     * Get total orders amount for last 30 days
     *
     * @return float
     */
    public function getTotalOrdersAmount(): float
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return 0.0;
        }

        $thirtyDaysAgo = $this->timezone->date()->modify('-30 days')->format('Y-m-d H:i:s');
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
     *
     * @param int $limit
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getRecentOrders(int $limit = 5)
    {
        // Cache only the default (limit=5) call to avoid repeated queries within single request
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
     *
     * @return array
     */
    public function getPurchaseHistoryData(): array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return [];
        }

        $timezone = $this->_localeDate->getConfigTimezone();
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
     *
     * @param int $limit
     * @return \GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest\Collection
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
     *
     * @return int
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
     *
     * @return int
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
     * Format price
     *
     * @param float $price
     * @return string
     */
    public function formatPrice($price): string
    {
        return $this->pricingHelper->currency($price, true, false);
    }

    /**
     * Get quote status label
     *
     * @param string $status
     * @return string
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
     *
     * @param string $status
     * @return string
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
     * Get quote request URL
     *
     * @return string
     */
    public function getQuoteRequestUrl(): string
    {
        return $this->getUrl('b2b/quote/index');
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
     * Get the most appropriate quote action URL for the current feature state.
     */
    public function getQuoteActionUrl(): string
    {
        return $this->isQuoteRequestAvailable()
            ? $this->getQuoteRequestUrl()
            : $this->getQuotesListUrl();
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
     * Get quotes list URL
     *
     * @return string
     */
    public function getQuotesListUrl(): string
    {
        return $this->getUrl('b2b/quote/history');
    }

    /**
     * Get orders URL
     *
     * @return string
     */
    public function getOrdersUrl(): string
    {
        return $this->getUrl('sales/order/history');
    }

    /**
     * Get shopping list URL
     *
     * @return string
     */
    public function getShoppingListUrl(): string
    {
        return $this->getUrl('b2b/shoppinglist');
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
     *
     * @return array|null
     */
    public function getAttendantInfo(): ?array
    {
        $customerId = $this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }

        try {
            return $this->attendantManager->getCustomerAttendant((int)$customerId);
        } catch (\Exception $e) {
            $this->_logger->warning('[B2B Dashboard] getAttendantInfo error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Registra falhas não críticas ocorridas durante o render do template (ex.: dados ERP
     * indisponíveis). Exposto como método público pois templates PHTML não têm acesso direto
     * à propriedade protegida `_logger` herdada de AbstractBlock.
     *
     * @param string $context
     * @param \Throwable $e
     * @return void
     */
    public function logTemplateWarning(string $context, \Throwable $e): void
    {
        $this->_logger->warning('[B2B Dashboard] ' . $context . ': ' . $e->getMessage());
    }

    /**
     * Get onboarding checklist for new B2B customers
     *
     * @return array
     */
    public function getOnboardingChecklist(): array
    {
        if (!$this->getCustomer()) {
            return [];
        }

        return [
            $this->buildChecklistItem('cnpj', 'CNPJ validado pela Receita Federal', !empty($this->getCnpj()), 'Validar CNPJ', 'b2b/register'),
            $this->buildChecklistItem('approval', 'Conta B2B aprovada', $this->isApproved(), $this->getApprovalStatusText(), ''),
            $this->buildChecklistItem('address', 'Endereço de entrega cadastrado', $this->customerHasAddress(), 'Adicionar Endereço', 'customer/address/new'),
            $this->buildChecklistItem('order', 'Primeiro pedido realizado', $this->getRecentOrders(1)->getSize() > 0, 'Ver Catálogo', 'catalogsearch/result/?q='),
            $this->buildChecklistItem('list', 'Lista de compras criada', $this->hasShoppingLists(), 'Criar Lista', 'b2b/shoppinglist'),
        ];
    }

    /**
     * Build a single checklist item array
     *
     * @param string $id
     * @param string $label
     * @param bool $done
     * @param string $actionLabel
     * @param string $actionPath
     * @return array
     */
    private function buildChecklistItem(string $id, string $label, bool $done, string $actionLabel, string $actionPath): array
    {
        return [
            'id' => $id,
            'label' => __($label),
            'done' => $done,
            'action_label' => $done ? '' : __($actionLabel),
            'action_url' => ($done || $actionPath === '') ? '' : $this->getUrl($actionPath),
        ];
    }

    /**
     * Get onboarding completion percentage
     *
     * @return int
     */
    public function getOnboardingProgress(): int
    {
        $checklist = $this->getOnboardingChecklist();
        if (empty($checklist)) {
            return 0;
        }
        $done = array_filter($checklist, fn($item) => $item['done']);
        return (int)round((count($done) / count($checklist)) * 100);
    }

    /**
     * Check if onboarding is complete (all steps done)
     *
     * @return bool
     */
    public function isOnboardingComplete(): bool
    {
        return $this->getOnboardingProgress() === 100;
    }

    /**
     * Get smart suggestions based on customer profile
     *
     * @return array
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
            'action_url' => $this->getQuoteRequestUrl(),
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
            'action_url' => $this->getUrl('b2b/quickorder'),
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
            'action_url' => $this->buildAttendantContactUrl($attendant),
            'priority' => 3,
            'external' => true,
        ];
    }

    private function buildAttendantContactUrl(array $attendant): string
    {
        if (!empty($attendant['whatsapp'])) {
            $countryCode = (string) $this->scopeConfig->getValue('grupoawamotos_b2b/whatsapp/country_code', ScopeInterface::SCOPE_STORE) ?: '55';
            return 'https://wa.me/' . $countryCode . preg_replace('/\D/', '', $attendant['whatsapp']);
        }
        return 'mailto:' . ($attendant['email'] ?? '');
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
            'action_url' => $this->getUrl('b2b/shoppinglist'),
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
            'action_url' => $this->getUrl('catalogsearch/result/?q='),
            'priority' => 5,
        ];
    }

    /**
     * Get featured product categories
     *
     * @return array
     */
    public function getFeaturedCategories(): array
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'url_path', 'image'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('level', 2)
                ->addAttributeToFilter('include_in_menu', 1)
                ->setPageSize(6)
                ->setOrder('position', 'ASC');

            $categories = [];
            foreach ($collection as $category) {
                $categories[] = [
                    'name' => $category->getName(),
                    'url' => $this->getUrl($category->getUrlPath() ?: ('catalog/category/view/id/' . $category->getId())),
                    'id' => $category->getId(),
                ];
            }
            return $categories;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if customer has any address
     *
     * @return bool
     */
    private function customerHasAddress(): bool
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
     *
     * @return bool
     */
    private function hasShoppingLists(): bool
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

    /**
     * Get account registration date formatted
     *
     * @return string
     */
    public function getRegistrationDate(): string
    {
        $customer = $this->getCustomer();
        if ($customer && $customer->getCreatedAt()) {
            return $this->formatDate((string) $customer->getCreatedAt());
        }

        return '';
    }

    /**
     * Check if this is a recently registered customer (within 30 days)
     *
     * @return bool
     */
    public function isNewCustomer(): bool
    {
        $customer = $this->getCustomer();
        if (!$customer || !$customer->getCreatedAt()) {
            return false;
        }

        try {
            $registrationDate = $this->timezone->date(new \DateTime((string) $customer->getCreatedAt()));
            $thirtyDaysAgo = $this->timezone->date()->modify('-30 days');
            return $registrationDate > $thirtyDaysAgo;
        } catch (\Exception) {
            // Se a data vier inválida, evita classificar incorretamente como cliente novo.
            return false;
        }
    }

    /**
     * Quick order URL
     *
     * @return string
     */
    public function getQuickOrderUrl(): string
    {
        return $this->getUrl('b2b/quickorder');
    }

    /**
     * Formata data no padrão B2B (d/m/Y) preservando assinatura compatível com AbstractBlock.
     *
     * @param string|\DateTimeInterface|null $date
     * @param int $format
     * @param bool $showTime
     * @param string|null $timezone
     */
    public function formatDate(
        $date = null,
        $format = \IntlDateFormatter::SHORT,
        $showTime = false,
        $timezone = null
    ): string {
        if (func_num_args() === 1 || (func_num_args() === 0)) {
            if ($date === null || $date === '') {
                return '-';
            }

            $raw = $date instanceof \DateTimeInterface ? $date->format('c') : (string) $date;
            try {
                return $this->timezone->date(new \DateTime($raw))->format('d/m/Y');
            } catch (\Exception) {
                return substr($raw, 0, 10);
            }
        }

        return (string) parent::formatDate($date, $format, $showTime, $timezone);
    }

    public function formatDateTime(?string $date): string
    {
        if ($date === null || $date === '') {
            return '-';
        }

        try {
            return $this->timezone->date(new \DateTime($date))->format('d/m/Y H:i');
        } catch (\Exception) {
            return substr($date, 0, 16);
        }
    }
}
