<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adição individual e em lote ao carrinho, com resultado por item e idempotência.
 *
 * Garantias:
 * - Nenhum item é adicionado sem revalidação server-side por SKU.
 * - Clique duplicado / retry com o mesmo request_id não duplica itens.
 * - Sucesso parcial é devolvido explicitamente (added + errors).
 * - Falha total não é gravada no cache de idempotência.
 * - Carrinho devolvido é o quote real do Magento após o save.
 */
class AddService
{
    /**
     * Inicializa o serviço com as dependências de carrinho e validação.
     *
     * @param AuthorizationService $authorizationService
     * @param Cart $cart
     * @param ProductAccessValidator $productAccessValidator
     * @param ItemBatchNormalizer $normalizer
     * @param IdempotencyRegistry $idempotencyRegistry
     * @param CartService $cartService
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly Cart $cart,
        private readonly ProductAccessValidator $productAccessValidator,
        private readonly ItemBatchNormalizer $normalizer,
        private readonly IdempotencyRegistry $idempotencyRegistry,
        private readonly CartService $cartService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Adiciona itens ao carrinho com idempotência e resultado por item.
     *
     * @param array<int,mixed> $rawItems
     * @param string $requestId
     * @return array<string,mixed>
     */
    public function add(array $rawItems, string $requestId): array
    {
        $context = $this->authorizationService->resolve();

        if (!$context['allowed']) {
            return [
                'success' => false,
                'reason' => $context['reason'],
                'message' => $context['message'],
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ];
        }

        if (!$context['can_add']) {
            return [
                'success' => false,
                'reason' => AuthorizationService::REASON_CANNOT_ADD,
                'message' => (string) __('Sua conta não pode adicionar produtos ao carrinho neste momento.'),
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ];
        }

        if (!$this->normalizer->isValidRequestId($requestId)) {
            return [
                'success' => false,
                'reason' => 'invalid_request_id',
                'message' => (string) __('Requisição inválida. Recarregue a página e tente novamente.'),
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ];
        }

        $items = $this->normalizer->normalize($rawItems);

        if ($items === []) {
            return [
                'success' => false,
                'reason' => 'empty_items',
                'message' => (string) __('Informe pelo menos um produto para adicionar.'),
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ];
        }

        $customerId = (int) $context['customer_id'];
        $idempotencyKey = $this->idempotencyRegistry->buildKey($customerId, $requestId);
        $cached = $this->idempotencyRegistry->load($idempotencyKey);

        if ($cached !== null) {
            $cached['replayed'] = true;

            return $cached;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $aggregator = new AddResultAggregator();

        foreach ($items as $item) {
            $sku = (string) $item['sku'];
            $qty = (int) $item['qty'];
            $aggregator->startItem($sku);

            $validation = $this->productAccessValidator->validate($sku, $storeId);
            if (!$validation['allowed'] || $validation['product'] === null) {
                $aggregator->addError($sku, (string) $validation['message']);
                continue;
            }

            try {
                $this->cart->addProduct($validation['product'], ['qty' => $qty]);
                // Registra o sucesso por item (sem isto hasAdded() fica falso e o
                // carrinho não é persistido — bug encontrado no E2E do HML).
                $aggregator->addSuccess($sku, $qty, (string) $validation['product']->getName(), 0);
            } catch (LocalizedException $exception) {
                $aggregator->addError($sku, (string) $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->logger->error('[B2B QuickCatalog] Add to cart failed.', [
                    'customer_id' => $customerId,
                    'sku' => $sku,
                    'exception' => $exception->getMessage(),
                ]);
                $aggregator->addError($sku, (string) __('Erro ao adicionar produto.'));
            }
        }

        $result = $aggregator->toArray();

        if ($aggregator->hasAdded()) {
            try {
                $this->cart->save();
            } catch (\Throwable $exception) {
                // O save é o ponto de persistência: sem ele nada foi confirmado.
                $this->logger->error('[B2B QuickCatalog] Cart save failed after add.', [
                    'customer_id' => $customerId,
                    'exception' => $exception->getMessage(),
                ]);
                $this->cartService->snapshot();

                return [
                    'success' => false,
                    'reason' => 'cart_save_failed',
                    'message' => (string) __('Não foi possível salvar o carrinho agora. Tente novamente.'),
                    'added' => [],
                    'errors' => $result['errors'],
                    'processed' => $result['processed'],
                    'summary' => ['added' => 0, 'failed' => count($result['errors']) + count($result['added'])],
                ];
            }
        }

        $snapshot = $this->cartService->snapshot();
        $qtyBySku = [];
        foreach ($snapshot['items'] as $cartItem) {
            $qtyBySku[mb_strtolower((string) $cartItem['sku'])] = (float) $cartItem['qty'];
        }

        $added = [];
        foreach ($result['added'] as $addedItem) {
            $addedItem['cart_qty_sku'] = (int) ($qtyBySku[mb_strtolower((string) $addedItem['sku'])] ?? 0);
            $added[] = $addedItem;
        }
        $result['added'] = $added;

        $response = [
            'success' => $aggregator->hasAdded(),
            'replayed' => false,
            'reason' => null,
            'message' => $aggregator->hasAdded()
                ? (string) __('%1 item(ns) adicionado(s) ao carrinho.', count($added))
                : (string) __('Nenhum item foi adicionado.'),
            'added' => $result['added'],
            'errors' => $result['errors'],
            'processed' => $result['processed'],
            'summary' => $result['summary'],
            'cart' => $this->cartPayload($snapshot),
        ];

        $this->idempotencyRegistry->save($idempotencyKey, $response);

        return $response;
    }

    /**
     * Monta o payload público do carrinho a partir do snapshot.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function cartPayload(array $snapshot): array
    {
        return [
            'items_count' => $snapshot['items_count'],
            'total_units' => $snapshot['total_units'],
            'distinct_skus' => $snapshot['distinct_skus'],
            'subtotal' => $snapshot['subtotal'],
            'subtotal_value' => $snapshot['subtotal_value'],
            'shipping_unknown' => true,
        ];
    }
}
