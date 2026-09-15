<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Service\CustomerGroupManager;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

/**
 * Detecta troca de grupo B2B (pendente → aprovado) e atualiza a sessão ativa
 * do cliente sem exigir logout manual.
 *
 * Fluxo: admin aprova → CustomerGroupManager grava flag no cache →
 * na próxima request do cliente este observer limpa o flag e recarrega
 * os dados do cliente na sessão, incluindo o novo group_id.
 */
class CustomerGroupRefresh implements ObserverInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly PhpCookieManager $cookieManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        // FPC: não chamar CustomerSession em visitantes sem cookie (evita session_start em PLP/home).
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return;
        }

        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $cacheKey   = CustomerGroupManager::CACHE_KEY_PREFIX . $customerId;

        $newGroupId = $this->cache->load($cacheKey);
        if ($newGroupId === false) {
            return; // sem flag — nada a fazer
        }

        try {
            /* Remove o flag ANTES de qualquer operação para evitar loop */
            $this->cache->remove($cacheKey);

            $sessionGroupId = (int) $this->customerSession->getCustomer()->getGroupId();
            if ($sessionGroupId === (int) $newGroupId) {
                return; // sessão já está atualizada
            }

            /* Recarrega dados do cliente do DB e atualiza a sessão */
            $customer = $this->customerRepository->getById($customerId);
            $this->customerSession->setCustomerDataAsLoggedIn($customer);

            $this->logger->info(sprintf(
                '[B2B] Session group refreshed for customer %d: %d → %d',
                $customerId,
                $sessionGroupId,
                (int) $newGroupId
            ));
        } catch (\Exception $e) {
            $this->logger->warning('[B2B] CustomerGroupRefresh falhou: ' . $e->getMessage());
        }
    }
}
