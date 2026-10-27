<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Model\Pricing\CustomerPriceResolver;
use GrupoAwamotos\WhatsAppCommerce\Api\CatalogInterface;

/**
 * Tool: retorna detalhes de um produto pelo SKU.
 */
class ProductDetailTool implements ToolInterface
{
    public function __construct(
        private readonly CatalogInterface $catalog,
        private readonly CustomerPriceResolver $priceResolver
    ) {
    }

    public function getName(): string
    {
        return 'product_detail';
    }

    public function getDescription(): string
    {
        return 'Retorna os detalhes completos de um produto da AWA Motos: preço, estoque, descrição e URL. '
            . 'Use quando souber o SKU exato do produto.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'sku' => [
                    'type'        => 'string',
                    'description' => 'SKU exato do produto.',
                ],
            ],
            'required' => ['sku'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $sku = trim((string) ($arguments['sku'] ?? ''));
        if ($sku === '') {
            return ['error' => 'SKU não informado.'];
        }
        $data = $this->catalog->getProduct($sku);
        if (is_array($data) && empty($data['error'])) {
            $this->applyCustomerPrice($data, $context);
        }

        return $data;
    }

    /**
     * Cliente B2B logado com tabela própria no Sectra recebe o preço da lista
     * dele (mesma fonte da vitrine). Sem preço próprio, mantém o catálogo
     * (tabela NACIONAL sincronizada do Sectra).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function applyCustomerPrice(array &$data, array $context): void
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            return;
        }
        $resolved = $this->priceResolver->resolvePrices($context, [$sku]);
        if (!isset($resolved[$sku])) {
            return;
        }
        $data['price'] = $resolved[$sku]['price'];
        $data['price_formatted'] = $resolved[$sku]['price_formatted'];
        $data['price_source'] = $resolved[$sku]['price_source'];
        $data['price_note'] = $resolved[$sku]['price_note'];
    }

    public function getAllowedChannels(): array
    {
        return [
            AssistantOrchestratorInterface::CHANNEL_STOREFRONT,
            AssistantOrchestratorInterface::CHANNEL_B2B,
            AssistantOrchestratorInterface::CHANNEL_ADMIN,
        ];
    }
}
