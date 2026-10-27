<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Item 4 (lista de auditoria 2026-10-21): recuperacao apos perda da resposta
 * do pagamento/finalizacao.
 *
 * Quando a resposta de "finalizar pedido" se perde (timeout, queda de rede)
 * depois que o servidor ja criou o pedido, e o cliente recarrega a pagina e
 * tenta finalizar de novo o MESMO carrinho, o Magento ja aplica CartMutex e exige quote ativa no placeOrder.
 * Essas protecoes nao fornecem, sozinhas, reconciliacao da resposta perdida.
 * Este service confirma
 * no servidor, antes de deixar criar um pedido novo, se o quote ja foi
 * convertido num pedido existente.
 *
 * Nunca reenvia nada sozinho e nunca decide "deu certo ou errado" — so
 * verifica se ja existe um pedido com o increment_id reservado deste quote
 * e, se existir, devolve o id dele para o plugin evitar duplicidade.
 */
class OrderIdempotencyService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return int|null Entity id do pedido ja existente para este quote, ou null se nao existir.
     */
    public function findExistingOrderId(CartInterface $quote): ?int
    {
        // Quote ativo segue o fluxo normal protegido pelo CartMutex do Magento.
        // Isso nao prova ausencia de efeitos parciais em uma tentativa anterior.
        if ($quote->getIsActive()) {
            return null;
        }

        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            return null;
        }

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $reservedOrderId)
                ->addFilter('quote_id', (int) $quote->getId())
                ->addFilter('store_id', (int) $quote->getStoreId())
                ->create();
            $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        } catch (\Exception $e) {
            $this->logger->error(
                '[B2B][OrderIdempotency] Falha ao consultar pedido existente — seguindo fluxo padrao: '
                    . $e->getMessage(),
                ['exception' => $e, 'reserved_order_id' => $reservedOrderId]
            );
            // Em duvida, nao bloquear o checkout — segue para o fluxo normal do Magento.
            return null;
        }

        $order = reset($orders);
        // A reserved number alone does not identify this customer's quote.
        // Reject ambiguous or unrelated results without bypassing Magento.
        if (count($orders) !== 1 || !$order
            || (int) $order->getQuoteId() !== (int) $quote->getId()
            || (int) $order->getStoreId() !== (int) $quote->getStoreId()
            || (int) $order->getCustomerId() !== (int) $quote->getCustomerId()
            || (string) $order->getIncrementId() !== (string) $reservedOrderId
        ) {
            return null;
        }

        $this->logger->info(
            '[B2B][OrderIdempotency] Pedido ja existia para este quote — evitando duplicidade.',
            [
                'quote_id' => $quote->getId(),
                'reserved_order_id' => $reservedOrderId,
                'order_id' => $order->getEntityId(),
            ]
        );

        return (int) $order->getEntityId();
    }
}
