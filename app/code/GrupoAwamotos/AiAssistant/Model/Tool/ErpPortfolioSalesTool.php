<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Model\Copilot\ErpPortfolioReader;
use GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope;
use Psr\Log\LoggerInterface;

/**
 * Tool (Admin): vendas reais do ERP no período (carteira ou base toda),
 * comparadas com o período anterior de mesmo tamanho.
 */
class ErpPortfolioSalesTool implements ToolInterface
{
    public function __construct(
        private readonly ErpPortfolioReader $reader,
        private readonly PortfolioScope $portfolioScope,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'erp_portfolio_sales';
    }

    public function getDescription(): string
    {
        return 'Vendas REAIS do ERP (todos os canais: balcão, representante e site) da carteira de quem '
            . 'pergunta — supervisão/admin veem a empresa toda: pedidos, clientes que compraram, faturamento, '
            . 'ticket médio, variação contra o período anterior, top 5 clientes e top 5 produtos. '
            . 'Prefira esta tool a admin_sales para perguntas de vendas/faturamento/desempenho.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'days' => [
                    'type'        => 'integer',
                    'description' => 'Período em dias contando hoje (1 = hoje, 7 = semana, 30 = padrão, até 365).',
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));

        try {
            $codes = $this->reader->scopeCodes($this->portfolioScope);
            if ($codes === []) {
                return ['error' => 'Sua carteira não tem clientes com código no ERP.'];
            }
            $s = $this->reader->salesSummary($codes, $days);

            $topCodes = array_column($s['top_customers'], 'code');
            $names = $this->reader->erpNames($topCodes);
            $ids = $this->reader->magentoIdsForCodes($topCodes);

            return [
                'scope'          => $codes === null ? 'empresa toda' : 'sua carteira',
                'period_days'    => $days,
                'orders'         => $s['orders'],
                'customers'      => $s['customers'],
                'revenue'        => $s['revenue'],
                'avg_ticket'     => $s['orders'] > 0 ? round($s['revenue'] / $s['orders'], 2) : 0.0,
                'previous_period' => $s['previous'],
                'revenue_change_pct' => $this->pct($s['revenue'], (float) $s['previous']['revenue']),
                'status_breakdown' => $s['status_breakdown'],
                'top_customers'  => array_map(static fn (array $c): array => [
                    'customer_id' => $ids[$c['code']] ?? null,
                    'erp_code'    => $c['code'],
                    'name'        => $names[$c['code']]['name'] ?? ('cliente ERP ' . $c['code']),
                    'orders'      => $c['orders'],
                    'revenue'     => $c['revenue'],
                ], $s['top_customers']),
                'top_products'   => $s['top_products'],
                'note'           => 'Fonte: ERP Sectra. orders/revenue = pedidos efetivados (E) + em andamento (V), '
                    . 'sem cancelados. status_breakdown mostra à parte: "unconfirmed_batch" = lote de pedidos S '
                    . '(filial 1, 1 por cliente, lançado em out/2026) que NÃO entra no total — cite separado e '
                    . 'diga que a natureza dele não foi confirmada; "cancelled" = cancelados no período. '
                    . 'Pedidos recentes ainda podem ser cancelados: não afirme crescimento só pelo período atual '
                    . 'se "in_progress" for grande. Se revenue_change_pct for null, não havia vendas antes.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[AiAssistant] erp_portfolio_sales: ' . $e->getMessage());
            return ['error' => 'ERP indisponível no momento. Tente de novo em instantes.'];
        }
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }

    private function pct(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
