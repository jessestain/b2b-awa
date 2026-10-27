<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Psr\Log\LoggerInterface;

/**
 * Snapshot do carrinho REAL do Magento (quote), sempre somente leitura.
 *
 * Usado para: (a) resumo exibido no painel do catálogo; (b) reconciliação após
 * resposta incerta de adição. Nada é formatado/estimado no cliente.
 */
class CartService
{
    /**
     * Inicializa o serviço com o carrinho real e o helper de preço.
     *
     * @param Cart $cart
     * @param PriceHelper $priceHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Cart $cart,
        private readonly PriceHelper $priceHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Monta o snapshot somente leitura do carrinho real (quote).
     *
     * @return array{
     *   items_count: int,
     *   total_units: float,
     *   distinct_skus: int,
     *   items: array<int, array{sku: string, qty: float, name: string}>,
     *   subtotal: string,
     *   subtotal_value: float,
     *   shipping_unknown: bool
     * }
     */
    public function snapshot(): array
    {
        $snapshot = [
            'items_count' => 0,
            'total_units' => 0.0,
            'distinct_skus' => 0,
            'items' => [],
            'subtotal' => '',
            'subtotal_value' => 0.0,
            'shipping_unknown' => true,
        ];

        try {
            $quote = $this->cart->getQuote();
            $items = [];
            $skus = [];
            $totalUnits = 0.0;

            foreach ($quote->getAllVisibleItems() as $item) {
                $sku = (string) $item->getSku();
                $qty = (float) $item->getQty();
                $items[] = [
                    'sku' => $sku,
                    'qty' => $qty,
                    'name' => (string) $item->getName(),
                ];
                $totalUnits += $qty;
                $skus[mb_strtolower($sku)] = true;
            }

            $subtotal = (float) $quote->getSubtotal();
            if ($subtotal <= 0.0 && $items !== []) {
                // Fallback somente leitura: alguns add-to-cart paths não coletam
                // totais imediatamente; o valor é derivado dos itens do próprio
                // quote (qty × price), nunca estimado no cliente.
                $subtotal = 0.0;
                foreach ($quote->getAllVisibleItems() as $item) {
                    $rowTotal = (float) $item->getRowTotal();
                    $subtotal += $rowTotal > 0.0
                        ? $rowTotal
                        : ((float) $item->getQty() * (float) $item->getPrice());
                }
            }

            $snapshot['items'] = $items;
            $snapshot['items_count'] = count($items);
            $snapshot['total_units'] = $totalUnits;
            $snapshot['distinct_skus'] = count($skus);
            $snapshot['subtotal_value'] = $subtotal;
            $snapshot['subtotal'] = (string) $this->priceHelper->currency($subtotal, true, false);
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Cart snapshot failed.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $snapshot;
    }

    /**
     * Retorna o mapa de SKU (minúsculo) para a quantidade atual no carrinho.
     *
     * Usado para preencher o resultado por item após a confirmação do servidor.
     *
     * @return array<string,float>
     */
    public function qtyBySku(): array
    {
        $map = [];
        foreach ($this->snapshot()['items'] as $item) {
            $map[mb_strtolower((string) $item['sku'])] = (float) $item['qty'];
        }

        return $map;
    }
}
