<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Observer;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Observer for Order Status Changes
 *
 * Sends WhatsApp notifications when order status changes to:
 * - processing (Pedido Confirmado)
 * - complete (Pedido Entregue)
 * - canceled (Pedido Cancelado)
 * - holded (Pedido em Análise)
 */
class OrderStatusChange implements ObserverInterface
{
    private array $notifiableStatuses = [
        'processing' => 'processing',
        'complete' => 'complete',
        'canceled' => 'canceled',
        'holded' => 'holded',
    ];

    public function __construct(
        private readonly WhatsappSenderInterface $whatsappSender,
        private readonly Helper $helper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isWhatsAppOrderStatusEnabled()) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        $newStatus = $order->getStatus();
        $originalStatus = $order->getOrigData('status');

        if ($newStatus === $originalStatus) {
            return;
        }

        if (!isset($this->notifiableStatuses[$newStatus])) {
            return;
        }

        $this->sendOrderStatusNotification($order, $newStatus);
    }

    private function sendOrderStatusNotification(Order $order, string $status): void
    {
        try {
            $phoneNumber = $this->getCustomerPhone($order);

            if (!$phoneNumber) {
                return;
            }

            $message = $this->buildStatusMessage($order->getIncrementId(), $status);
            $result = $this->queueStatusMessage($order, $phoneNumber, $message);

            if ($result) {
                $this->logger->info(sprintf(
                    '[WhatsApp Order] Status notification queued for order %s (status: %s)',
                    $order->getIncrementId(),
                    $status
                ));
                $order->addCommentToStatusHistory(
                    sprintf('WhatsApp: Notificação de status "%s" agendada.', $status)
                );
            } else {
                $this->logger->warning(sprintf(
                    '[WhatsApp Order] Failed to queue notification for order %s',
                    $order->getIncrementId()
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[WhatsApp Order] Error sending notification for order %s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    protected function queueStatusMessage(Order $order, string $phone, string $message): bool
    {
        return $this->whatsappSender->queueMessage($phone, $message, 10);
    }

    private function buildStatusMessage(string $incrementId, string $status): string
    {
        return match ($status) {
            'processing' => "✅ Pedido #{$incrementId} confirmado!\nEstamos processando seu pedido. Em breve você receberá uma atualização.",
            'complete'   => "📦 Pedido #{$incrementId} entregue!\nObrigado pela preferência. Esperamos vê-lo novamente em breve.",
            'canceled'   => "❌ Pedido #{$incrementId} foi cancelado.\nDúvidas? Entre em contato conosco.",
            'holded'     => "⏸ Pedido #{$incrementId} está em análise.\nEntraremos em contato em breve.",
            default      => "ℹ️ Pedido #{$incrementId}: status atualizado para {$status}.",
        };
    }

    private function getCustomerPhone(Order $order): ?string
    {
        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress && $shippingAddress->getTelephone()) {
            return $shippingAddress->getTelephone();
        }

        $billingAddress = $order->getBillingAddress();
        if ($billingAddress && $billingAddress->getTelephone()) {
            return $billingAddress->getTelephone();
        }

        return null;
    }
}
