<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Customer;

/**
 * Decisao pura: um cliente aprovado no site deve ser MARCADO para revisao quando o
 * Sectra diz que ele nao e mais cliente ativo.
 *
 * POR QUE EXISTE
 * Medido em 16/10/2026 (b2b:audit:erp-readiness): 723 clientes aprovados no site
 * estao bloqueados no Sectra (703 com ATCLIENTE != 'S', 20 cujo codigo nao existe
 * mais em FN_FORNECEDORES). O preco de lista especifica desses clientes ja e
 * cortado em CustomerPriceProvider::getCustomerPriceListCode (cai no preco base
 * NACIONAL). Esta guarda decide SO a marcacao administrativa.
 *
 * ASSIMETRIA DE RISCO (mesmo principio das guardas de produto)
 * Bloquear um cliente real por engano e pior que deixar um cliente desativado
 * comprando mais um pouco no preco base. Por isso a acao desta guarda nunca vai
 * alem de "marcar para revisao humana": nunca despromove b2b_approval_status,
 * nunca bloqueia checkout, nunca altera grupo de cliente ou preco.
 */
final class CustomerErpStatusGuard
{
    public const REASON_NOT_FOUND = 'nao_encontrado_no_sectra';
    public const REASON_INACTIVE = 'inativo_no_sectra';

    /**
     * @param bool $foundInErp o codigo existe em FN_FORNECEDORES?
     * @param string|null $ckCliente CKCLIENTE do Sectra, quando encontrado
     * @param string|null $atCliente ATCLIENTE do Sectra, quando encontrado
     * @return string|null motivo da marcacao, ou null se o cliente esta em ordem
     */
    public static function reasonToFlag(bool $foundInErp, ?string $ckCliente, ?string $atCliente): ?string
    {
        if (!$foundInErp) {
            return self::REASON_NOT_FOUND;
        }

        if ($ckCliente !== 'S' || $atCliente !== 'S') {
            return self::REASON_INACTIVE;
        }

        return null;
    }

    /**
     * Mesmo limite de seguranca das guardas de produto (CatalogPublicationGuard):
     * volume de candidatos acima disto indica falha de consulta ao Sectra, nao uma
     * onda real de clientes desativados no mesmo dia.
     */
    public static function withinSafetyLimit(int $candidates, int $totalChecked, float $maxRatio): bool
    {
        if ($candidates <= 0) {
            return true;
        }
        if ($totalChecked <= 0) {
            return false;
        }

        return ($candidates / $totalChecked) <= $maxRatio;
    }
}
