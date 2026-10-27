<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Service\LogAnalyzer;

use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterfaceFactory;
use GrupoAwamotos\LogMonitoring\Api\AlertRepositoryInterface;
use GrupoAwamotos\LogMonitoring\Api\Data\LogMetricsInterfaceFactory;
use GrupoAwamotos\LogMonitoring\Api\LogMetricsRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

class ErpAnalyzer implements AnalyzerInterface
{
    private Filesystem $filesystem;
    private LogMetricsRepositoryInterface $logMetricsRepository;
    private LogMetricsInterfaceFactory $logMetricsFactory;
    private AlertRepositoryInterface $alertRepository;
    private AlertInterfaceFactory $alertFactory;
    private LoggerInterface $logger;

    public function __construct(
        Filesystem $filesystem,
        LogMetricsRepositoryInterface $logMetricsRepository,
        LogMetricsInterfaceFactory $logMetricsFactory,
        AlertRepositoryInterface $alertRepository,
        AlertInterfaceFactory $alertFactory,
        LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->logMetricsRepository = $logMetricsRepository;
        $this->logMetricsFactory = $logMetricsFactory;
        $this->alertRepository = $alertRepository;
        $this->alertFactory = $alertFactory;
        $this->logger = $logger;
    }

    public function analyze(): array
    {
        $logDir = $this->filesystem->getDirectoryRead(DirectoryList::LOG);
        $logFiles = [
            'awa_erp.log',
            'system.log',
            'exception.log',
        ];

        $analysis = [];

        foreach ($logFiles as $logFile) {
            if ($logDir->isExist($logFile)) {
                $analysis[$logFile] = $this->analyzeLogFile($logDir, $logFile);
            }
        }

        $this->generateErpAlerts($analysis);

        return $analysis;
    }

    public function getSpecificMetrics(): array
    {
        $metrics = $this->logMetricsRepository->getMetricsByType('erp', 10);

        $erpMetrics = [
            'sync_rate' => $this->calculateSyncRate($metrics),
            'error_rate' => $this->calculateErrorRate($metrics),
            'performance_score' => $this->calculatePerformanceScore($metrics),
            'health_score' => 100,
            'last_sync' => $this->getLastSyncTime(),
            'failed_operations' => $this->getFailedOperations($metrics),
            'critical_issues' => $this->getCriticalIssues()
        ];

        // Calculate overall health score
        $erpMetrics['health_score'] = $this->calculateHealthScore($erpMetrics);

        return $erpMetrics;
    }

    public function checkHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'score' => 100
        ];

        // Check recent ERP errors
        $recentMetrics = $this->logMetricsRepository->getMetricsByType('erp', 5);
        $errorCount = 0;

        foreach ($recentMetrics as $metric) {
            $errorCount += $metric->getErrorEntries();
        }

        if ($errorCount > 50) {
            $health['status'] = 'critical';
            $health['issues'][] = 'High error rate in ERP synchronization';
            $health['score'] -= 30;
        } elseif ($errorCount > 20) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Moderate error rate in ERP synchronization';
            $health['score'] -= 15;
        }

        // Check sync frequency
        $lastSync = $this->getLastSyncTime();
        if (strtotime($lastSync) < strtotime('-1 hour')) {
            $health['status'] = 'warning';
            $health['issues'][] = 'ERP sync appears to be delayed';
            $health['score'] -= 10;
        }

        return $health;
    }

    public function generateAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getSpecificMetrics();

        // High error rate alert
        if ($metrics['error_rate'] > 0.1) { // More than 10% errors
            $alerts[] = $this->createAlert(
                'high_erp_error_rate',
                'critical',
                'High ERP Error Rate Detected',
                sprintf('ERP error rate is %.2f%%, indicating potential integration issues', $metrics['error_rate'] * 100),
                ['error_rate' => $metrics['error_rate']]
            );
        }

        // Low sync rate alert
        if ($metrics['sync_rate'] < 0.8) { // Less than 80% sync rate
            $alerts[] = $this->createAlert(
                'low_erp_sync_rate',
                'high',
                'Low ERP Sync Rate',
                sprintf('ERP sync rate is %.2f%%, below expected threshold', $metrics['sync_rate'] * 100),
                ['sync_rate' => $metrics['sync_rate']]
            );
        }

        return $alerts;
    }

    private function analyzeLogFile(\Magento\Framework\Filesystem\Directory\ReadInterface $logDir, string $logFile): array
    {
        try {
            $stat = $logDir->stat($logFile);
            $fileSize = (int)($stat['size'] ?? 0);

            // Read at most 200 KB from the end of large files to avoid OOM
            $maxBytes = 204800;
            $position = $fileSize > $maxBytes ? $fileSize - $maxBytes : 0;
            // ReadInterface::readFile() 3rd param is stream context, not offset;
            // use native file_get_contents($path, false, null, $offset) instead
            $absolutePath = $logDir->getAbsolutePath($logFile);
            $content = file_get_contents($absolutePath, false, null, $position);
            if ($content === false) {
                throw new \RuntimeException('Cannot read log file: ' . $logFile);
            }

            $lines = explode("\n", $content);

            $analysis = [
                'total_lines' => count($lines),
                'error_lines' => 0,
                'warning_lines' => 0,
                'critical_lines' => 0,
                'erp_specific' => [
                    'sync_errors' => 0,
                    'nosuchentity_errors' => 0,
                    'api_timeouts' => 0,
                    'integration_failures' => 0
                ],
                'file_size' => $fileSize,
                'patterns' => []
            ];

            foreach ($lines as $line) {
                // General log level analysis
                if (stripos($line, '[ERROR]') !== false) {
                    $analysis['error_lines']++;
                }
                if (stripos($line, '[WARNING]') !== false) {
                    $analysis['warning_lines']++;
                }
                if (stripos($line, '[CRITICAL]') !== false) {
                    $analysis['critical_lines']++;
                }

                // ERP specific patterns
                if (stripos($line, 'NoSuchEntityException') !== false) {
                    $analysis['erp_specific']['nosuchentity_errors']++;
                }
                if (stripos($line, 'ERP sync') !== false && stripos($line, 'error') !== false) {
                    $analysis['erp_specific']['sync_errors']++;
                }
                if (stripos($line, 'timeout') !== false && stripos($line, 'api') !== false) {
                    $analysis['erp_specific']['api_timeouts']++;
                }
                if (stripos($line, 'integration') !== false && stripos($line, 'failed') !== false) {
                    $analysis['erp_specific']['integration_failures']++;
                }
            }

            // Save metrics
            $logMetrics = $this->logMetricsFactory->create();
            $logMetrics->setLogType('erp');
            $logMetrics->setSourceFile($logFile);
            $logMetrics->setTotalEntries($analysis['total_lines']);
            $logMetrics->setErrorEntries($analysis['error_lines']);
            $logMetrics->setWarningEntries($analysis['warning_lines']);
            $logMetrics->setCriticalEntries($analysis['critical_lines']);
            $logMetrics->setFileSizeBytes($analysis['file_size']);
            $logMetrics->setAnalysisData($analysis);

            $this->logMetricsRepository->save($logMetrics);

            return $analysis;
        } catch (\Throwable $e) {
            $this->logger->error('Error analyzing ERP log file: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function generateErpAlerts(array $analysis): void
    {
        foreach ($analysis as $file => $data) {
            if (isset($data['error'])) {
                continue;
            }

            // Alert on high NoSuchEntityException count
            if ($data['erp_specific']['nosuchentity_errors'] > 10) {
                $this->createAndSaveAlert(
                    'nosuchentity_spike',
                    'high',
                    'High NoSuchEntityException Count',
                    sprintf(
                        'Detected %d NoSuchEntityException errors in %s',
                        $data['erp_specific']['nosuchentity_errors'],
                        $file
                    ),
                    ['file' => $file, 'count' => $data['erp_specific']['nosuchentity_errors']]
                );
            }

            // Alert on sync errors
            if ($data['erp_specific']['sync_errors'] > 5) {
                $this->createAndSaveAlert(
                    'erp_sync_errors',
                    'critical',
                    'ERP Sync Errors Detected',
                    sprintf(
                        'Found %d ERP sync errors in %s',
                        $data['erp_specific']['sync_errors'],
                        $file
                    ),
                    ['file' => $file, 'count' => $data['erp_specific']['sync_errors']]
                );
            }
        }
    }

    private function createAlert(string $type, string $severity, string $title, string $message, array $context): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context
        ];
    }

    private function createAndSaveAlert(string $type, string $severity, string $title, string $message, array $context): void
    {
        try {
            /* DEDUPE NA ORIGEM (task-49, 10/10/2026). Antes desta guarda o metodo criava uma linha
               NOVA a cada rodada do cron, de 15 em 15 minutos: a tabela acumulou 157 linhas de
               nosuchentity_spike, 15 abertas, entrando 1 nova a cada 15 minutos. Agora, existindo
               alerta ABERTO do mesmo tipo+severidade+fonte, ele e INCREMENTADO.
               NAO tocamos em notified_at de proposito: a coluna pertence ao cooldown do consumidor
               (Cron/AlertProcessor.php, NOTIFY_COOLDOWN_HOURS = 6) e reescreve-la reabriria o
               flood de e-mail corrigido em 10/10/2026. */
            $existente = $this->alertRepository->getLatestOpenAlert($type, $severity, 'erp_analyzer');

            if ($existente !== null) {
                $existente->setOccurrences(((int) $existente->getOccurrences()) + 1);
                $existente->setMessage($message);
                $existente->setContextData($context);
                $existente->setLastOccurrence(date('Y-m-d H:i:s'));
                $this->alertRepository->save($existente);

                return;
            }

            $alert = $this->alertFactory->create();
            $alert->setAlertType($type);
            $alert->setSeverity($severity);
            $alert->setTitle($title);
            $alert->setMessage($message);
            $alert->setContextData($context);
            $alert->setSource('erp_analyzer');
            $alert->setStatus('open');
            $alert->setOccurrences(1);
            $alert->setFirstOccurrence(date('Y-m-d H:i:s'));
            $alert->setLastOccurrence(date('Y-m-d H:i:s'));

            $this->alertRepository->save($alert);
        } catch (\Exception $e) {
            $this->logger->error('Error creating alert: ' . $e->getMessage());
        }
    }

    private function calculateSyncRate(array $metrics): float
    {
        if (empty($metrics)) {
            return 1.0;
        }

        $totalOperations = 0;
        $successfulOperations = 0;

        foreach ($metrics as $metric) {
            $total = $metric->getTotalEntries();
            $errors = $metric->getErrorEntries();

            $totalOperations += $total;
            $successfulOperations += ($total - $errors);
        }

        return $totalOperations > 0 ? $successfulOperations / $totalOperations : 1.0;
    }

    private function calculateErrorRate(array $metrics): float
    {
        if (empty($metrics)) {
            return 0.0;
        }

        $totalEntries = 0;
        $totalErrors = 0;

        foreach ($metrics as $metric) {
            $totalEntries += $metric->getTotalEntries();
            $totalErrors += $metric->getErrorEntries();
        }

        return $totalEntries > 0 ? $totalErrors / $totalEntries : 0.0;
    }

    private function calculatePerformanceScore(array $metrics): float
    {
        // Based on error rate and sync frequency
        $errorRate = $this->calculateErrorRate($metrics);
        $baseScore = 100;

        // Reduce score based on error rate
        $baseScore -= ($errorRate * 100 * 0.5);

        return max(0, $baseScore);
    }

    private function calculateHealthScore(array $metrics): float
    {
        $score = 100;

        // Reduce score based on various factors
        if ($metrics['error_rate'] > 0.1) {
            $score -= 30;
        } elseif ($metrics['error_rate'] > 0.05) {
            $score -= 15;
        }

        if ($metrics['sync_rate'] < 0.8) {
            $score -= 25;
        } elseif ($metrics['sync_rate'] < 0.9) {
            $score -= 10;
        }

        if ($metrics['performance_score'] < 70) {
            $score -= 20;
        }

        return max(0, $score);
    }

    private function getLastSyncTime(): string
    {
        // This would check actual ERP sync logs or database
        return date('Y-m-d H:i:s', strtotime('-30 minutes')); // Placeholder
    }

    private function getFailedOperations(array $metrics): int
    {
        $failed = 0;
        foreach ($metrics as $metric) {
            $failed += $metric->getErrorEntries();
        }
        return $failed;
    }

    private function getCriticalIssues(): array
    {
        $criticalAlerts = $this->alertRepository->getCriticalAlerts();
        return array_map(function ($alert) {
            return [
                'type' => $alert->getAlertType(),
                'message' => $alert->getMessage(),
                'occurrences' => $alert->getOccurrences()
            ];
        }, $criticalAlerts);
    }
}
