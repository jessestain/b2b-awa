<?php
declare(strict_types=1);
namespace GrupoAwamotos\ERPIntegration\Observer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data;
use Psr\Log\LoggerInterface;
/** Applies the existing customer WhatsApp preference before status notifications. */
class ConsentedShipmentSaveAfter extends ShipmentSaveAfter
{
    public function __construct(
        WhatsappSenderInterface $whatsappSender,
        Data $helper,
        LoggerInterface $logger,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($whatsappSender, $helper, $logger);
    }
    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment ? $shipment->getOrder() : null;
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
}
