<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Block\Adminhtml\Customer;

use GrupoAwamotos\B2B\CommercialPanel\Model\ContactLogManagement;
use GrupoAwamotos\B2B\CommercialPanel\Model\Customer360DataProvider;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;

class View360 extends Template
{
    /** @var string[] */
    private const ALLOWED_TABS = [
        'resumo',
        'pedidos',
        'cotacoes',
        'carrinho',
        'contatos',
        'tarefas',
        'sugestoes',
    ];

    /** @var array<string, mixed>|null */
    private ?array $customerDataCache = null;

    private ?string $activeTabId = null;

    public function __construct(
        Context $context,
        private readonly Customer360DataProvider $customer360DataProvider,
        private readonly ContactLogManagement $contactLogManagement,
        private readonly PriceHelper $priceHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCustomerId(): int
    {
        return (int) $this->getRequest()->getParam('customer_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomerData(): array
    {
        if ($this->customerDataCache === null) {
            try {
                $this->customerDataCache = $this->customer360DataProvider->getCustomerData($this->getCustomerId());
            } catch (NoSuchEntityException) {
                $this->customerDataCache = [];
            }
        }

        return $this->customerDataCache;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPortfolioAssignment(): ?array
    {
        return $this->customer360DataProvider->getPortfolioAssignment($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContactHistory(): array
    {
        return $this->customer360DataProvider->getContactHistory($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentOrders(): array
    {
        return $this->customer360DataProvider->getRecentOrders($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getQuoteRequests(): array
    {
        return $this->customer360DataProvider->getQuoteRequests($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAbandonedCarts(): array
    {
        return $this->customer360DataProvider->getAbandonedCarts($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOpenTasks(): array
    {
        return $this->customer360DataProvider->getOpenTasks($this->getCustomerId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSuggestions(): array
    {
        return $this->customer360DataProvider->getRepurchaseSuggestions($this->getCustomerId());
    }

    /**
     * @return array<string, string>
     */
    public function getContactTypeOptions(): array
    {
        $labels = [
            'whatsapp' => __('WhatsApp'),
            'phone' => __('Ligação'),
            'email' => __('E-mail'),
            'visit' => __('Visita'),
            'other' => __('Outro'),
        ];

        $options = [];
        foreach ($this->contactLogManagement->getAllowedContactTypes() as $type) {
            if ($type === 'chat') {
                continue;
            }
            $options[$type] = (string) ($labels[$type] ?? $type);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function getTabList(): array
    {
        return [
            'resumo' => (string) __('Resumo'),
            'pedidos' => (string) __('Pedidos'),
            'cotacoes' => (string) __('Cotações'),
            'carrinho' => (string) __('Carrinho'),
            'contatos' => (string) __('Contatos'),
            'tarefas' => (string) __('Tarefas'),
            'sugestoes' => (string) __('Sugestões'),
        ];
    }

    /**
     * Aba inicial da ficha. Query ?tab= sobrevive ao admin Magento; hash #tab-* é descartado.
     */
    public function getActiveTabId(): string
    {
        if ($this->activeTabId !== null) {
            return $this->activeTabId;
        }

        $tab = strtolower(trim((string) $this->getRequest()->getParam('tab')));
        $this->activeTabId = in_array($tab, self::ALLOWED_TABS, true) ? $tab : 'resumo';

        return $this->activeTabId;
    }

    public function getSaveContactUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialcontact/save');
    }

    public function getPortfolioUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialportfolio/index');
    }

    public function getSaveTaskUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialtask/save');
    }

    public function getCompleteTaskUrl(int $taskId): string
    {
        return $this->getUrl('awa_commercial/commercialtask/complete', ['task_id' => $taskId]);
    }

    /**
     * @return array<string, string>
     */
    public function getPriorityOptions(): array
    {
        return [
            'low' => (string) __('Baixa'),
            'normal' => (string) __('Normal'),
            'high' => (string) __('Alta'),
            'urgent' => (string) __('Urgente'),
        ];
    }

    public function formatPrice(float $amount): string
    {
        return (string) $this->priceHelper->currency($amount, true, false);
    }
}
