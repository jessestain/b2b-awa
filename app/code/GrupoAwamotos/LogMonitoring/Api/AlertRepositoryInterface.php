<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Api;

use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

interface AlertRepositoryInterface
{
    public function save(AlertInterface $alert): AlertInterface;

    public function getById(int $id): AlertInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    public function delete(AlertInterface $alert): bool;

    public function deleteById(int $id): bool;

    public function getActiveAlerts(): array;

    public function getAlertsByType(string $alertType): array;

    public function getAlertsBySeverity(string $severity): array;

    public function acknowledgeAlert(int $alertId, string $acknowledgedBy): bool;

    public function resolveAlert(int $alertId, string $resolvedBy): bool;

    public function getCriticalAlerts(): array;

    /**
     * Alerta ABERTO mais recente de um tipo/severidade/fonte (task-49).
     *
     * Usado para deduplicar na origem: o analisador incrementa occurrences deste alerta
     * em vez de inserir uma linha nova a cada rodada do cron.
     * Ordenacao determinista: last_occurrence DESC, entity_id DESC.
     *
     * @return AlertInterface|null null quando nao existe alerta aberto desse tipo
     */
    public function getLatestOpenAlert(string $alertType, string $severity, string $source): ?AlertInterface;
}
