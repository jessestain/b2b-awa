<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Cron;

use GrupoAwamotos\LogMonitoring\Api\AlertRepositoryInterface;
use GrupoAwamotos\LogMonitoring\Service\NotificationService;
use Psr\Log\LoggerInterface;

class AlertProcessor
{
    /**
     * Horas minimas entre duas notificacoes do MESMO alerta.
     * Evita flood de e-mail porque o cron roda a cada 5 minutos.
     */
    private const NOTIFY_COOLDOWN_HOURS = 6;

    private AlertRepositoryInterface $alertRepository;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        AlertRepositoryInterface $alertRepository,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->alertRepository = $alertRepository;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    /**
     * Diz se o alerta ainda esta dentro da janela de silencio.
     *
     * @param mixed $notifiedAt valor da coluna notified_at (null = nunca notificado)
     */
    private function isWithinNotificationCooldown($notifiedAt): bool
    {
        if (empty($notifiedAt)) {
            return false;
        }

        $ts = strtotime((string) $notifiedAt);

        if ($ts === false) {
            return false;
        }

        return (time() - $ts) < (self::NOTIFY_COOLDOWN_HOURS * 3600);
    }

    public function execute(): void
    {
        try {
            // Get critical alerts that need immediate attention
            $criticalAlerts = $this->alertRepository->getCriticalAlerts();

            foreach ($criticalAlerts as $alert) {
                $alertData = [
                    'type' => $alert->getAlertType(),
                    'severity' => $alert->getSeverity(),
                    'title' => $alert->getTitle(),
                    'message' => $alert->getMessage(),
                    'context' => $alert->getContextData()
                ];

                // Send notification for unacknowledged critical alerts.
                //
                // COOLDOWN 10/10/2026 — o cron roda a cada 5 min (crontab.xml: schedule a cada
                // 5 minutos). Antes desta guarda, TODO alerta 'open' era reenviado a cada rodada:
                // com 13 alertas abertos isso gerou 247 e-mails num unico dia (13 x 12 rodadas
                // por hora). Agora cada alerta notifica no maximo 1x a cada
                // self::NOTIFY_COOLDOWN_HOURS horas. A coluna notified_at vive em
                // awa_log_alerts (etc/db_schema.xml) — schema declarativo do Magento.
                //
                // TETO POR JANELA DE TEMPO — 11/10/2026, task-74.
                // A condicao "occurrences <= 3" existia para conter o flood ANTES do cooldown.
                // Depois do dedupe dos analyzers (task-49) ela virou TETO DE OCORRENCIAS e passou
                // a SILENCIAR incidente longo: um alerta com occurrences maior que 3 nunca mais
                // notificava, e como o dedupe soma na MESMA linha, a recorrencia tambem ficava
                // muda para sempre (o alerta 157 chegou a occurrences=3 e o proximo incremento o
                // deixaria mudo). O volume ja e contido pelo cooldown acima: cada alerta dispara
                // no maximo 1x a cada NOTIFY_COOLDOWN_HOURS, e o dedupe garante no maximo UMA
                // linha aberta por (alert_type, severity, source). Pior caso medido: 5 linhas
                // notificaveis x 4 por dia = 20 e-mails por dia, contra 60 por dia do passivo de
                // 15 duplicatas e 247 por dia antes do cooldown. A matriz esta no relatorio da
                // task-74 e foi conferida antes de remover a guarda.
                if ($alert->getStatus() === 'open') {
                    $notifiedAt = $alert->getData('notified_at');

                    if ($this->isWithinNotificationCooldown($notifiedAt)) {
                        continue;
                    }

                    $result = $this->notificationService->sendAlert($alertData);

                    if (array_filter($result)) { // If any notification method succeeded
                        $alert->setData('notified_at', date('Y-m-d H:i:s'));
                        $this->alertRepository->save($alert);

                        $this->logger->info('Alert notification sent', [
                            'alert_id' => $alert->getEntityId(),
                            'type' => $alert->getAlertType(),
                            'result' => $result,
                            'notified_at' => $alert->getData('notified_at')
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error in alert processing: ' . $e->getMessage());
        }
    }
}
