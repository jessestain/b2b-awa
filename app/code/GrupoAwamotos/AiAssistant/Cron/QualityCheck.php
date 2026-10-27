<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Cron;

use GrupoAwamotos\AiAssistant\Model\Query\ProductQueryParser;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * QA periódica do assistente: erros recentes, latência e regressão do parser.
 * Somente leitura no banco + PHP puro. Alerta só via log dedicado.
 */
class QualityCheck
{
    private const ERROR_WINDOW_HOURS = 6;
    private const ERROR_ALERT_THRESHOLD = 3;
    private const LATENCY_ALERT_MS = 8000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('grupoawamotos_ai_conversation_log');
        $since = gmdate('Y-m-d H:i:s', time() - (self::ERROR_WINDOW_HOURS * 3600));

        $errorCount = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['cnt' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('created_at >= ?', $since)
                ->where('status IN (?)', ['llm_error', 'error'])
        );
        $avgLatency = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['avg_ms' => new \Zend_Db_Expr('AVG(latency_ms)')])
                ->where('created_at >= ?', $since)
                ->where('latency_ms IS NOT NULL')
        );
        $maxLatency = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['max_ms' => new \Zend_Db_Expr('MAX(latency_ms)')])
                ->where('created_at >= ?', $since)
                ->where('latency_ms IS NOT NULL')
        );

        $parserFailures = $this->parserRegressionFailures();
        $alerts = [];
        if ($errorCount >= self::ERROR_ALERT_THRESHOLD) {
            $alerts[] = 'errors=' . $errorCount . ' em ' . self::ERROR_WINDOW_HOURS . 'h';
        }
        if ($maxLatency >= self::LATENCY_ALERT_MS) {
            $alerts[] = 'latencia_max=' . $maxLatency . 'ms';
        }
        if ($parserFailures !== []) {
            $alerts[] = 'parser=' . implode(',', $parserFailures);
        }

        $payload = [
            'errors' => $errorCount,
            'avg_latency_ms' => $avgLatency,
            'max_latency_ms' => $maxLatency,
            'parser_failures' => $parserFailures,
        ];

        if ($alerts !== []) {
            $this->logger->error('[AiAssistant] QA ALERTA: ' . implode('; ', $alerts), $payload);
            return;
        }

        $this->logger->info('[AiAssistant] QA OK', $payload);
    }

    /**
     * @return string[]
     */
    private function parserRegressionFailures(): array
    {
        $parser = new ProductQueryParser();
        $cases = [
            'o que voce consegue fazer' => false,
            'qual a politica de troca e devolucao' => false,
            'qual o preco do SKU 1170' => true,
            'o que serve na fazer 250' => true,
            'sku 99999999' => true,
        ];
        $failures = [];
        foreach ($cases as $message => $expectProduct) {
            $intent = $parser->parse($message);
            $got = !empty($intent['is_product_query']);
            if ($got !== $expectProduct) {
                $failures[] = $message;
            }
        }

        return $failures;
    }
}
