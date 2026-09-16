<?php

declare(strict_types=1);

namespace GrupoAwamotos\MarketingIntelligence\Observer;

use GrupoAwamotos\MarketingIntelligence\Model\Service\FunnelEventService;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Sends B2BRepurchase CAPI event when a B2B customer places a repeat order.
 * Event: checkout_submit_all_after
 *
 * Detects B2B by customer group or CNPJ attribute length = 14.
 * Detects repeat by checking order count > 1.
 */
class SendRepurchase implements ObserverInterface
{
    /** @var array<int, bool> Customer group IDs considered B2B */
    private const B2B_GROUP_IDS = [4, 5, 6];

    public function __construct(
        private readonly FunnelEventService $funnelEventService,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var \Magento\Sales\Model\Order|null $order */
            $order = $observer->getData('order');
            if ($order === null) {
                $orders = $observer->getData('orders');
                if (is_array($orders) && !empty($orders)) {
                    $order = reset($orders);
                }
            }

            if ($order === null || !$order->getCustomerId()) {
                return;
            }

            $customerId = (int) $order->getCustomerId();

            if (!$this->isB2BCustomer($customerId, $order)) {
                return;
            }

            if (!$this->isRepeatPurchase($order)) {
                return;
            }

            $this->funnelEventService->sendFunnelEvent(
                'B2BRepurchase',
                $customerId,
                [
                    'content_category' => 'b2b_repurchase',
                    'order_id' => $order->getIncrementId(),
                    'value' => (float) $order->getGrandTotal(),
                    'num_items' => (int) $order->getTotalItemCount(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'SendRepurchase observer failed — ' . $e->getMessage()
            );
        }
    }

    private function isB2BCustomer(int $customerId, \Magento\Sales\Model\Order $order): bool
    {
        $groupId = (int) $order->getCustomerGroupId();
        if (in_array($groupId, self::B2B_GROUP_IDS, true)) {
            return true;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
            $cnpj = $customer->getCustomAttribute('cnpj')?->getValue();
            if (!empty($cnpj) && strlen(preg_replace('/\D/', '', (string) $cnpj)) === 14) {
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[SendRepurchase::isB2BCustomer] Exceção ignorada: ' . $e->getMessage());
            // Not found — not B2B
        }

        return false;
    }

    /**
     * Check if the customer has previously completed orders (order count > 1).
     */
    private function isRepeatPurchase(\Magento\Sales\Model\Order $order): bool
    {
        $customerId = (int) $order->getCustomerId();

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToFilter('state', ['in' => ['complete', 'processing', 'new']]);

        return $collection->getSize() > 1;
    }
}
