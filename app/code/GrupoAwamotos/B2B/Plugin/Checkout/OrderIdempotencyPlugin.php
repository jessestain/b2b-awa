<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\Model\OrderIdempotencyService;
use Magento\Checkout\Api\PaymentInformationManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Psr\Log\LoggerInterface;

/**
 * Item 4 (lista de auditoria 2026-10-21): evita pedido duplicado quando a
 * resposta da finalizacao se perde (timeout/queda de rede) depois que o
 * servidor ja criou o pedido, e o cliente tenta finalizar de novo o mesmo
 * carrinho. Roda ANTES de qualquer outro plugin de checkout (sortOrder="1"
 * em di.xml) para que, se um pedido ja existir, nada mais neste fluxo
 * execute — nenhuma validacao de PO/data/nota roda, nenhum pedido novo e
 * criado, o metodo original (Magento) nem chega a ser chamado.
 *
 * NUNCA reenvia nada sozinho: so verifica, e se achar que o quote ja virou
 * pedido, devolve o id desse pedido existente em vez de criar outro.
 */
class OrderIdempotencyPlugin
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly OrderIdempotencyService $orderIdempotencyService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param PaymentInformationManagementInterface $subject
     * @param \Closure $proceed
     * @param int $cartId
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     * @return int
     */
    public function aroundSavePaymentInformationAndPlaceOrder(
        PaymentInformationManagementInterface $subject,
        \Closure $proceed,
        $cartId,
        PaymentInterface $paymentMethod,
        ?AddressInterface $billingAddress = null
    ) {
        $existingOrderId = $this->findExistingOrderId((int) $cartId);

        if ($existingOrderId !== null) {
            return $existingOrderId;
        }

        return $proceed($cartId, $paymentMethod, $billingAddress);
    }

    private function findExistingOrderId(int $cartId): ?int
    {
        try {
            $quote = $this->cartRepository->get($cartId);
        } catch (\Exception $e) {
            // Quote nao encontrado aqui nao e um caso de duplicidade — deixa o
            // fluxo original do Magento lidar com o erro normalmente.
            return null;
        }

        try {
            return $this->orderIdempotencyService->findExistingOrderId($quote);
        } catch (\Exception $e) {
            $this->logger->error(
                '[B2B][OrderIdempotencyPlugin] Erro inesperado ao checar duplicidade — seguindo fluxo padrao: '
                    . $e->getMessage(),
                ['exception' => $e, 'cart_id' => $cartId]
            );

            return null;
        }
    }
}
