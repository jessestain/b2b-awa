<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Copilot;

/**
 * "Saúde" de compra de um cliente B2B a partir do histórico do ERP.
 *
 * Classe pura (sem Magento) para poder ser testada isoladamente. Responde
 * três perguntas do vendedor: o cliente está no ritmo dele? quem eu priorizo?
 * o que ofereço?
 *
 * Ciclo = intervalo médio entre pedidos (span / (pedidos - 1)). Atraso =
 * dias sem comprar / ciclo. Cliente com 1 pedido não tem ciclo: usa faixas
 * fixas de dias.
 */
class CustomerHealth
{
    public const SEM_COMPRAS = 'sem_compras';
    public const NOVO        = 'novo';
    public const EM_DIA      = 'em_dia';
    public const ATRASADO    = 'atrasado';
    public const ESFRIANDO   = 'esfriando';
    public const INATIVO     = 'inativo';

    private const MIN_CYCLE_DAYS = 7;
    private const INACTIVE_DAYS  = 365;

    private const LABELS = [
        self::SEM_COMPRAS => 'sem compras no período',
        self::NOVO        => 'cliente novo (1 pedido recente)',
        self::EM_DIA      => 'em dia com o ritmo de compra',
        self::ATRASADO    => 'atrasado em relação ao ritmo dele',
        self::ESFRIANDO   => 'esfriando — bem acima do ciclo normal',
        self::INATIVO     => 'inativo',
    ];

    private const ACTIONS = [
        self::SEM_COMPRAS => 'Apresentar a AWA e entender a demanda; não há histórico para comparar.',
        self::NOVO        => 'Fazer o pós-venda do primeiro pedido e oferecer a segunda compra.',
        self::EM_DIA      => 'Acompanhar; preparar a próxima recompra perto da data esperada.',
        self::ATRASADO    => 'Ligar esta semana: passou do ciclo normal, oferecer a recompra dos itens habituais.',
        self::ESFRIANDO   => 'Prioridade de contato: entender o motivo (preço, estoque, concorrente) antes de perder o cliente.',
        self::INATIVO     => 'Campanha de reativação; checar se o cadastro e a tabela de preço seguem válidos.',
    ];

    /**
     * @return array{
     *     status: string, status_label: string, suggested_action: string,
     *     orders: int, days_since_last: int|null, cycle_days: int|null,
     *     overdue_ratio: float|null, next_expected: string|null
     * }
     */
    public function classify(int $orders, ?string $firstDate, ?string $lastDate, ?\DateTimeInterface $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $first = $this->toDate($firstDate);
        $last  = $this->toDate($lastDate);

        if ($orders <= 0 || $last === null) {
            return $this->result(self::SEM_COMPRAS, 0, null, null, null, null);
        }

        $daysSince = max(0, (int) $last->diff($today)->format('%r%a'));

        if ($orders === 1 || $first === null) {
            $status = $daysSince <= 45 ? self::NOVO
                : ($daysSince <= 120 ? self::ATRASADO
                : ($daysSince <= self::INACTIVE_DAYS ? self::ESFRIANDO : self::INATIVO));
            return $this->result($status, $orders, $daysSince, null, null, null);
        }

        $span  = max(0, (int) $first->diff($last)->format('%a'));
        $cycle = max(self::MIN_CYCLE_DAYS, (int) round($span / ($orders - 1)));
        $ratio = round($daysSince / $cycle, 2);

        if ($daysSince > self::INACTIVE_DAYS || $ratio > 4.0) {
            $status = self::INATIVO;
        } elseif ($ratio > 2.0) {
            $status = self::ESFRIANDO;
        } elseif ($ratio > 1.2) {
            $status = self::ATRASADO;
        } else {
            $status = self::EM_DIA;
        }

        $next = \DateTimeImmutable::createFromInterface($last)->modify('+' . $cycle . ' days')->format('Y-m-d');

        return $this->result($status, $orders, $daysSince, $cycle, $ratio, $next);
    }

    /**
     * Prioridade de contato para o radar: quanto vale × quanto passou do
     * ciclo. Inativo pesa menos (custa mais reativar); em dia/novo entram só
     * se a recompra está a até 7 dias.
     */
    public function priority(array $health, float $value12m): float
    {
        $value = max(0.0, $value12m);
        $ratio = (float) ($health['overdue_ratio'] ?? 0.0);

        return round(match ($health['status']) {
            self::ATRASADO  => $value * max(1.0, $ratio),
            self::ESFRIANDO => $value * min(4.0, max(2.0, $ratio)),
            self::INATIVO   => $value * 0.5,
            self::EM_DIA, self::NOVO => $this->dueSoon($health) ? $value * 0.8 : 0.0,
            default => 0.0,
        }, 2);
    }

    /**
     * Itens que o cliente compra com regularidade e que já passaram do ciclo
     * dele — o que oferecer na ligação.
     *
     * Sem item recorrente (cliente que nunca repetiu um item), cai para os de
     * maior volume já vencidos, marcados como "compra_unica" — o vendedor sabe
     * que é palpite, não hábito.
     *
     * @param array<int, array<string, mixed>> $items linhas de ErpPortfolioReader::productHistory()
     * @return array<int, array{sku: string, name: string, times_bought: int, days_since_last: int, avg_price: float, basis: string}>
     */
    public function reorderCandidates(array $items, ?int $cycleDays, int $limit = 5): array
    {
        $threshold = max(30, (int) ($cycleDays ?? 30));
        $regular = [];
        $single = [];
        foreach ($items as $item) {
            $times = (int) ($item['order_count'] ?? 0);
            $days  = (int) ($item['days_since_last'] ?? 0);
            if ($times < 1 || $days < $threshold) {
                continue;
            }
            $row = [
                'sku'             => trim((string) ($item['sku'] ?? '')),
                'name'            => trim((string) ($item['name'] ?? '')),
                'times_bought'    => $times,
                'days_since_last' => $days,
                'avg_price'       => round((float) ($item['avg_price'] ?? 0), 2),
                'basis'           => $times >= 2 ? 'habito' : 'compra_unica',
                '_qty'            => (float) ($item['total_qty'] ?? 0),
            ];
            if ($times >= 2) {
                $regular[] = $row;
            } else {
                $single[] = $row;
            }
        }
        usort($regular, static fn (array $a, array $b): int =>
            [$b['times_bought'], $b['days_since_last']] <=> [$a['times_bought'], $a['days_since_last']]);
        usort($single, static fn (array $a, array $b): int => $b['_qty'] <=> $a['_qty']);

        $picked = array_slice($regular !== [] ? $regular : $single, 0, $limit);

        return array_map(static function (array $r): array {
            unset($r['_qty']);
            return $r;
        }, $picked);
    }

    public function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    private function dueSoon(array $health, ?\DateTimeInterface $today = null): bool
    {
        if (empty($health['next_expected'])) {
            return false;
        }
        $today ??= new \DateTimeImmutable('today');
        $next = $this->toDate((string) $health['next_expected']);

        return $next !== null && (int) $today->diff($next)->format('%r%a') <= 7;
    }

    private function toDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(substr(trim($value), 0, 10)))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function result(
        string $status,
        int $orders,
        ?int $daysSince,
        ?int $cycle,
        ?float $ratio,
        ?string $next
    ): array {
        return [
            'status'           => $status,
            'status_label'     => self::LABELS[$status],
            'suggested_action' => self::ACTIONS[$status],
            'orders'           => $orders,
            'days_since_last'  => $daysSince,
            'cycle_days'       => $cycle,
            'overdue_ratio'    => $ratio,
            'next_expected'    => $next,
        ];
    }
}
