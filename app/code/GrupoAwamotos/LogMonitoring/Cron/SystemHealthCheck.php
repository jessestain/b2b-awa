<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Cron;

use GrupoAwamotos\LogMonitoring\Service\OperationalHealthService;
use GrupoAwamotos\LogMonitoring\Service\SystemHealthService;
use GrupoAwamotos\LogMonitoring\Service\NotificationService;
use Psr\Log\LoggerInterface;

class SystemHealthCheck
{
    private SystemHealthService $systemHealthService;
    private OperationalHealthService $operationalHealthService;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        SystemHealthService $systemHealthService,
        OperationalHealthService $operationalHealthService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->systemHealthService = $systemHealthService;
        $this->operationalHealthService = $operationalHealthService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $healthData = $this->systemHealthService->getOverallHealth();

            // Alertas operacionais (patch, cron, indexadores) via OperationalHealthService
            $operational = $this->operationalHealthService->collect();
            $healthData['operational'] = $operational;
            $runtimeFailed = 0;
            foreach (($operational['checks'] ?? []) as $check) {
                if (($check['status'] ?? '') !== 'fail') {
                    continue;
                }
                if (($check['id'] ?? '') === 'magento_version') {
                    continue;
                }
                $runtimeFailed++;
            }
            if ($runtimeFailed > 0) {
                $healthData['overall_score'] = min($healthData['overall_score'], 50);
                $healthData['overall_status'] = 'critical';
            }

            // Update component health in database
            if (isset($healthData['components'])) {
                foreach ($healthData['components'] as $component => $data) {
                    $this->systemHealthService->updateComponentHealth($component, $data);
                }
            }

            // Send alert if system health is poor
            if ($healthData['overall_score'] < 70) {
                $this->notificationService->sendSystemHealthAlert($healthData);
            }

            $this->logger->info('Completed system health check', [
                'status' => $healthData['overall_status'],
                'score' => $healthData['overall_score']
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error in system health check: ' . $e->getMessage());
        }
    }
}
