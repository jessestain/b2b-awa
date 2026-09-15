<?php

/**
 * B2B Restricted Catalog Plugin - Filter products by customer group
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Catalog;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Customer\Model\Session as CustomerSession;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

class RestrictedProductCollectionPlugin
{
    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var B2BHelper
     */
    private $b2bHelper;

    /**
     * @var LoggerInterface
     */
    private ?LoggerInterface $logger;

    /**
     * @var bool
     */
    private $filterApplied = false;

    /**
     * @var PhpCookieManager
     */
    private $cookieManager;

    public function __construct(
        CustomerSession $customerSession,
        B2BHelper $b2bHelper,
        PhpCookieManager $cookieManager,
        ?LoggerInterface $logger = null
    ) {
        $this->customerSession = $customerSession;
        $this->b2bHelper = $b2bHelper;
        $this->cookieManager = $cookieManager;
        $this->logger = $logger;
    }

    /**
     * Filter products based on B2B restrictions before loading
     */
    public function beforeLoad(Collection $subject, bool $printQuery = false, bool $logQuery = false): array
    {
        if ($this->filterApplied || $subject->isLoaded()) {
            return [$printQuery, $logQuery];
        }

        $this->applyB2BFilter($subject);
        $this->filterApplied = true;

        return [$printQuery, $logQuery];
    }

    /**
     * Apply B2B filtering to collection
     *
     * @param Collection $collection
     * @return void
     */
    private function applyB2BFilter(Collection $collection): void
    {
        if (!$this->b2bHelper->isEnabled()) {
            return;
        }

        // Skip filtering if using Flat Catalog (not compatible with EAV attribute filters)
        if ($collection->isEnabledFlat()) {
            return;
        }

        // Avoid session_start() for guests: check cookie before calling isLoggedIn().
        $hasCookie  = $this->cookieManager->getCookie(session_name()) !== null;
        $isLoggedIn = $hasCookie && $this->customerSession->isLoggedIn();
        $customerGroupId = $isLoggedIn ? (int) $this->customerSession->getCustomerGroupId() : 0;
        $isB2BCustomer = in_array($customerGroupId, $this->b2bHelper->getB2BGroupIds(), true);

        try {
            // Add attribute to select
            $collection->addAttributeToSelect('b2b_exclusive', 'left');
            $collection->addAttributeToSelect('b2b_customer_groups', 'left');

            if (!$isLoggedIn || !$isB2BCustomer) {
                // Guest or non-B2B: hide B2B exclusive products
                $collection->addAttributeToFilter(
                    [
                        ['attribute' => 'b2b_exclusive', 'null' => true],
                        ['attribute' => 'b2b_exclusive', 'eq' => 0],
                        ['attribute' => 'b2b_exclusive', 'eq' => '']
                    ],
                    null,
                    'left'
                );
            } else {
                // B2B customer: filter by allowed groups
                $this->filterByCustomerGroup($collection, $customerGroupId);
            }
        } catch (\Exception $e) {
            // Keep storefront resilient if B2B attributes are missing.
            if ($this->logger !== null) {
                $this->logger->warning('[B2B] RestrictedProductCollectionPlugin: ' . $e->getMessage());
            }
        }
    }

    /**
     * Filter collection by customer group restrictions
     *
     * @param Collection $collection
     * @param int $customerGroupId
     * @return void
     */
    private function filterByCustomerGroup(Collection $collection, int $customerGroupId): void
    {
        $groupName = $this->getGroupNameById($customerGroupId);

        if (!$groupName) {
            return;
        }

        // Products are visible if:
        // 1. b2b_customer_groups is empty (available to all)
        // 2. b2b_customer_groups contains the customer's group
        // 3. b2b_customer_groups contains "Todos os Grupos B2B"

        // This is complex with EAV, so we'll do post-filtering in afterLoad if needed
    }

    /**
     * Get customer group name by ID
     *
     * @param int $groupId
     * @return string|null
     */
    private function getGroupNameById(int $groupId): ?string
    {
        $name = $this->b2bHelper->getB2BGroupName($groupId);
        return $name !== 'Cliente' ? $name : null;
    }
}
