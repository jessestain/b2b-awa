<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\Model\OrderIdempotencyService;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Mesma protecao de GrupoAwamotos\B2B\Plugin\Checkout\OrderIdempotencyPlugin,
 * para o endpoint de checkout de convidado (cart id mascarado em vez de int).
 * Ver OrderIdempotencyPlugin para a explicacao completa.
 */
class GuestOrderIdempotencyPlugin
{
    public function __construct(
        private readonly GuestCartRepositoryInterface $guestCartRepository,
        private readonly OrderIdempotencyService $orderIdempotencyService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param GuestPaymentInformationManagementInterface $subject
     * @param \Closure $proceed
     * @param string $cartId
     * @param string $email
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     * @return int
     */
    public function aroundSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagementInterface $subject,
        \Closure $proceed,
        $cartId,
        $email,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null
    ) {
        $existingOrderId = $this->findExistingOrderId((string) $cartId);

        if ($existingOrderId !== null) {
            return $existingOrderId;
        }

        return $proceed($cartId, $email, $paymentMethod, $billingAddress);
    }

    private function findExistingOrderId(string $cartId): ?int
    {
        try {
            $quote = $this->guestCartRepository->get($cartId);
        } catch (\Exception $e) {
            return null;
        }

        try {
            return $this->orderIdempotencyService->findExistingOrderId($quote);
        } catch (\Exception $e) {
            $this->logger->error(
                '[B2B][GuestOrderIdempotencyPlugin] Erro inesperado ao checar duplicidade — seguindo fluxo padrao: '
                    . $e->getMessage(),
                ['exception' => $e, 'masked_cart_id' => $cartId]
            );

            return null;
        }
    }
}
