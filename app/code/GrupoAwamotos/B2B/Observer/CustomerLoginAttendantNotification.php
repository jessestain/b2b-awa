<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Service\GroupNotifier;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Notifica (WhatsApp) o login de cliente B2B:
 *  - vendedora atribuida (individual, se houver);
 *  - grupo operacional configurado (sempre que configurado, independente de vendedora).
 *
 * Guardas: flag grupoawamotos_b2b/whatsapp/notify_attendant_on_login (default 0),
 * dedupe 1/login por cliente por dia (cache 24h), try/catch total.
 */
class CustomerLoginAttendantNotification implements ObserverInterface
{
    private const CONFIG_PATH_ENABLED = 'grupoawamotos_b2b/whatsapp/notify_attendant_on_login';
    private const CACHE_PREFIX = 'b2b_attendant_login_';
    private const DEDUPE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AttendantManager $attendantManager,
        private readonly WhatsAppPublisher $whatsAppPublisher,
        private readonly CacheInterface $cache,
        private readonly TimezoneInterface $timezone,
        private readonly GroupNotifier $groupNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
                return;
            }

            /** @var Customer|null $customer */
            $customer = $observer->getEvent()->getCustomer();
            $customerId = $customer ? (int) $customer->getId() : 0;
            if ($customerId <= 0) {
                return;
            }

            $cacheKey = self::CACHE_PREFIX . $customerId . '_' . date('Ymd');
            if ($this->cache->load($cacheKey)) {
                return;
            }

            $message = $this->buildMessage($customer);

            // Grupo operacional (recebe sempre, mesmo sem vendedora atribuida)
            $this->groupNotifier->send($message);

            // Vendedora atribuida (individual)
            $attendant = $this->attendantManager->getCustomerAttendant($customerId);
            if ($attendant && !empty($attendant['whatsapp'])) {
                $this->whatsAppPublisher->publishText((string) $attendant['whatsapp'], $message);
            }

            $this->cache->save('1', $cacheKey, [], self::DEDUPE_TTL_SECONDS);
        } catch (\Throwable $e) {
            // Nunca quebra o login do cliente.
            $this->logger->error('[B2B Login→Vendedora] Erro ao notificar: ' . $e->getMessage());
        }
    }

    private function buildMessage(Customer $customer): string
    {
        $name = trim($customer->getFirstname() ?: 'Cliente');
        $razaoAttr = $customer->getCustomAttribute('b2b_razao_social');
        $empresa = $razaoAttr ? trim((string) $razaoAttr->getValue()) : '';
        $hora = $this->timezone->date()->format('H:i');

        $message = "👤 *{$name}*";
        if ($empresa !== '') {
            $message .= " ({$empresa})";
        }
        $message .= " acabou de entrar na loja às {$hora}.";

        return $message;
    }
}
