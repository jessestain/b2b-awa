<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Model\Copilot\CustomerHealth;
use GrupoAwamotos\AiAssistant\Model\Copilot\ErpPortfolioReader;
use GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope;
use Psr\Log\LoggerInterface;

/**
 * Tool (Admin): visão 360° de UM cliente da carteira com dados reais do ERP —
 * ritmo de compra, status, últimos pedidos, tendência e o que oferecer.
 * Somente leitura; respeita a carteira (PortfolioScope).
 */
class ErpCustomerOverviewTool implements ToolInterface
{
    public function __construct(
        private readonly ErpPortfolioReader $reader,
        private readonly PortfolioScope $portfolioScope,
        private readonly CustomerHealth $health,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'erp_customer_overview';
    }

    public function getDescription(): string
    {
        return 'Raio-X de um cliente com o histórico REAL de compras do ERP (pedidos de balcão, '
            . 'representante e site): total comprado, ciclo médio entre pedidos, dias sem comprar, '
            . 'status (em dia/atrasado/esfriando/inativo), últimos pedidos, tendência mensal, produtos '
            . 'habituais e o que oferecer na recompra. Use para "como está o cliente X", "o que ofereço '
            . 'para X", "quando X comprou". Aceita nome, nome fantasia, razão social, CNPJ ou código do cliente.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'customer' => [
                    'type'        => 'string',
                    'description' => 'Nome, nome fantasia, razão social, customer_id ou CNPJ do cliente. '
                        . 'CNPJ chega na conversa como token (ex.: CNPJ_REF_1): passe o token como está.',
                ],
            ],
            'required'   => ['customer'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $found = $this->reader->resolveCustomer((string) ($arguments['customer'] ?? ''), $this->portfolioScope);
        if (isset($found['error'])) {
            return ['error' => $found['error']];
        }
        if (isset($found['candidates'])) {
            return [
                'ambiguous'  => true,
                'candidates' => $found['candidates'],
                'note'       => 'Mais de um cliente encontrado. Pergunte qual deles (ou use o customer_id).',
            ];
        }

        $codes = $found['erp_codes'] ?? [];
        if ($codes === []) {
            return [
                'customer_id' => $found['customer_id'],
                'name'        => $found['name'],
                'erp_linked'  => false,
                'note'        => 'Cliente sem código no ERP: ainda não sincronizado ou nunca comprou fora do site.',
            ];
        }

        try {
            $stats = $this->reader->orderStats($codes);
            $orders = array_sum(array_column($stats, 'orders'));
            $firsts = array_filter(array_column($stats, 'first'));
            $lasts  = array_filter(array_column($stats, 'last'));
            $health = $this->health->classify(
                $orders,
                $firsts ? min($firsts) : null,
                $lasts ? max($lasts) : null
            );
            $products = $this->reader->productHistory($codes);
            $erpName = $this->reader->erpNames([$codes[0]])[$codes[0]] ?? null;

            return [
                'customer_id'     => $found['customer_id'],
                'name'            => $erpName['name'] ?? $found['name'],
                'city'            => $erpName['city'] ?? '',
                'erp_codes'       => $codes,
                'window'          => 'últimos 24 meses (pedidos não cancelados)',
                'orders_24m'      => $orders,
                'value_12m'       => round(array_sum(array_column($stats, 'value_12m')), 2),
                'first_order_24m' => $firsts ? min($firsts) : null,
                'last_order'      => $lasts ? max($lasts) : null,
                'health'          => $health,
                'last_orders'     => $this->reader->lastOrders($codes, 5),
                'monthly_trend'   => $this->reader->monthlyTrend($codes, 6),
                'usual_products'  => array_map(static fn (array $p): array => [
                    'sku'          => trim((string) $p['sku']),
                    'name'         => trim((string) $p['name']),
                    'times_bought' => (int) $p['order_count'],
                    'days_since_last' => (int) $p['days_since_last'],
                ], array_slice($products, 0, 5)),
                'reorder_pitch'   => $this->health->reorderCandidates($products, $health['cycle_days']),
                'note'            => 'Status de pedido são códigos do ERP Sectra (ex.: E, S, V) — cite o código, '
                    . 'não invente o significado. Valores em R$. "reorder_pitch" = itens habituais que já passaram '
                    . 'do ciclo do cliente: sugira oferecer na ligação.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[AiAssistant] erp_customer_overview: ' . $e->getMessage());
            return ['error' => 'ERP indisponível no momento. Tente de novo em instantes.'];
        }
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
