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
 * Tool (Admin): radar da carteira — quem ligar hoje, quem reativar, quem
 * está para recomprar, quem são os maiores. Dados reais do ERP, limitados à
 * carteira de quem pergunta (supervisão/admin veem a base toda).
 */
class ErpPortfolioRadarTool implements ToolInterface
{
    private const FOCUS = ['ligar_hoje', 'reativar', 'recompra_proxima', 'maiores'];

    public function __construct(
        private readonly ErpPortfolioReader $reader,
        private readonly PortfolioScope $portfolioScope,
        private readonly CustomerHealth $health,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'erp_portfolio_radar';
    }

    public function getDescription(): string
    {
        return 'Radar da carteira com dados reais do ERP (24 meses). focus: "ligar_hoje" (padrão: clientes '
            . 'atrasados/esfriando que mais valem — quem ligar primeiro), "reativar" (parados há muito tempo), '
            . '"recompra_proxima" (em dia, recompra esperada em até 7 dias), "maiores" (maior faturamento 12 meses). '
            . 'Retorna lista priorizada com motivo, dias sem comprar, ciclo e ação sugerida, além do resumo por status.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'focus' => [
                    'type'        => 'string',
                    'enum'        => self::FOCUS,
                    'description' => 'Tipo de lista. Padrão: ligar_hoje.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Quantos clientes listar (1 a 20, padrão 10).',
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $focus = in_array($arguments['focus'] ?? '', self::FOCUS, true) ? $arguments['focus'] : 'ligar_hoje';
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 10)));

        try {
            $codes = $this->reader->scopeCodes($this->portfolioScope);
            if ($codes === []) {
                return ['error' => 'Sua carteira não tem clientes com código no ERP.'];
            }
            $stats = $this->reader->orderStats($codes);

            $rows = [];
            $byStatus = [];
            foreach ($stats as $s) {
                $h = $this->health->classify($s['orders'], $s['first'], $s['last']);
                $byStatus[$h['status']] = ($byStatus[$h['status']] ?? 0) + 1;
                $rows[] = ['s' => $s, 'h' => $h, 'p' => $this->health->priority($h, $s['value_12m'])];
            }

            $rows = array_values(array_filter($rows, static fn (array $r): bool => match ($focus) {
                'ligar_hoje'       => $r['p'] > 0 && in_array($r['h']['status'], [CustomerHealth::ATRASADO, CustomerHealth::ESFRIANDO], true),
                'reativar'         => in_array($r['h']['status'], [CustomerHealth::ESFRIANDO, CustomerHealth::INATIVO], true),
                'recompra_proxima' => $r['p'] > 0 && in_array($r['h']['status'], [CustomerHealth::EM_DIA, CustomerHealth::NOVO], true),
                default            => $r['s']['value_12m'] > 0,
            }));
            usort($rows, static fn (array $a, array $b): int => match ($focus) {
                'maiores', 'reativar' => $b['s']['value_12m'] <=> $a['s']['value_12m'],
                'recompra_proxima'    => strcmp((string) $a['h']['next_expected'], (string) $b['h']['next_expected']),
                default               => $b['p'] <=> $a['p'],
            });
            $rows = array_slice($rows, 0, $limit);

            $codesShown = array_map(static fn (array $r): int => $r['s']['code'], $rows);
            $names = $this->reader->erpNames($codesShown);
            $ids = $this->reader->magentoIdsForCodes($codesShown);

            return [
                'focus'           => $focus,
                'scope'           => $codes === null ? 'base inteira' : 'sua carteira',
                'customers_in_scope_with_orders_24m' => count($stats),
                'by_status'       => $byStatus,
                'customers'       => array_map(fn (array $r): array => [
                    'customer_id'     => $ids[$r['s']['code']] ?? null,
                    'erp_code'        => $r['s']['code'],
                    'name'            => $names[$r['s']['code']]['name'] ?? ('cliente ERP ' . $r['s']['code']),
                    'city'            => $names[$r['s']['code']]['city'] ?? '',
                    'status'          => $r['h']['status_label'],
                    'days_since_last' => $r['h']['days_since_last'],
                    'cycle_days'      => $r['h']['cycle_days'],
                    'next_expected'   => $r['h']['next_expected'],
                    'orders_24m'      => $r['s']['orders'],
                    'value_12m'       => $r['s']['value_12m'],
                    'suggested_action' => $r['h']['suggested_action'],
                ], $rows),
                'note'            => 'Clientes sem nenhum pedido em 24 meses não aparecem aqui. Para o detalhe '
                    . 'de um cliente e o que oferecer, chame erp_customer_overview com o customer_id.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[AiAssistant] erp_portfolio_radar: ' . $e->getMessage());
            return ['error' => 'ERP indisponível no momento. Tente de novo em instantes.'];
        }
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
