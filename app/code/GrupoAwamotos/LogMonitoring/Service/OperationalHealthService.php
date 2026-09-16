<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Verificações operacionais reais para saúde da loja (CLI, cron, monitoramento).
 */
class OperationalHealthService
{
    /** @var array<string, string> Último security patch conhecido por linha minor (atualizar após releases Adobe). */
    private const LATEST_SECURITY_PATCHES = [
        '2.4.8' => '2.4.8-p5',
        '2.4.7' => '2.4.7-p10',
        '2.4.6' => '2.4.6-p15',
        '2.4.9' => '2.4.9',
    ];

    private ProductMetadataInterface $productMetadata;
    private IndexerCollectionFactory $indexerCollectionFactory;
    private ResourceConnection $resourceConnection;
    private DeploymentConfig $deploymentConfig;
    private LoggerInterface $logger;

    public function __construct(
        ProductMetadataInterface $productMetadata,
        IndexerCollectionFactory $indexerCollectionFactory,
        ResourceConnection $resourceConnection,
        DeploymentConfig $deploymentConfig,
        LoggerInterface $logger
    ) {
        $this->productMetadata = $productMetadata;
        $this->indexerCollectionFactory = $indexerCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->deploymentConfig = $deploymentConfig;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [
            $this->checkMagentoVersion(),
            $this->checkDeployMode(),
            $this->checkCompiledDi(),
            $this->checkIndexers(),
            $this->checkCron(),
            $this->checkRedis(),
            $this->checkOpenSearch(),
            $this->checkDisk(),
            $this->checkExceptionLog(),
            $this->checkDbLogger(),
        ];

        $failed = 0;
        $warnings = 0;

        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $failed++;
            } elseif ($check['status'] === 'warn') {
                $warnings++;
            }
        }

        $overall = 'ok';
        if ($failed > 0) {
            $overall = 'fail';
        } elseif ($warnings > 0) {
            $overall = 'warn';
        }

        return [
            'overall' => $overall,
            'failed' => $failed,
            'warnings' => $warnings,
            'checked_at' => date('c'),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMagentoVersion(): array
    {
        $current = $this->productMetadata->getVersion();
        $edition = $this->productMetadata->getEdition();
        $line = $this->resolveMinorLine($current);
        $latest = self::LATEST_SECURITY_PATCHES[$line] ?? null;

        $status = 'ok';
        $message = sprintf('Magento %s (%s)', $current, $edition);

        if ($latest === null) {
            $status = 'warn';
            $message .= ' — linha não mapeada no monitor AWA; verificar patch manualmente';
        } elseif ($this->comparePatchVersions($current, $latest) < 0) {
            $status = 'fail';
            $message .= sprintf(' — DESATUALIZADO (último patch: %s)', $latest);
        } else {
            $message .= ' — patch em dia';
        }

        return [
            'id' => 'magento_version',
            'label' => 'Versão Magento',
            'status' => $status,
            'message' => $message,
            'data' => [
                'current' => $current,
                'edition' => $edition,
                'latest_patch' => $latest,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDeployMode(): array
    {
        $mode = $this->deploymentConfig->get('MAGE_MODE') ?? 'unknown';
        $status = $mode === 'production' ? 'ok' : 'warn';

        return [
            'id' => 'deploy_mode',
            'label' => 'Modo de deploy',
            'status' => $status,
            'message' => $mode === 'production'
                ? 'Modo production'
                : sprintf('Modo %s — use production em produção', $mode),
            'data' => ['mode' => $mode],
        ];
    }

    /**
     * Production HTTP bootstraps via generated/metadata/global.php.
     * If that file is missing, FPM returns 500 and Varnish marks the backend sick
     * while CLI commands can still appear healthy.
     *
     * @return array<string, mixed>
     */
    private function checkCompiledDi(): array
    {
        $mode = (string)($this->deploymentConfig->get('MAGE_MODE') ?? '');
        $metadata = BP . '/generated/metadata/global.php';
        $present = is_file($metadata) && is_readable($metadata);

        if ($mode !== 'production') {
            return [
                'id' => 'compiled_di',
                'label' => 'DI compilado',
                'status' => 'ok',
                'message' => 'Checagem de metadata aplica-se só em production',
                'data' => [
                    'path' => $metadata,
                    'present' => $present,
                    'mode' => $mode,
                ],
            ];
        }

        if ($present) {
            $bytes = filesize($metadata);

            return [
                'id' => 'compiled_di',
                'label' => 'DI compilado',
                'status' => 'ok',
                'message' => 'generated/metadata/global.php presente',
                'data' => [
                    'path' => $metadata,
                    'bytes' => $bytes === false ? 0 : $bytes,
                ],
            ];
        }

        return [
            'id' => 'compiled_di',
            'label' => 'DI compilado',
            'status' => 'fail',
            'message' => 'generated/metadata/global.php ausente — HTTP 500 e Varnish sick. Rodar setup:di:compile',
            'data' => [
                'path' => $metadata,
                'present' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkIndexers(): array
    {
        $invalid = [];
        $notScheduled = [];

        foreach ($this->indexerCollectionFactory->create() as $indexer) {
            $state = (string)$indexer->getStatus();
            if ($state !== 'valid') {
                $invalid[] = $indexer->getId() . ':' . $state;
            }
            if (!$indexer->isScheduled()) {
                $notScheduled[] = $indexer->getId();
            }
        }

        $status = 'ok';
        $parts = ['Todos os indexadores Ready'];

        if ($invalid !== []) {
            $status = 'fail';
            $parts = ['Indexadores inválidos: ' . implode(', ', $invalid)];
        }

        if ($notScheduled !== []) {
            if ($status === 'ok') {
                $status = 'warn';
            }
            $parts[] = 'Fora de Schedule: ' . implode(', ', $notScheduled);
        }

        return [
            'id' => 'indexers',
            'label' => 'Indexadores',
            'status' => $status,
            'message' => implode(' | ', $parts),
            'data' => [
                'invalid' => $invalid,
                'not_scheduled' => $notScheduled,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCron(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');

        $lastSuccess = $connection->fetchOne(
            $connection->select()
                ->from($table, ['finished_at' => new Expression('MAX(finished_at)')])
                ->where('status = ?', 'success')
        );

        $recentErrors = (int)$connection->fetchOne(
            $connection->select()
                ->from($table, ['cnt' => new Expression('COUNT(*)')])
                ->where('status = ?', 'error')
                ->where('created_at >= ?', date('Y-m-d H:i:s', strtotime('-24 hours')))
        );

        $status = 'ok';
        $message = 'Cron ativo';

        if ($lastSuccess === false || $lastSuccess === null) {
            $status = 'fail';
            $message = 'Nenhum job cron concluído com sucesso';
        } else {
            $ageMinutes = (int)((time() - strtotime((string)$lastSuccess)) / 60);
            if ($ageMinutes > 15) {
                $status = 'fail';
                $message = sprintf('Último cron OK há %d min (>15 min)', $ageMinutes);
            } else {
                $message = sprintf('Último cron OK há %d min', $ageMinutes);
            }
        }

        if ($recentErrors > 0) {
            if ($status === 'ok') {
                $status = 'warn';
            }
            $message .= sprintf(' | %d erros nas últimas 24h', $recentErrors);
        }

        return [
            'id' => 'cron',
            'label' => 'Cron Magento',
            'status' => $status,
            'message' => $message,
            'data' => [
                'last_success' => $lastSuccess,
                'errors_24h' => $recentErrors,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        $host = (string)($this->deploymentConfig->get('cache/frontend/default/backend_options/server') ?: '::1');
        $port = (int)($this->deploymentConfig->get('cache/frontend/default/backend_options/port') ?: 6379);
        $password = $this->deploymentConfig->get('cache/frontend/default/backend_options/password');

        try {
            $redis = new \Redis();
            $redis->connect($host, $port, 2.5);
            if ($password) {
                $redis->auth((string)$password);
            }
            $pong = $redis->ping();
            $redis->close();

            $ok = $pong === true || $pong === '+PONG' || $pong === 'PONG';

            return [
                'id' => 'redis',
                'label' => 'Redis',
                'status' => $ok ? 'ok' : 'fail',
                'message' => $ok ? 'Redis respondendo (cache DB1)' : 'Redis ping inesperado',
                'data' => ['host' => $host, 'port' => $port],
            ];
        } catch (\Throwable $e) {
            return [
                'id' => 'redis',
                'label' => 'Redis',
                'status' => 'fail',
                'message' => 'Redis indisponível: ' . $e->getMessage(),
                'data' => ['host' => $host, 'port' => $port],
            ];
        }
    }

    /**
     * Resolve OpenSearch endpoint from Magento config (core_config_data), then env.php.
     *
     * @return array{0: string, 1: string} [hostname, port]
     */
    private function resolveOpenSearchEndpoint(): array
    {
        $hostname = null;
        $port = null;

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('core_config_data');
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from($table, ['path', 'value'])
                    ->where('scope = ?', 'default')
                    ->where('path IN (?)', [
                        'catalog/search/opensearch_server_hostname',
                        'catalog/search/opensearch_server_port',
                    ])
            );
            if (is_array($rows)) {
                $hostname = isset($rows['catalog/search/opensearch_server_hostname'])
                    ? trim((string) $rows['catalog/search/opensearch_server_hostname'])
                    : null;
                $port = isset($rows['catalog/search/opensearch_server_port'])
                    ? trim((string) $rows['catalog/search/opensearch_server_port'])
                    : null;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[OperationalHealthService::resolveOpenSearchEndpoint] Falha ao ler core_config_data, '
                . 'usando deploymentConfig/defaults: ' . $e->getMessage()
            );
        }

        if ($hostname === null || $hostname === '') {
            $hostname = (string) ($this->deploymentConfig->get(
                'system/default/catalog/search/opensearch_server_hostname'
            ) ?: 'localhost');
        }
        if ($port === null || $port === '') {
            $port = (string) ($this->deploymentConfig->get(
                'system/default/catalog/search/opensearch_server_port'
            ) ?: '9200');
        }

        return [$hostname, $port];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOpenSearch(): array
    {
        [$hostname, $port] = $this->resolveOpenSearchEndpoint();
        $host = $hostname . ':' . $port;
        $url = 'http://' . $host . '/_cluster/health';

        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return [
                'id' => 'opensearch',
                'label' => 'OpenSearch',
                'status' => 'fail',
                'message' => 'OpenSearch indisponível em ' . $host,
                'data' => ['url' => $url],
            ];
        }

        /** @var array<string, mixed>|null $health */
        $health = json_decode($body, true);
        $clusterStatus = is_array($health) ? (string)($health['status'] ?? 'unknown') : 'unknown';

        $status = in_array($clusterStatus, ['green', 'yellow'], true) ? 'ok' : 'fail';
        if ($clusterStatus === 'yellow') {
            $status = 'warn';
        }

        return [
            'id' => 'opensearch',
            'label' => 'OpenSearch',
            'status' => $status,
            'message' => sprintf('Cluster %s', $clusterStatus),
            'data' => [
                'cluster_status' => $clusterStatus,
                'url' => $url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDisk(): array
    {
        $free = disk_free_space(BP);
        $total = disk_total_space(BP);
        $usedPct = ($total > 0 && $free !== false)
            ? round((($total - $free) / $total) * 100, 1)
            : 0.0;

        $status = 'ok';
        if ($usedPct >= 90) {
            $status = 'fail';
        } elseif ($usedPct >= 80) {
            $status = 'warn';
        }

        return [
            'id' => 'disk',
            'label' => 'Disco',
            'status' => $status,
            'message' => sprintf('Uso do disco: %.1f%%', $usedPct),
            'data' => ['used_percent' => $usedPct],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkExceptionLog(): array
    {
        $logFile = BP . '/var/log/exception.log';
        if (!is_readable($logFile)) {
            return [
                'id' => 'exception_log',
                'label' => 'Exception log',
                'status' => 'ok',
                'message' => 'exception.log ausente ou vazio',
                'data' => ['recent_errors' => 0],
            ];
        }

        $sizeMb = round(filesize($logFile) / 1024 / 1024, 1);
        $recentErrors = $this->countRecentLogLines($logFile, 3600);

        $status = 'ok';
        if ($recentErrors > 20) {
            $status = 'fail';
        } elseif ($recentErrors > 5 || $sizeMb > 100) {
            $status = 'warn';
        }

        return [
            'id' => 'exception_log',
            'label' => 'Exception log',
            'status' => $status,
            'message' => sprintf('%d entradas na última hora | arquivo %.1f MB', $recentErrors, $sizeMb),
            'data' => [
                'recent_errors' => $recentErrors,
                'size_mb' => $sizeMb,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDbLogger(): array
    {
        $output = (string)($this->deploymentConfig->get('db_logger/output') ?: 'unknown');
        $enabled = $output !== 'disabled';

        return [
            'id' => 'db_logger',
            'label' => 'DB Logger',
            'status' => $enabled ? 'warn' : 'ok',
            'message' => $enabled
                ? 'db_logger ativo — risco de db.log gigante; desabilitar em produção'
                : 'db_logger desabilitado',
            'data' => ['output' => $output],
        ];
    }

    private function resolveMinorLine(string $version): string
    {
        if (preg_match('/^(2\.4\.\d+)/', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    /**
     * Compara patches no formato 2.4.8-p3 vs 2.4.8-p5.
     */
    private function comparePatchVersions(string $current, string $latest): int
    {
        $normalize = static function (string $version): array {
            if (preg_match('/^(2\.4\.\d+)(?:-p(\d+))?/', $version, $m)) {
                return [$m[1], isset($m[2]) ? (int)$m[2] : 0];
            }

            return [$version, 0];
        };

        [$curLine, $curPatch] = $normalize($current);
        [$latLine, $latPatch] = $normalize($latest);

        if ($curLine !== $latLine) {
            return strcmp($curLine, $latLine);
        }

        return $curPatch <=> $latPatch;
    }

    private function countRecentLogLines(string $file, int $seconds): int
    {
        if (!is_readable($file)) {
            return 0;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }

        $cutoff = time() - $seconds;
        $count = 0;
        $chunkSize = 8192;
        $buffer = '';

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        if ($pos === false) {
            fclose($handle);
            return 0;
        }

        while ($pos > 0) {
            $read = min($chunkSize, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $chunk = fread($handle, $read);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            while (($newlinePos = strrpos($buffer, "\n")) !== false) {
                $line = substr($buffer, $newlinePos + 1);
                $buffer = substr($buffer, 0, $newlinePos);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts !== false && $ts >= $cutoff) {
                        $count++;
                    } elseif ($ts !== false && $ts < $cutoff) {
                        fclose($handle);
                        return $count;
                    }
                }
            }
        }

        fclose($handle);
        return $count;
    }
}
