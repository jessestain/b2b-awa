<?php

declare(strict_types=1);

namespace GrupoAwamotos\LogMonitoring\Model;

use GrupoAwamotos\LogMonitoring\Api\AlertRepositoryInterface;
use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterface;
use GrupoAwamotos\LogMonitoring\Api\Data\AlertInterfaceFactory;
use GrupoAwamotos\LogMonitoring\Model\ResourceModel\Alert as AlertResource;
use GrupoAwamotos\LogMonitoring\Model\ResourceModel\Alert\CollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

class AlertRepository implements AlertRepositoryInterface
{
    private AlertResource $resource;
    private AlertInterfaceFactory $alertFactory;
    private CollectionFactory $collectionFactory;
    private SearchResultsInterfaceFactory $searchResultsFactory;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        AlertResource $resource,
        AlertInterfaceFactory $alertFactory,
        CollectionFactory $collectionFactory,
        SearchResultsInterfaceFactory $searchResultsFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->alertFactory = $alertFactory;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    public function save(AlertInterface $alert): AlertInterface
    {
        try {
            $this->resource->save($alert);
        } catch (\Exception $e) {
            $this->logger->error('Error saving alert: ' . $e->getMessage());
            throw new LocalizedException(__('Unable to save alert: %1', $e->getMessage()));
        }

        return $alert;
    }

    public function getById(int $id): AlertInterface
    {
        $alert = $this->alertFactory->create();
        $this->resource->load($alert, $id);

        if (!$alert->getEntityId()) {
            throw new NoSuchEntityException(__('Alert with ID %1 does not exist', $id));
        }

        return $alert;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();

        foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
            foreach ($filterGroup->getFilters() as $filter) {
                $condition = $filter->getConditionType() ?: 'eq';
                $collection->addFieldToFilter($filter->getField(), [$condition => $filter->getValue()]);
            }
        }

        $collection->setCurPage($searchCriteria->getCurrentPage());
        $collection->setPageSize($searchCriteria->getPageSize());

        foreach ($searchCriteria->getSortOrders() as $sortOrder) {
            $collection->setOrder($sortOrder->getField(), $sortOrder->getDirection());
        }

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(AlertInterface $alert): bool
    {
        try {
            $this->resource->delete($alert);
        } catch (\Exception $e) {
            $this->logger->error('Error deleting alert: ' . $e->getMessage());
            throw new LocalizedException(__('Unable to delete alert: %1', $e->getMessage()));
        }

        return true;
    }

    public function deleteById(int $id): bool
    {
        $alert = $this->getById($id);
        return $this->delete($alert);
    }

    public function getActiveAlerts(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addActiveFilter()
            ->orderByLatest()
            ->setPageSize(100);

        return $collection->getItems();
    }

    public function getAlertsByType(string $alertType): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addTypeFilter($alertType)
            ->orderByLatest()
            ->setPageSize(100);

        return $collection->getItems();
    }

    public function getAlertsBySeverity(string $severity): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addSeverityFilter($severity)
            ->orderByLatest()
            ->setPageSize(100);

        return $collection->getItems();
    }

    public function acknowledgeAlert(int $alertId, string $acknowledgedBy): bool
    {
        try {
            $alert = $this->getById($alertId);
            $alert->setStatus(AlertInterface::STATUS_ACKNOWLEDGED);
            $alert->setAcknowledgedAt($this->dateTime->gmtDate());
            $alert->setAcknowledgedBy($acknowledgedBy);

            $this->save($alert);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error acknowledging alert: ' . $e->getMessage());
            return false;
        }
    }

    public function resolveAlert(int $alertId, string $resolvedBy): bool
    {
        try {
            $alert = $this->getById($alertId);
            $alert->setStatus(AlertInterface::STATUS_RESOLVED);
            $alert->setResolvedAt($this->dateTime->gmtDate());
            $alert->setResolvedBy($resolvedBy);

            $this->save($alert);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error resolving alert: ' . $e->getMessage());
            return false;
        }
    }

    public function getCriticalAlerts(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addCriticalFilter()
            ->addActiveFilter()
            ->orderBySeverity()
            ->setPageSize(50);

        return $collection->getItems();
    }

    /**
     * {@inheritdoc}
     *
     * IMPORTANTE (task-49): nao altera notified_at — a coluna pertence ao cooldown do
     * consumidor (Cron/AlertProcessor.php). O save() grava o valor carregado, sem reabrir o flood.
     */
    public function getLatestOpenAlert(string $alertType, string $severity, string $source): ?AlertInterface
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('alert_type', $alertType)
            ->addFieldToFilter('severity', $severity)
            ->addFieldToFilter('source', $source)
            ->addFieldToFilter('status', AlertInterface::STATUS_OPEN)
            ->setOrder('last_occurrence', 'DESC')
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);

        $alert = $collection->getFirstItem();

        if (!$alert || !$alert->getEntityId()) {
            return null;
        }

        return $alert;
    }
}
