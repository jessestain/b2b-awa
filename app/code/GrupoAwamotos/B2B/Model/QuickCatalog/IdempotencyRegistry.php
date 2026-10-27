<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Registro de idempotência dos lotes de adição ao carrinho.
 *
 * - Chave sempre escopada por customer_id (nunca compartilha resultado entre contas).
 * - Somente respostas com efeito real no carrinho (added >= 1) são gravadas:
 *   falha total NÃO vira resultado válido em cache ("não armazenar falhas como
 *   resultado válido").
 * - Se o cache estiver indisponível, o fluxo prossegue (a reconciliação do
 *   carrinho cobre a resposta incerta); a falha é logada e nunca engole exceção
 *   silenciosamente.
 */
class IdempotencyRegistry
{
    private const PREFIX = 'awa_qc_add';
    private const TTL_SECONDS = 600;

    /**
     * Inicializa o registro com o cache e o logger.
     *
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Monta a chave de idempotência escopada por cliente e request_id.
     *
     * @param int $customerId
     * @param string $requestId
     * @return string
     */
    public function buildKey(int $customerId, string $requestId): string
    {
        return self::PREFIX . '|' . $customerId . '|' . hash('sha256', $requestId);
    }

    /**
     * Carrega o resultado previamente gravado para a chave informada.
     *
     * @param string $key
     * @return array<string,mixed>|null
     */
    public function load(string $key): ?array
    {
        try {
            $payload = $this->cache->load($key);
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Idempotency cache read failed.', [
                'exception' => $exception->getMessage(),
            ]);
            return null;
        }

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Grava o resultado do lote quando houve adição efetiva ao carrinho.
     *
     * @param string $key
     * @param array<string,mixed> $result
     */
    public function save(string $key, array $result): void
    {
        if (($result['added'] ?? []) === []) {
            return;
        }

        try {
            $this->cache->save(
                (string) json_encode($result),
                $key,
                ['awa_qc_idempotency'],
                self::TTL_SECONDS
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Idempotency cache write failed.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
