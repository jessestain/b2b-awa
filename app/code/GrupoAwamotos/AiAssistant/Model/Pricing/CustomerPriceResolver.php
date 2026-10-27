<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Pricing;

use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;

/**
 * Preço B2B por tabela ERP (Sectra) para as tools do assistente.
 *
 * Contrato de segurança — MESMA semântica da vitrine:
 *  - CustomerPriceProvider::getCustomerPrices() devolve null/omite quando o
 *    cliente NÃO tem lista própria, tem a lista padrão (NACIONAL), a lista não
 *    tem o SKU ou o ERP está indisponível. Nesses casos o preço base do
 *    catálogo É a tabela NACIONAL sincronizada do Sectra — nunca substituímos
 *    por outro valor.
 *  - Só substituímos quando existe preço próprio da lista do cliente.
 *  - Flag `ai_assistant/b2b/use_erp_price`: OFF por padrão (comportamento
 *    atual); ligar por config após validação no HML.
 */
class CustomerPriceResolver
{
    public function __construct(
        private readonly CustomerPriceProvider $customerPriceProvider,
        private readonly ErpCodeResolver $erpCodeResolver,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $context Contexto da tool (is_b2b, customer_id).
     * @param string[] $skus
     * @return array<string, array{price: float, price_formatted: string, price_source: string, price_note: string}>
     *         SKU => preço da tabela própria do cliente (somente os que diferem do catálogo).
     */
    public function resolvePrices(array $context, array $skus): array
    {
        if (!$this->config->getUseErpPrice()) {
            return [];
        }
        if (empty($context['is_b2b']) || (int) ($context['customer_id'] ?? 0) <= 0) {
            return [];
        }
        $skus = array_values(array_filter(
            array_map(static fn(string $sku): string => trim($sku), $skus),
            static fn(string $sku): bool => $sku !== ''
        ));
        if ($skus === []) {
            return [];
        }

        $customerId = (int) $context['customer_id'];
        $erpCode = $this->erpCodeResolver->resolveForCustomerId($customerId);
        if ($erpCode === null) {
            return [];
        }

        $prices = $this->customerPriceProvider->getCustomerPrices($erpCode, $skus);
        $out = [];
        foreach ($prices as $sku => $price) {
            if ($price === null) {
                continue;
            }
            $out[(string) $sku] = [
                'price' => (float) $price,
                'price_formatted' => 'R$ ' . number_format((float) $price, 2, ',', '.'),
                'price_source' => 'sectra',
                'price_note' => 'Sua tabela (Sectra)',
            ];
        }

        return $out;
    }
}
