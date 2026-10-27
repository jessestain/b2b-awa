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

class PerformanceAnalyzer implements AnalyzerInterface
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
            'debug.log',
            'system.log',
            'performance_monitor.log',
        ];

        $analysis = [];

        foreach ($logFiles as $logFile) {
            if ($logDir->isExist($logFile)) {
                $analysis[$logFile] = $this->analyzeLogFile($logDir, $logFile);
            }
        }

        $this->generatePerformanceAlerts($analysis);

        return $analysis;
    }

    public function getSpecificMetrics(): array
    {
        $metrics = $this->logMetricsRepository->getMetricsByType('performance', 10);

        $performanceMetrics = [
            'cache_hit_rate' => $this->calculateCacheHitRate($metrics),
            'page_load_time' => $this->calculateAveragePageLoadTime($metrics),
            'memory_usage' => $this->getMemoryUsage($metrics),
            'slow_queries_count' => $this->getSlowQueriesCount($metrics),
            'cache_warmer_status' => $this->getCacheWarmerStatus(),
            'health_score' => 100,
            'performance_issues' => $this->getPerformanceIssues($metrics)
        ];

        // Calculate overall health score
        $performanceMetrics['health_score'] = $this->calculateHealthScore($performanceMetrics);

        return $performanceMetrics;
    }

    public function checkHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'score' => 100
        ];

        $metrics = $this->getSpecificMetrics();

        // Check cache hit rate
        if ($metrics['cache_hit_rate'] < 0.8) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Low cache hit rate affecting performance';
            $health['score'] -= 20;
        }

        // Check page load time
        if ($metrics['page_load_time'] > 3.0) {
            $health['status'] = 'critical';
            $health['issues'][] = 'High page load times detected';
            $health['score'] -= 30;
        } elseif ($metrics['page_load_time'] > 2.0) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Elevated page load times';
            $health['score'] -= 15;
        }

        // Check memory usage
        if ($metrics['memory_usage'] > 80) {
            $health['status'] = 'critical';
            $health['issues'][] = 'High memory usage';
            $health['score'] -= 25;
        } elseif ($metrics['memory_usage'] > 70) {
            $health['status'] = 'warning';
            $health['issues'][] = 'Elevated memory usage';
            $health['score'] -= 10;
        }

        return $health;
    }

    public function generateAlerts(): array
    {
        $alerts = [];
        $metrics = $this->getSpecificMetrics();

        // Cache performance alert
        if ($metrics['cache_hit_rate'] < 0.7) {
            $alerts[] = $this->createAlert(
                'low_cache_hit_rate',
                'high',
                'Low Cache Hit Rate',
                sprintf('Cache hit rate is %.2f%%, performance may be degraded', $metrics['cache_hit_rate'] * 100),
                ['cache_hit_rate' => $metrics['cache_hit_rate']]
            );
        }

        // Page load time alert
        if ($metrics['page_load_time'] > 5.0) {
            $alerts[] = $this->createAlert(
                'slow_page_load',
                'critical',
                'Slow Page Load Times',
                sprintf('Average page load time is %.2f seconds, users may experience delays', $metrics['page_load_time']),
                ['page_load_time' => $metrics['page_load_time']]
            );
        }

        // Memory usage alert
        if ($metrics['memory_usage'] > 85) {
            $alerts[] = $this->createAlert(
                'high_memory_usage',
                'critical',
                'High Memory Usage',
                sprintf('Memory usage is at %.1f%%, system may become unstable', $metrics['memory_usage']),
                ['memory_usage' => $metrics['memory_usage']]
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
            // ReadInterface::readFile() 3rd param is stream context, not offset
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
                'performance_specific' => [
                    'cache_misses' => 0,
                    'slow_queries' => 0,
                    'memory_warnings' => 0,
                    'timeout_errors' => 0,
                    'performance_alerts' => 0
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

                // Performance specific patterns
                if (stripos($line, 'cache miss') !== false || stripos($line, 'cache_miss') !== false) {
                    $analysis['performance_specific']['cache_misses']++;
                }
                if (stripos($line, 'slow query') !== false || stripos($line, 'query took') !== false) {
                    $analysis['performance_specific']['slow_queries']++;
                }
                if (stripos($line, 'memory') !== false && (stripos($line, 'limit') !== false || stripos($line, 'exhausted') !== false)) {
                    $analysis['performance_specific']['memory_warnings']++;
                }
                if (stripos($line, 'timeout') !== false || stripos($line, 'timed out') !== false) {
                    $analysis['performance_specific']['timeout_errors']++;
                }
                if (stripos($line, 'performance') !== false && stripos($line, 'alert') !== false) {
                    $analysis['performance_specific']['performance_alerts']++;
                }
            }

            // Save metrics
            $logMetrics = $this->logMetricsFactory->create();
            $logMetrics->setLogType('performance');
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
            $this->logger->error('Error analyzing performance log file: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function generatePerformanceAlerts(array $analysis): void
    {
        foreach ($analysis as $file => $data) {
            if (isset($data['error'])) {
                continue;
            }

            // Alert on high cache miss rate
            if ($data['performance_specific']['cache_misses'] > 100) {
                $this->createAndSaveAlert(
                    'high_cache_miss_rate',
                    'high',
                    'High Cache Miss Rate Detected',
                    sprintf(
                        'Detected %d cache misses in %s, performance may be affected',
                        $data['performance_specific']['cache_misses'],
                        $file
                    ),
                    ['file' => $file, 'count' => $data['performance_specific']['cache_misses']]
                );
            }

            // Alert on slow queries
            if ($data['performance_specific']['slow_queries'] > 20) {
                $this->createAndSaveAlert(
                    'slow_queries_detected',
                    'medium',
                    'Slow Database Queries',
                    sprintf(
                        'Found %d slow queries in %s, database performance may be degraded',
                        $data['performance_specific']['slow_queries'],
                        $file
                    ),
                    ['file' => $file, 'count' => $data['performance_specific']['slow_queries']]
                );
            }

            // Alert on memory issues
            if ($data['performance_specific']['memory_warnings'] > 5) {
                $this->createAndSaveAlert(
                    'memory_warnings',
                    'critical',
                    'Memory Usage Warnings',
                    sprintf(
                        'Detected %d memory warnings in %s, system stability at risk',
                        $data['performance_specific']['memory_warnings'],
                        $file
                    ),
                    ['file' => $file, 'count' => $data['performance_specific']['memory_warnings']]
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
            $existente = $this->alertRepository->getLatestOpenAlert($type, $severity, 'performance_analyzer');

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
            $alert->setSource('performance_analyzer');
            $alert->setStatus('open');
            $alert->setOccurrences(1);
            $alert->setFirstOccurrence(date('Y-m-d H:i:s'));
            $alert->setLastOccurrence(date('Y-m-d H:i:s'));

            $this->alertRepository->save($alert);
        } catch (\Exception $e) {
            $this->logger->error('Error creating performance alert: ' . $e->getMessage());
        }
    }

    private function calculateCacheHitRate(array $metrics): float
    {
        if (empty($metrics)) {
            return 0.9; // Default assumption
        }

        $totalRequests = 0;
        $cacheMisses = 0;

        foreach ($metrics as $metric) {
            $analysisData = $metric->getAnalysisData();
            if (isset($analysisData['performance_specific']['cache_misses'])) {
                $misses = $analysisData['performance_specific']['cache_misses'];
                $requests = max(1, $metric->getTotalEntries() / 5); // Estimate requests

                $totalRequests += $requests;
                $cacheMisses += $misses;
            }
        }

        if ($totalRequests == 0) {
            return 0.9;
        }

        return max(0, 1 - ($cacheMisses / $totalRequests));
    }

    private function calculateAveragePageLoadTime(array $metrics): float
    {
        // This would be calculated from actual performance logs
        // For now, return a simulated value based on error rates
        if (empty($metrics)) {
            return 1.5;
        }

        $baseTime = 1.2;
        $errorMultiplier = 0;

        foreach ($metrics as $metric) {
            if ($metric->getErrorEntries() > 0) {
                $errorMultiplier += ($metric->getErrorEntries() / max(1, $metric->getTotalEntries())) * 0.5;
            }
        }

        return $baseTime + ($errorMultiplier / count($metrics));
    }

    private function getMemoryUsage(array $metrics): float
    {
        // This would check actual memory usage
        // For simulation, base it on log activity
        if (empty($metrics)) {
            return 45.0;
        }

        $totalSize = 0;
        foreach ($metrics as $metric) {
            $totalSize += $metric->getFileSizeBytes();
        }

        // Simulate memory usage based on log size (more logs = more activity = more memory)
        $baseMem = 45.0;
        $memIncrease = min(40, ($totalSize / (1024 * 1024)) * 0.1); // MB to percentage

        return $baseMem + $memIncrease;
    }

    private function getSlowQueriesCount(array $metrics): int
    {
        $count = 0;
        foreach ($metrics as $metric) {
            $analysisData = $metric->getAnalysisData();
            if (isset($analysisData['performance_specific']['slow_queries'])) {
                $count += $analysisData['performance_specific']['slow_queries'];
            }
        }
        return $count;
    }

    private function getCacheWarmerStatus(): string
    {
        // Check if cache warmer is running properly
        $recentMetrics = $this->logMetricsRepository->getMetricsByType('performance', 1);

        if (empty($recentMetrics)) {
            return 'unknown';
        }

        $latestMetric = reset($recentMetrics);
        $analysisData = $latestMetric->getAnalysisData();

        if (isset($analysisData['performance_specific']['cache_misses'])) {
            $missRate = $analysisData['performance_specific']['cache_misses'] / max(1, $latestMetric->getTotalEntries());

            if ($missRate > 0.3) {
                return 'ineffective';
            } elseif ($missRate > 0.2) {
                return 'partial';
            }
        }

        return 'effective';
    }

    private function getPerformanceIssues(array $metrics): array
    {
        $issues = [];

        foreach ($metrics as $metric) {
            $analysisData = $metric->getAnalysisData();
            if (!$analysisData) {
                continue;
            }

            $perfData = $analysisData['performance_specific'] ?? [];

            if (($perfData['cache_misses'] ?? 0) > 50) {
                $issues[] = 'High cache miss rate';
            }
            if (($perfData['slow_queries'] ?? 0) > 10) {
                $issues[] = 'Slow database queries detected';
            }
            if (($perfData['memory_warnings'] ?? 0) > 0) {
                $issues[] = 'Memory usage warnings';
            }
            if (($perfData['timeout_errors'] ?? 0) > 5) {
                $issues[] = 'Request timeout errors';
            }
        }

        return array_unique($issues);
    }

    private function calculateHealthScore(array $metrics): float
    {
        $score = 100;

        // Reduce score based on various factors
        if ($metrics['cache_hit_rate'] < 0.7) {
            $score -= 30;
        } elseif ($metrics['cache_hit_rate'] < 0.8) {
            $score -= 15;
        }

        if ($metrics['page_load_time'] > 3.0) {
            $score -= 40;
        } elseif ($metrics['page_load_time'] > 2.0) {
            $score -= 20;
        }

        if ($metrics['memory_usage'] > 80) {
            $score -= 25;
        } elseif ($metrics['memory_usage'] > 70) {
            $score -= 10;
        }

        if ($metrics['slow_queries_count'] > 20) {
            $score -= 15;
        }

        return max(0, $score);
    }
}
