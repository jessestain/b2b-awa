<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\Sectra\ProspectEvent;
use GrupoAwamotos\B2B\Model\Sectra\SectraSyncLogger;
use GrupoAwamotos\B2B\Model\Sectra\ValidatorChecker;
use GrupoAwamotos\ERPIntegration\Api\B2bOrderPullCustomerDataInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address;
use Psr\Log\LoggerInterface;

/**
 * Valida endereço de entrega B2B e registra clientes pendentes no validador Sectra.
 */
class ValidateB2bOrderErpReadinessObserver implements ObserverInterface
{
    public function __construct(
        private readonly B2bOrderPullCustomerDataInterface $orderPullCustomerData,
        private readonly ValidatorChecker $validatorChecker,
        private readonly SectraSyncLogger $syncLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getQuote();
        if (!$quote instanceof CartInterface) {
            return;
        }

        $customerId = (int) ($quote->getCustomerId() ?? 0);
        if ($customerId <= 0 || !$this->orderPullCustomerData->isApprovedB2bCustomer($customerId)) {
            return;
        }

        if (!$this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
            $this->syncLogger->log(
                ProspectEvent::CHECKOUT_BLOCKED_CUSTOMER_NOT_VALIDATED,
                'Cliente ainda não consta no validador; o Sectra validará no Importar Pedidos.',
                $customerId,
                null,
                null,
                $sectraChave
            );
        }

        /** @phpstan-ignore-next-line */
        $shipping = $quote->getShippingAddress();
        if (!$shipping instanceof Address) {
            throw new LocalizedException(__('Endereço de entrega é obrigatório para pedidos B2B.'));
        }

        $street = implode(' ', array_filter($shipping->getStreet() ?? []));
        if (trim($street) === '' || trim((string) $shipping->getCity()) === ''
            || trim((string) $shipping->getPostcode()) === ''
            || trim((string) $shipping->getRegion()) === ''
        ) {
            $message = (string) __('Endereço de entrega incompleto (rua, cidade, UF e CEP).');
            $this->logger->warning(sprintf('[B2B-ERP-Pull] Pedido bloqueado — customer #%d: %s', $customerId, $message));
            throw new LocalizedException(__($message));
        }
    }
}
