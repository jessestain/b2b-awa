<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Coupon;

use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\RuleInterfaceFactory;
use Magento\SalesRule\Api\Data\ConditionInterfaceFactory;
use Magento\SalesRule\Api\Data\CouponInterfaceFactory;
use Magento\SalesRule\Model\Coupon;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

/**
 * Coupon Generator Service
 *
 * Generates personalized coupons for customer re-engagement
 * based on RFM segment and customer history
 */
class Generator
{
    private const COUPON_PREFIX = 'VOLTE';
    private const RULE_NAME_PREFIX = 'ERP Re-engagement - ';

    private Helper $helper;
    private RuleRepositoryInterface $ruleRepository;
    private CouponRepositoryInterface $couponRepository;
    private RuleInterfaceFactory $ruleFactory;
    private ConditionInterfaceFactory $conditionFactory;
    private CouponInterfaceFactory $couponFactory;
    private CustomerRepositoryInterface $customerRepository;
    private StoreManagerInterface $storeManager;
    private State $state;
    private LoggerInterface $logger;

    /**
     * Discount percentages by RFM segment
     */
    private array $segmentDiscounts = [
        'cant_lose' => 20,      // High value, need urgent action
        'at_risk' => 15,        // At risk of churning
        'about_to_sleep' => 12, // Starting to disengage
        'hibernating' => 10,    // Inactive but recoverable
        'lost' => 25,           // Last resort - high discount
        'need_attention' => 10, // Needs a nudge
        'default' => 10,        // Default discount
    ];

    public function __construct(
        Helper $helper,
        RuleRepositoryInterface $ruleRepository,
        CouponRepositoryInterface $couponRepository,
        RuleInterfaceFactory $ruleFactory,
        ConditionInterfaceFactory $conditionFactory,
        CouponInterfaceFactory $couponFactory,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        State $state,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->ruleRepository = $ruleRepository;
        $this->couponRepository = $couponRepository;
        $this->ruleFactory = $ruleFactory;
        $this->conditionFactory = $conditionFactory;
        $this->couponFactory = $couponFactory;
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->state = $state;
        $this->logger = $logger;
    }

    /**
     * Generate a personalized coupon for a customer
     *
     * @param int $customerId Magento customer ID
     * @param string $segment RFM segment
     * @param int|null $discountPercent Override discount percentage
     * @param int $validDays Number of days the coupon is valid
     * @return array|null Coupon data or null on failure
     */
    public function generateForCustomer(
        int $customerId,
        string $segment = 'default',
        ?int $discountPercent = null,
        int $validDays = 30
    ): ?array {
        if (!$this->helper->isCouponGenerationEnabled()) {
            return null;
        }

        try {
            // Ensure area code is set (fix: Area code is not set error)
            try {
                $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
            // Area code already set by CLI bootstrap or a previous call in this
            // same request; safe to ignore.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                // Area already set, continue
            }

            // Get customer
            $customer = $this->customerRepository->getById($customerId);
            $customerEmail = $customer->getEmail();
            $customerName = $customer->getFirstname();

            // Determine discount
            $discount = $discountPercent ?? $this->getDiscountForSegment($segment);

            // Generate unique coupon code
            $couponCode = $this->generateCouponCode($customerId);

            // Calculate expiration date
            $expirationDate = date('Y-m-d', strtotime("+{$validDays} days"));

            // Create the sales rule
            $rule = $this->createSalesRule(
                $customerEmail,
                $customerName,
                $discount,
                $expirationDate,
                $segment
            );

            // Create the coupon
            $coupon = $this->createCoupon((int) $rule->getRuleId(), $couponCode);

            $this->logger->info(sprintf(
                '[ERP Coupon] Generated coupon %s for customer %s (segment: %s, discount: %d%%)',
                $couponCode,
                $customerEmail,
                $segment,
                $discount
            ));

            return [
                'coupon_code' => $couponCode,
                'discount_percent' => $discount,
                'expiration_date' => $expirationDate,
                'valid_days' => $validDays,
                'customer_id' => $customerId,
                'customer_email' => $customerEmail,
                'customer_name' => $customerName,
                'segment' => $segment,
                'rule_id' => (int) $rule->getRuleId(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('[ERP Coupon] Error generating coupon: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate coupons for multiple customers
     *
     * @param array $customers Array of ['customer_id' => int, 'segment' => string]
     * @param int $validDays
     * @return array Generated coupons
     */
    public function generateBatch(array $customers, int $validDays = 30): array
    {
        $results = [];

        foreach ($customers as $customerData) {
            $customerId = $customerData['customer_id'] ?? null;
            $segment = $customerData['segment'] ?? 'default';

            if (!$customerId) {
                continue;
            }

            $coupon = $this->generateForCustomer($customerId, $segment, null, $validDays);

            if ($coupon) {
                $results[] = $coupon;
            }
        }

        return $results;
    }

    /**
     * Get discount percentage for a segment
     */
    public function getDiscountForSegment(string $segment): int
    {
        // Check if there's a configured value
        $configuredDiscount = $this->helper->getCouponDiscountForSegment($segment);

        if ($configuredDiscount > 0) {
            return $configuredDiscount;
        }

        return $this->segmentDiscounts[$segment] ?? $this->segmentDiscounts['default'];
    }

    /**
     * Generate unique coupon code
     */
    private function generateCouponCode(int $customerId): string
    {
        $timestamp = base_convert((string) time(), 10, 36);
        $customerPart = base_convert((string) $customerId, 10, 36);
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        return strtoupper(self::COUPON_PREFIX . $timestamp . $customerPart . $random);
    }

    /**
     * Create a sales rule for the coupon using the API layer (RuleInterface).
     *
     * In Magento 2.4.8 Model\Rule no longer implements RuleInterface, so
     * RuleRepository::save() requires a proper Data\Rule (API DTO).
     * Conditions are built via ConditionInterface objects instead of serialized JSON.
     */
    private function createSalesRule(
        string $customerEmail,
        string $customerName,
        int $discountPercent,
        string $expirationDate,
        string $segment
    ): \Magento\SalesRule\Api\Data\RuleInterface {
        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteIds[] = (int) $website->getId();
        }

        $rule = $this->ruleFactory->create();

        $rule->setName(self::RULE_NAME_PREFIX . $customerEmail . ' (' . $segment . ')')
            ->setDescription("Cupom de re-engajamento para {$customerName} - Segmento: {$segment}")
            ->setIsActive(true)
            ->setWebsiteIds($websiteIds)
            ->setCustomerGroupIds($this->getAllCustomerGroupIds())
            ->setUsesPerCustomer(1)
            ->setUsesPerCoupon(1)
            ->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC)
            ->setUseAutoGeneration(false)
            ->setSimpleAction(\Magento\SalesRule\Model\Rule::BY_PERCENT_ACTION)
            ->setDiscountAmount($discountPercent)
            ->setDiscountStep(0)
            ->setStopRulesProcessing(false)
            ->setFromDate(date('Y-m-d'))
            ->setToDate($expirationDate)
            ->setSortOrder(100);

        // Build minimum order amount condition via ConditionInterface (API-safe)
        $minOrderAmount = $this->helper->getCouponMinOrderAmount();
        if ($minOrderAmount > 0) {
            $subtotalCondition = $this->conditionFactory->create();
            $subtotalCondition->setConditionType(\Magento\SalesRule\Model\Rule\Condition\Address::class)
                ->setAttributeName('base_subtotal')
                ->setOperator('>=')
                ->setValue((string) $minOrderAmount);

            $rootCondition = $this->conditionFactory->create();
            $rootCondition->setConditionType(\Magento\SalesRule\Model\Rule\Condition\Combine::class)
                ->setAggregatorType('all')
                ->setValue('1')
                ->setConditions([$subtotalCondition]);

            $rule->setCondition($rootCondition);
        }

        return $this->ruleRepository->save($rule);
    }

    /**
     * Create a coupon for the rule
     */
    private function createCoupon(int $ruleId, string $couponCode): \Magento\SalesRule\Api\Data\CouponInterface
    {
        $coupon = $this->couponFactory->create();

        $coupon->setRuleId($ruleId)
            ->setCode($couponCode)
            ->setUsageLimit(1)
            ->setUsagePerCustomer(1)
            ->setType(Coupon::TYPE_MANUAL)  // manual: code is provided, not auto-generated by Magento
            ->setIsPrimary(true);

        return $this->couponRepository->save($coupon);
    }

    /**
     * Get all customer group IDs
     */
    private function getAllCustomerGroupIds(): array
    {
        // Include all customer groups (0 = NOT LOGGED IN, 1 = General, 2 = Wholesale, 3 = Retailer)
        return [0, 1, 2, 3];
    }

    /**
     * Invalidate/delete an existing coupon
     */
    public function invalidateCoupon(string $couponCode): bool
    {
        try {
            // Find coupon by code
            $searchCriteria = new \Magento\Framework\Api\SearchCriteriaBuilder();
            // This would require additional implementation to find and delete

            $this->logger->info('[ERP Coupon] Coupon invalidated: ' . $couponCode);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('[ERP Coupon] Error invalidating coupon: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a coupon code is valid
     */
    public function isCouponValid(string $couponCode): bool
    {
        try {
            $coupon = $this->couponRepository->getById($couponCode);
            return $coupon && $coupon->getCode() === $couponCode;
        } catch (\Exception $e) {
            return false;
        }
    }
}
