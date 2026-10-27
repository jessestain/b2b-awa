<?php
declare(strict_types=1);
namespace GrupoAwamotos\ERPIntegration\Observer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data;
use Psr\Log\LoggerInterface;
/** Applies the existing customer WhatsApp preference before status notifications. */
class ConsentedOrderStatusChange extends OrderStatusChange
{
    public function __construct(
        WhatsappSenderInterface $whatsappSender,
        Data $helper,
        LoggerInterface $logger,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly \GrupoAwamotos\SmartSuggestions\Model\WhatsappQueueFactory $queueFactory
    ) {
        parent::__construct($whatsappSender, $helper, $logger);
    }
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getId() || !$order->getCustomerId()) {
            return;
        }
        try {
            $customer = $this->customerRepository->getById((int) $order->getCustomerId());
            $optin = $customer->getCustomAttribute('whatsapp_optin');
            if (!$optin || (int) $optin->getValue() !== 1) {
                return;
            }
        } catch (\Exception $e) {
            // A failed lookup must never be treated as permission to send.
            return;
        }
        parent::execute($observer);
    }
    /** Durable order identity; never infer consent from message text or telephone. */
    protected function queueStatusMessage(\Magento\Sales\Model\Order $order, string $phone, string $message): bool
    {
        $queue = $this->queueFactory->create();
        $queue->setData('message_type', 'order_status:' . (int) $order->getId());
        $queue->setData('erp_customer_id', 0);
        $queue->setData('phone', $phone);
        $queue->setData('message_content', $message);
        $queue->setData('priority', 10);
        $queue->setData('status', 'pending');
        $queue->getResource()->save($queue);
        return true;
    }
}
