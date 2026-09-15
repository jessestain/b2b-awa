<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Account;

use Magento\Customer\Model\Session;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class Orders extends Template
{
    protected $_template = 'GrupoAwamotos_B2B::account/orders.phtml';

    public function __construct(
        Context $context,
        private readonly Session $customerSession,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getOrders(): array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$customerId) {
            return [];
        }

        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', $customerId)
            ->addFieldToSelect('*')
            ->setOrder('created_at', 'DESC')
            ->setPageSize(30);

        return $collection->getItems();
    }

    /** ERP sync not yet implemented — returns empty until ErpSectra module is available. */
    public function getErpSync(int $orderId): array
    {
        return [];
    }

    public function getErpStatusLabel(string $status): string
    {
        return match ($status) {
            'pending'      => 'Enviando ao ERP',
            'acknowledged' => 'No ERP',
            'synced'       => 'Sincronizado',
            'error'        => 'Erro de envio',
            default        => '',
        };
    }

    public function getErpStatusColor(string $status): string
    {
        return match ($status) {
            'pending'      => '#f57c00',
            'acknowledged' => '#1565c0',
            'synced'       => '#2e7d32',
            'error'        => '#c62828',
            default        => '#999',
        };
    }

    public function getOrderUrl(\Magento\Sales\Model\Order $order): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $order->getId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('b2b/account');
    }

    public function formatPrice(float $price): string
    {
        return $this->priceCurrency->format($price, false);
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
