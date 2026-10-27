<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Toggle de favoritos do Catálogo de Compra Rápida.
 *
 * - Escopo SEMPRE por customer_id (nunca cruza contas).
 * - Linha existe → DELETE (favorite=false); senão → INSERT
 *   (UNIQUE(customer_id, product_id) — um favorito por conta/produto).
 * - Tabela ausente (ex.: antes do setup:upgrade) → fail-closed: success=false
 *   + log; o controller responde reason:'error' (nunca resposta falsa de sucesso).
 * - Sem registro de idempotência: operação reversível; guard de duplo clique é
 *   responsabilidade do cliente (CONTRACT §12.3).
 */
class FavoriteService
{
    /**
     * Inicializa o serviço com a conexão de banco e o logger.
     *
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Toggle do favorito: linha existente → DELETE; inexistente → INSERT.
     *
     * @param int $customerId
     * @param int $productId
     * @return array{success:bool,favorite:bool}
     */
    public function toggle(int $customerId, int $productId): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(CatalogItemPresenter::FAVORITE_TABLE);

            if (!$connection->isTableExists($table)) {
                $this->logger->error('[B2B QuickCatalog] Favorites table missing on toggle.', [
                    'customer_id' => $customerId,
                    'product_id' => $productId,
                    'table' => $table,
                ]);

                return ['success' => false, 'favorite' => false];
            }

            $existing = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['favorito_id'])
                    ->where('customer_id = ?', $customerId)
                    ->where('product_id = ?', $productId)
                    ->limit(1)
            );

            if ($existing) {
                $connection->delete(
                    $table,
                    ['customer_id = ?' => $customerId, 'product_id = ?' => $productId]
                );

                return ['success' => true, 'favorite' => false];
            }

            $connection->insert($table, [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return ['success' => true, 'favorite' => true];
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Favorite toggle failed.', [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);

            return ['success' => false, 'favorite' => false];
        }
    }
}
