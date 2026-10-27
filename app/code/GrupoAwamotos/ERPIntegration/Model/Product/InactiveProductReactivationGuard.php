<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Product;

/**
 * Guarda da reativacao — o lado espelhado de InactiveProductDeactivationGuard.
 *
 * POR QUE ESTA CLASSE EXISTE
 * O sync sabia DESATIVAR quem sai do escopo do Sectra, mas nunca REATIVAR quem
 * volta. O resultado medido em 06/10/2026: 27 produtos estavam inativos com o
 * Sectra dizendo CCKATIVO='S' AND CKCOMERCIALIZA='S' — e nada os trazia de volta.
 * Vendas perdidas em silencio.
 *
 * REATIVAR E A DIRECAO PERIGOSA
 * Desativar por engano esconde um produto; REATIVAR por engano COLOCA A VENDA algo
 * que nao deveria. Por isso esta guarda e mais estrita que a de desativacao:
 *
 *   1. Mesma regra base: se a consulta ao Sectra voltou vazia (ou sem nenhum ativo),
 *      e falha de conectividade, nao "todos os SKUs ficaram vendaveis".
 *
 *   2. Preco: um produto com preco de placeholder (R$ 0,01) nao pode ir para a loja.
 *      Medido: 21 dos 27 tinham exatamente R$ 0,01 no ERP. Vender a R$ 0,01 e prejuizo
 *      certo. O piso e 1,00 — o catalogo tem itens legitimamente baratos (uma lente a
 *      R$ 0,97) e R$ 0,01 e claramente um campo nao preenchido, nao um preco.
 *
 *   3. Preco ausente: mesmo tratamento do placeholder.
 *
 * O que esta guarda NAO decide: se o item e ou nao do ramo da loja. Isso e julgamento
 * semantico (o Sectra tem "MESA DE CORTE PARA VIDRO 3200" marcada como comercializada)
 * e foi resolvido a parte, com o Jev, em tools/julgar-reativacao.php.
 */
final class InactiveProductReactivationGuard
{
    /**
     * Abaixo disto o preco e tratado como placeholder, nao como preco.
     * Ver a medicao no cabecalho: R$ 0,01 foi o valor encontrado em 21 dos 27.
     */
    public const PRICE_FLOOR = 1.00;

    /**
     * A consulta ao Sectra trouxe dados utilizaveis?
     * Mesma condicao da guarda de desativacao, pelo mesmo motivo.
     */
    public static function shouldReactivate(int $allErpCount, int $activeErpCount): bool
    {
        return $allErpCount > 0 && $activeErpCount > 0;
    }

    /**
     * O preco permite colocar este produto a venda?
     *
     * @param float|null $price preco no Magento; null quando o atributo nao existe
     */
    public static function hasUsablePrice(?float $price): bool
    {
        return $price !== null && $price >= self::PRICE_FLOOR;
    }
}
