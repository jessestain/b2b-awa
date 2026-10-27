<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

/**
 * Normalizador puro de parâmetros de entrada das rotas do catálogo.
 *
 * Sem dependências de framework: instanciável em testes puros (não estático,
 * para permitir intercepção/DI conforme padrão Magento).
 */
class RequestInputNormalizer
{
    public const DEFAULT_PAGE_SIZE = 24;
    public const MAX_PAGE_SIZE = 48;

    /**
     * Página mínima 1.
     *
     * @param int $page
     * @return int
     */
    public function clampPage(int $page): int
    {
        return max(1, $page);
    }

    /**
     * Limite entre 1 e MAX_PAGE_SIZE.
     *
     * @param int $limit
     * @return int
     */
    public function clampLimit(int $limit): int
    {
        return max(1, min(self::MAX_PAGE_SIZE, $limit));
    }

    /**
     * Verifica se o product_id é válido para favoritos (inteiro positivo).
     *
     * @param int $productId
     * @return bool
     */
    public function isValidProductId(int $productId): bool
    {
        return $productId > 0;
    }
}
