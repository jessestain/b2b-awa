<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model;

use GrupoAwamotos\AiAssistant\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Sliding-window rate limiter (1-hour window) persisted in the DB.
 *
 * Tracks by SHA-256(IP) to avoid storing raw IPs.
 */
class RateLimiter
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config             $config,
        private readonly LoggerInterface    $logger
    ) {
    }

    /**
     * Check allowance and increment counter atomically.
     *
     * Cliente logado usa bucket próprio (cust:{id}); visitante continua por IP.
     * Assim um NAT compartilhado não consome a cota do B2B, e um cliente
     * logado não herda o limite de outro IP.
     *
     * @return bool true if allowed, false if rate limit exceeded
     */
    public function checkAndIncrement(string $ipAddress, ?int $customerId = null): bool
    {
        $limit   = $this->config->getRateLimitPerHour();
        $bucket  = ($customerId !== null && $customerId > 0)
            ? 'cust:' . $customerId
            : 'ip:' . $ipAddress;
        $ipHash  = hash('sha256', $bucket);
        $conn    = $this->resource->getConnection();
        $table   = $this->resource->getTableName('grupoawamotos_ai_rate_limit');

        try {
            $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $row  = $conn->fetchRow(
                $conn->select()->from($table)->where('ip_hash = ?', $ipHash)
            );

            if ($row === false) {
                $conn->insert($table, [
                    'ip_hash'        => $ipHash,
                    'requests_count' => 1,
                    'window_start'   => $now->format('Y-m-d H:i:s'),
                ]);
                return true;
            }

            $windowStart = new \DateTimeImmutable($row['window_start'], new \DateTimeZone('UTC'));
            $elapsed     = $now->getTimestamp() - $windowStart->getTimestamp();

            if ($elapsed >= 3600) {
                $conn->update($table, [
                    'requests_count' => 1,
                    'window_start'   => $now->format('Y-m-d H:i:s'),
                ], ['ip_hash = ?' => $ipHash]);
                return true;
            }

            if ((int) $row['requests_count'] >= $limit) {
                return false;
            }

            $conn->update($table, [
                'requests_count' => new \Zend_Db_Expr('requests_count + 1'),
            ], ['ip_hash = ?' => $ipHash]);

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('[AiAssistant] RateLimiter DB error: ' . $e->getMessage());
            return true;
        }
    }
}
