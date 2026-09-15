<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Block\Adminhtml;

use GrupoAwamotos\B2B\CommercialPanel\Model\DashboardDataProvider;
use GrupoAwamotos\B2B\CommercialPanel\Model\Intelligence\CommercialGoalProgressService;
use GrupoAwamotos\B2B\CommercialPanel\Model\SupervisorDashboardDataProvider;
use GrupoAwamotos\B2B\Helper\CurrentAttendant;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;

class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly DashboardDataProvider $dashboardDataProvider,
        private readonly SupervisorDashboardDataProvider $supervisorDashboardDataProvider,
        private readonly CommercialGoalProgressService $goalProgressService,
        private readonly CurrentAttendant $currentAttendant,
        private readonly PriceHelper $priceHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isSupervisorView(): bool
    {
        return $this->supervisorDashboardDataProvider->isSupervisorView();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return $this->dashboardDataProvider->getSummary();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTeamSummary(): array
    {
        return $this->supervisorDashboardDataProvider->getTeamSummary();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTodayPriorities(int $limit = 15): array
    {
        return $this->dashboardDataProvider->getTodayPriorities($limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSellerRanking(): array
    {
        return $this->supervisorDashboardDataProvider->getSellerRanking();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentOrders(int $limit = 10): array
    {
        return $this->dashboardDataProvider->getRecentOrders($limit);
    }

    public function isLinkedAttendant(): bool
    {
        return $this->currentAttendant->isAttendant();
    }

    public function formatPrice(float $amount): string
    {
        return (string) $this->priceHelper->currency($amount, true, false);
    }

    public function getDashboardUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialdashboard/index');
    }

    public function getPortfolioUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialportfolio/index');
    }

    public function getPendingUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialpending/index');
    }

    public function getTasksUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialtask/index');
    }

    public function getAbandonedCartsUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialabandonedcart/index');
    }

    public function getCustomer360Url(int $customerId): string
    {
        return $this->getUrl('awa_commercial/commercialcustomer/view', ['customer_id' => $customerId]);
    }

    public function getContactUrl(int $customerId): string
    {
        return $this->getUrl(
            'awa_commercial/commercialcustomer/view',
            ['customer_id' => $customerId, '_query' => ['tab' => 'contatos']]
        );
    }

    public function getCompleteTaskUrl(int $taskId): string
    {
        return $this->getUrl('awa_commercial/commercialtask/complete', ['task_id' => $taskId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGoalProgress(): array
    {
        if ($this->isSupervisorView()) {
            return $this->goalProgressService->getProgressForPeriod();
        }

        $own = $this->goalProgressService->getOwnProgress();
        if ($own === null || !$this->hasConfiguredGoal($own)) {
            return [];
        }

        return [$own];
    }

    /**
     * @param array<string, mixed> $goal
     */
    private function hasConfiguredGoal(array $goal): bool
    {
        return (float) ($goal['revenue_goal'] ?? 0) > 0
            || (int) ($goal['contacts_goal'] ?? 0) > 0
            || (int) ($goal['reactivated_goal'] ?? 0) > 0;
    }

    public function getGoalsUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialgoal/index');
    }

    public function getRepurchaseUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialrepurchase/index');
    }

    public function getInactiveUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialinactive/index');
    }

    public function getReportsUrl(): string
    {
        return $this->getUrl('awa_commercial/commercialreport/index');
    }
}
