<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Product;

/**
 * Itens que o Sectra marca como ativo+comercializado mas que NAO pertencem ao
 * ramo da loja. Ficam de fora da reativacao automatica.
 *
 * POR QUE ISTO EXISTE
 * A regra do escopo e booleana: `CCKATIVO='S' AND CKCOMERCIALIZA='S'`. Ela nao
 * distingue "manete de Titan" de "transformador de 225KVA" — e o cadastro do
 * Sectra tem os dois marcados igual. Medido em 06/10/2026, entre os produtos que
 * o escopo mandava vender havia:
 *
 *   50150102  MESA DE CORTE PARA VIDRO BASCULANTE 3200 X 2250MM
 *   50150162  TRANSFORMADOR 225KVA ALTA TENSAO 13800/10200 BAIXA
 *   55102952  PONTA BITS MAGNETICO 10MM PARA PARAFUSADEIRA
 *
 * Sem esta lista, basta alguem do ERP preencher um preco nesses tres para que
 * entrem em vigor na loja. O `tools/julgar-reativacao.php` os classificou como
 * `nao_vender_nao_e_moto` com confianca 1.00 / 0.99 / 0.45.
 *
 * O QUE ESTA LISTA **NAO** E
 * Nao e um segundo escopo nem uma regra de negocio escondida: e uma correcao de
 * dado que nao temos permissao de fazer no ERP. Cada entrada aponta o motivo, e
 * a lista deve encolher conforme o cadastro do Sectra for corrigido.
 *
 * IMPORTANTE: esta lista impede REATIVAR. Ela nao desativa nada que esteja ativo
 * por decisao humana — um produto aqui que o dono ligue a mao continua ligado.
 */
final class NonCatalogProductList
{
    /**
     * SKU => motivo (fica no codigo de proposito: e auditavel e versionado).
     *
     * @var array<string, string>
     */
    private const EXCLUDED = [
        '50150102' => 'Mesa de corte para vidro 3200x2250mm — equipamento industrial, nao e peca de moto',
        '50150162' => 'Transformador 225KVA alta tensao — equipamento eletrico industrial',
        '55102952' => 'Ponta bits magnetico 10mm para parafusadeira — ferramenta de outra area',
    ];

    /* string|int de proposito: as chaves de EXCLUDED sao SKUs numericos e o PHP as
     * converte para int, entao array_keys() devolve int e um cast rigido para string
     * estoura TypeError. Nao ha nada a proteger aqui — normalizar para string e
     * suficiente e elimina a classe inteira de erro. */
    public static function isExcluded(string|int $sku): bool
    {
        return isset(self::EXCLUDED[trim((string) $sku)]);
    }

    public static function reason(string|int $sku): ?string
    {
        return self::EXCLUDED[trim((string) $sku)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::EXCLUDED;
    }
}
