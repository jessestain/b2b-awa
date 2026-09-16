<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Service\AgentTermination;

use GrupoAwamotos\LogMonitoring\Api\AlertRepositoryInterface;
use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterface;
use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterfaceFactory;
use GrupoAwamotos\LogMonitoring\Service\NotificationService;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class DiagnosticReportService
{
    private const ALERT_TYPE = 'agent_termination';
    private const ALERT_SOURCE = 'antigravity';

    private Classifier $classifier;
    private RecoveryManager $recoveryManager;
    private AlertRepositoryInterface $alertRepository;
    private AlertInterfaceFactory $alertFactory;
    private NotificationService $notificationService;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        Classifier $classifier,
        RecoveryManager $recoveryManager,
        AlertRepositoryInterface $alertRepository,
        AlertInterfaceFactory $alertFactory,
        NotificationService $notificationService,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->classifier = $classifier;
        $this->recoveryManager = $recoveryManager;
        $this->alertRepository = $alertRepository;
        $this->alertFactory = $alertFactory;
        $this->notificationService = $notificationService;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $incident
     * @return array<string, mixed>
     */
    public function generate(array $incident, bool $persistAlert = false, bool $notify = false): array
    {
        $historicalAlerts = $this->loadHistoricalAlerts();
        $analysis = $this->classifier->classify($incident, $historicalAlerts);
        $recovery = $this->recoveryManager->buildRecoveryPlan($analysis);

        $report = [
            'generated_at' => $this->dateTime->gmtDate('c'),
            'incident' => [
                'trajectory_id' => (string)($incident['trajectory_id'] ?? ''),
                'trace_id' => (string)($incident['trace_id'] ?? ''),
                'workflow' => (string)($incident['workflow'] ?? 'unspecified_workflow'),
                'model' => (string)($incident['model'] ?? 'unknown_model'),
                'exact_error_message' => $analysis['evidence']['exact_error_message'],
                'stack_trace' => $analysis['evidence']['stack_trace'],
                'headers' => $analysis['evidence']['headers'],
            ],
            'analysis' => $analysis,
            'recovery' => $recovery,
            'post_mortem' => $this->buildPostMortem($analysis, $recovery),
            'support' => [
                'resources' => [
                    RecoveryManager::SUPPORT_URL
                ],
                'guidance' => 'Escalate persistent provider-side or client crash issues after the local recovery steps stop changing the classification.',
            ],
        ];

        if ($persistAlert) {
            $alert = $this->persistAlert($report);
            $report['alert'] = [
                'saved' => true,
                'alert_id' => $alert->getEntityId(),
            ];

            if ($notify) {
                $report['notifications'] = $this->notificationService->sendAlert([
                    'type' => self::ALERT_TYPE,
                    'severity' => $analysis['classification']['severity'],
                    'title' => $this->buildAlertTitle($analysis),
                    'message' => $analysis['evidence']['exact_error_message'],
                    'context' => $report,
                ]);
            }
        }

        return $report;
    }

    /**
     * @return AlertInterface[]
     */
    private function loadHistoricalAlerts(): array
    {
        try {
            return $this->alertRepository->getAlertsByType(self::ALERT_TYPE);
        } catch (\Throwable $throwable) {
            $this->logger->error('Unable to load historical agent termination alerts: ' . $throwable->getMessage());
            return [];
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function persistAlert(array $report): AlertInterface
    {
        $timestamp = $this->dateTime->gmtDate();
        $existingAlert = $this->findMatchingOpenAlert($report);

        if ($existingAlert !== null) {
            $existingAlert->setSeverity($this->resolveHigherSeverity(
                (string)$existingAlert->getSeverity(),
                (string)$report['analysis']['classification']['severity']
            ));
            $existingAlert->setTitle($this->buildAlertTitle($report['analysis']));
            $existingAlert->setMessage((string)$report['incident']['exact_error_message']);
            $existingAlert->setContextData($report);
            $existingAlert->setLastOccurrence($timestamp);
            $existingAlert->setOccurrences($existingAlert->getOccurrences() + 1);

            return $this->alertRepository->save($existingAlert);
        }

        /** @var AlertInterface $alert */
        $alert = $this->alertFactory->create();

        $alert->setAlertType(self::ALERT_TYPE);
        $alert->setSeverity((string)$report['analysis']['classification']['severity']);
        $alert->setTitle($this->buildAlertTitle($report['analysis']));
        $alert->setMessage((string)$report['incident']['exact_error_message']);
        $alert->setContextData($report);
        $alert->setSource(self::ALERT_SOURCE);
        $alert->setStatus(AlertInterface::STATUS_OPEN);
        $alert->setOccurrences(1);
        $alert->setFirstOccurrence($timestamp);
        $alert->setLastOccurrence($timestamp);

        return $this->alertRepository->save($alert);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function findMatchingOpenAlert(array $report): ?AlertInterface
    {
        $expectedClassification = (string)($report['analysis']['classification']['code'] ?? '');
        $expectedMessage = (string)($report['incident']['exact_error_message'] ?? '');
        $expectedModel = (string)($report['incident']['model'] ?? '');
        $expectedWorkflow = (string)($report['incident']['workflow'] ?? '');

        foreach ($this->loadHistoricalAlerts() as $alert) {
            $status = (string)$alert->getStatus();
            if ($status === AlertInterface::STATUS_RESOLVED) {
                continue;
            }

            $context = $alert->getContextData() ?? [];
            $classification = (string)($context['analysis']['classification']['code'] ?? '');
            $message = (string)($context['incident']['exact_error_message'] ?? $alert->getMessage() ?? '');
            $model = (string)($context['incident']['model'] ?? '');
            $workflow = (string)($context['incident']['workflow'] ?? '');

            if ($classification === $expectedClassification &&
                $message === $expectedMessage &&
                $model === $expectedModel &&
                $workflow === $expectedWorkflow
            ) {
                return $alert;
            }
        }

        return null;
    }

    private function resolveHigherSeverity(string $currentSeverity, string $newSeverity): string
    {
        $rank = [
            AlertInterface::SEVERITY_LOW => 1,
            AlertInterface::SEVERITY_MEDIUM => 2,
            AlertInterface::SEVERITY_HIGH => 3,
            AlertInterface::SEVERITY_CRITICAL => 4,
        ];

        return ($rank[$newSeverity] ?? 0) > ($rank[$currentSeverity] ?? 0)
            ? $newSeverity
            : $currentSeverity;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function buildPostMortem(array $analysis, array $recovery): array
    {
        return [
            'summary' => sprintf(
                'Agent termination classified as %s with severity %s.',
                $analysis['classification']['label'] ?? 'Unknown Agent Failure',
                $analysis['classification']['severity'] ?? 'medium'
            ),
            'actionable_steps' => $recovery['recommended_fixes'] ?? [],
            'graceful_degradation' => $recovery['graceful_degradation'] ?? [],
            'monitoring_alerts' => $recovery['monitoring_alerts'] ?? [],
            'retry_or_restart_options' => [
                'retry_operation' => true,
                'start_new_conversation' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function buildAlertTitle(array $analysis): string
    {
        $label = (string)($analysis['classification']['label'] ?? 'Agent Failure');
        return sprintf('Antigravity agent termination: %s', $label);
    }
}
