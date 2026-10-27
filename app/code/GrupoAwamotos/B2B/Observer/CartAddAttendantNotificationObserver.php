<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Service\GroupNotifier;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Aviso em tempo real quando o cliente adiciona produto ao carrinho:
 *  - grupo operacional (sempre que configurado, mesmo sem vendedora);
 *  - vendedora atribuida (individual, se houver).
 * Dedupe cliente+produto/dia.
 */
class CartAddAttendantNotificationObserver implements ObserverInterface
{
    private const CONFIG_REALTIME = 'grupoawamotos_b2b/whatsapp/notify_attendant_activity_realtime';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AttendantManager $attendantManager,
        private readonly WhatsAppPublisher $whatsAppPublisher,
        private readonly CacheInterface $cache,
        private readonly GroupNotifier $groupNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::CONFIG_REALTIME, ScopeInterface::SCOPE_STORE)) {
                return;
            }

            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return;
            }

            $event = $observer->getEvent();
            $item = $event->getQuoteItem();
            $product = $event->getProduct();

            $name = $item ? (string) $item->getName() : ($product ? (string) $product->getName() : '');
            if ($name === '') {
                return;
            }
            $qty = max(1, $item ? (int) $item->getQty() : 1);
            $price = $item ? (float) $item->getPrice() : ($product ? (float) $product->getFinalPrice() : 0.0);
            $productId = $product ? (int) $product->getId() : ($item ? (int) $item->getProductId() : 0);

            $dedupeKey = 'b2b_cart_add_' . $customerId . '_' . $productId . '_' . date('Ymd');
            if ($this->cache->load($dedupeKey)) {
                return;
            }

            $firstName = (string) $this->customerSession->getCustomer()->getFirstname();
            $message = sprintf(
                "🛒 *%s* colocou no carrinho: *%s* (x%d) — R$ %s. (aviso automático)",
                $firstName !== '' ? $firstName : 'Cliente',
                $name,
                $qty,
                number_format($price, 2, ',', '.')
            );

            // Grupo operacional (recebe sempre, mesmo sem vendedora atribuida)
            $this->groupNotifier->send($message);

            // Vendedora atribuida (individual)
            $attendant = $this->attendantManager->getCustomerAttendant($customerId);
            if ($attendant && !empty($attendant['whatsapp'])) {
                $this->whatsAppPublisher->publishText((string) $attendant['whatsapp'], $message);
            }

            $this->cache->save('1', $dedupeKey, [], 86400);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Carrinho→Vendedora] ' . $e->getMessage());
        }
    }
}
