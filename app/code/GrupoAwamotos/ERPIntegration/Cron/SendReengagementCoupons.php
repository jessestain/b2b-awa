<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\ERPIntegration\Model\Rfm\Calculator as RfmCalculator;
use GrupoAwamotos\ERPIntegration\Model\Coupon\Generator as CouponGenerator;
use GrupoAwamotos\ERPIntegration\Model\Alert\EmailSender;
use GrupoAwamotos\ERPIntegration\Model\Cart\SuggestedCart;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron Job - Send Re-engagement Emails with Coupons
 *
 * Sends personalized emails with coupons to at-risk customers
 * Runs weekly on Tuesdays
 */
class SendReengagementCoupons
{
    private RfmCalculator $rfmCalculator;
    private CouponGenerator $couponGenerator;
    private EmailSender $emailSender;
    private SuggestedCart $suggestedCart;
    private CustomerRepositoryInterface $customerRepository;
    private Helper $helper;
    private LoggerInterface $logger;

    public function __construct(
        RfmCalculator $rfmCalculator,
        CouponGenerator $couponGenerator,
        EmailSender $emailSender,
        SuggestedCart $suggestedCart,
        CustomerRepositoryInterface $customerRepository,
        Helper $helper,
        LoggerInterface $logger
    ) {
        $this->rfmCalculator = $rfmCalculator;
        $this->couponGenerator = $couponGenerator;
        $this->emailSender = $emailSender;
        $this->suggestedCart = $suggestedCart;
        $this->customerRepository = $customerRepository;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Execute cron job
     */
    public function execute(): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isCouponAutoSendEnabled()) {
            return;
        }

        try {
            // Get at-risk customers (limit to prevent email flooding)
            $atRiskCustomers = $this->rfmCalculator->getAtRiskCustomers(20);

            if (empty($atRiskCustomers)) {
                $this->logger->info('[ERP Cron] No at-risk customers found for re-engagement.');
                return;
            }

            $sentCount = 0;
            $errorCount = 0;
            $validDays = $this->helper->getCouponValidDays();

            foreach ($atRiskCustomers as $customerData) {
                try {
                    // Get Magento customer ID from ERP code
                    $magentoCustomerId = $this->findMagentoCustomerId($customerData);

                    if (!$magentoCustomerId) {
                        continue;
                    }

                    // Get customer details
                    $customer = $this->customerRepository->getById($magentoCustomerId);
                    $segment = $customerData['segment'] ?? 'at_risk';

                    // Generate coupon
                    $coupon = $this->couponGenerator->generateForCustomer(
                        $magentoCustomerId,
                        $segment,
                        null,
                        $validDays
                    );

                    if (!$coupon) {
                        $this->logger->warning(sprintf(
                            '[ERP Cron] Failed to generate coupon for customer %s',
                            $customer->getEmail()
                        ));
                        continue;
                    }

                    // Get suggested products
                    $suggestedProducts = $this->getSuggestedProductsForEmail(
                        $customerData['customer_code'] ?? $magentoCustomerId
                    );

                    // Send email
                    $sent = $this->emailSender->sendReengagementEmail(
                        $customer->getEmail(),
                        $customer->getFirstname(),
                        $customerData['days_since_purchase'] ?? 90,
                        $suggestedProducts,
                        $coupon['coupon_code'],
                        $coupon['discount_percent']
                    );

                    if ($sent) {
                        $sentCount++;
                        $this->logger->info(sprintf(
                            '[ERP Cron] Re-engagement email sent to %s with coupon %s (%d%% off)',
                            $customer->getEmail(),
                            $coupon['coupon_code'],
                            $coupon['discount_percent']
                        ));
                    } else {
                        $errorCount++;
                    }

                    // Small delay to prevent email server overload
                    usleep(500000); // 0.5 seconds
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error(sprintf(
                        '[ERP Cron] Error processing customer: %s',
                        $e->getMessage()
                    ));
                }
            }

            $this->logger->info(sprintf(
                '[ERP Cron] Re-engagement campaign completed: %d sent, %d errors',
                $sentCount,
                $errorCount
            ));
        } catch (\Exception $e) {
            $this->logger->error('[ERP Cron] Re-engagement campaign error: ' . $e->getMessage());
        }
    }

    /**
     * Find Magento customer ID from ERP customer data
     */
    private function findMagentoCustomerId(array $customerData): ?int
    {
        // First check if we have a direct mapping
        if (!empty($customerData['magento_customer_id'])) {
            return (int) $customerData['magento_customer_id'];
        }

        // Try to find by email
        if (!empty($customerData['email'])) {
            try {
                $customer = $this->customerRepository->get($customerData['email']);
                return (int) $customer->getId();
            } catch (\Exception $e) {
                $this->logger->warning('[SendReengagementCoupons::findMagentoCustomerId] Exceção ignorada: ' . $e->getMessage());
                // Customer not found by email
            }
        }

        // If we have customer_code, assume it's the Magento ID (for testing)
        if (!empty($customerData['customer_code'])) {
            try {
                $customer = $this->customerRepository->getById((int) $customerData['customer_code']);
                return (int) $customer->getId();
            } catch (\Exception $e) {
                $this->logger->warning('[SendReengagementCoupons::findMagentoCustomerId] Exceção ignorada: ' . $e->getMessage());
                // Customer not found
            }
        }

        return null;
    }

    /**
     * Get suggested products formatted for email
     */
    private function getSuggestedProductsForEmail(int $customerCode): array
    {
        try {
            $cart = $this->suggestedCart->buildSuggestedCart($customerCode);
            $products = [];

            // Get up to 3 products from reorder suggestions
            $reorderItems = $cart['reorder_items'] ?? [];
            foreach (array_slice($reorderItems, 0, 3) as $item) {
                if (!empty($item['product'])) {
                    $product = $item['product'];
                    $products[] = [
                        'name' => $product->getName(),
                        'price' => number_format((float) $product->getFinalPrice(), 2, ',', '.'),
                        'url' => $product->getProductUrl(),
                        'image_url' => $this->getProductImageUrl($product),
                    ];
                }
            }

            return $products;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get product image URL for email
     */
    private function getProductImageUrl($product): string
    {
        try {
            $imageUrl = $product->getData('small_image');
            if ($imageUrl && $imageUrl !== 'no_selection') {
                // Would need media URL helper for full implementation
                return '';
            }
        } catch (\Exception $e) {
            $this->logger->warning('[SendReengagementCoupons::getProductImageUrl] Exceção ignorada: ' . $e->getMessage());
            // Ignore
        }

        return '';
    }
}
