<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\Helper\CurrentAttendant;
use GrupoAwamotos\B2B\Model\ResourceModel\Attendant\CollectionFactory as AttendantCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\CollectionFactory as CustomerAttendantCollectionFactory;
use Magento\Framework\AuthorizationInterface;

class PortfolioScope implements PortfolioScopeInterface
{
    private const ACL_ALL_PORTFOLIOS = 'GrupoAwamotos_B2B::commercial_all_portfolios';
    private const ACL_COCKPIT_ONLY = 'GrupoAwamotos_B2B::commercial_cockpit_only';
    private const ACL_TECHNICAL_B2B = 'GrupoAwamotos_B2B::b2b';

    /** @var int[]|null */
    private ?array $visibleCustomerIdsCache = null;

    /** @var int[]|null */
    private ?array $visibleAttendantIdsCache = null;

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly CurrentAttendant $currentAttendant,
        private readonly CustomerAttendantCollectionFactory $customerAttendantCollectionFactory,
        private readonly AttendantCollectionFactory $attendantCollectionFactory
    ) {
    }

    public function canViewAllPortfolios(): bool
    {
        return $this->authorization->isAllowed(self::ACL_ALL_PORTFOLIOS);
    }

    public function isCockpitOnlyUser(): bool
    {
        return $this->authorization->isAllowed(self::ACL_COCKPIT_ONLY)
            && !$this->authorization->isAllowed(self::ACL_TECHNICAL_B2B);
    }

    public function canBypassPortfolioScope(): bool
    {
        if ($this->canViewAllPortfolios()
            || $this->authorization->isAllowed(self::ACL_TECHNICAL_B2B)
        ) {
            return true;
        }

        // Magento Administrators têm todas as ACLs, inclusive cockpit_only.
        // A restrição vale só para perfil cockpit-only real.
        return !$this->isCockpitOnlyUser();
    }

    public function getVisibleAttendantIds(): array
    {
        if ($this->visibleAttendantIdsCache !== null) {
            return $this->visibleAttendantIdsCache;
        }

        if ($this->canViewAllPortfolios()) {
            $collection = $this->attendantCollectionFactory->create();
            $collection->addFieldToFilter('is_active', 1);
            // Painel da Equipe: apenas vendedoras com login admin vinculado (equipe AWA).
            $collection->addFieldToFilter('admin_user_id', ['notnull' => true]);
            $this->visibleAttendantIdsCache = array_map(
                'intval',
                $collection->getColumnValues('attendant_id')
            );

            return $this->visibleAttendantIdsCache;
        }

        $attendantId = $this->currentAttendant->getId();
        $this->visibleAttendantIdsCache = $attendantId ? [$attendantId] : [];

        return $this->visibleAttendantIdsCache;
    }

    public function getVisibleCustomerIds(): array
    {
        if ($this->visibleCustomerIdsCache !== null) {
            return $this->visibleCustomerIdsCache;
        }

        $attendantIds = $this->getVisibleAttendantIds();
        if ($attendantIds === []) {
            $this->visibleCustomerIdsCache = [];

            return $this->visibleCustomerIdsCache;
        }

        $collection = $this->customerAttendantCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', ['in' => $attendantIds]);
        $this->visibleCustomerIdsCache = array_map(
            'intval',
            $collection->getColumnValues('customer_id')
        );

        return $this->visibleCustomerIdsCache;
    }

    public function canAccessCustomer(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        if ($this->canBypassPortfolioScope()) {
            return true;
        }

        return in_array($customerId, $this->getVisibleCustomerIds(), true);
    }
}
