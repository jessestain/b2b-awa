<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Observer;

use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

/**
 * Pre-loads customer-specific ERP prices in batch when a product collection loads.
 *
 * Without this, each product's getPrice() triggers an individual ERP query via
 * GroupPricePlugin → CustomerPriceProvider. This observer collects all SKUs from
 * the collection and warms the in-memory cache with a single batch query.
 */
class PreloadCustomerPrices implements ObserverInterface
{
    private CustomerSession $customerSession;
    private B2BConfig $b2bConfig;
    private SyncLogResource $syncLogResource;
    private CustomerPriceProvider $customerPriceProvider;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;
    private PhpCookieManager $cookieManager;

    /** Prevent multiple loads within a single request */
    private bool $loaded = false;
    private ?int $erpCode = null;

    public function __construct(
        CustomerSession $customerSession,
        B2BConfig $b2bConfig,
        SyncLogResource $syncLogResource,
        CustomerPriceProvider $customerPriceProvider,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger,
        PhpCookieManager $cookieManager
    ) {
        $this->customerSession = $customerSession;
        $this->b2bConfig = $b2bConfig;
        $this->syncLogResource = $syncLogResource;
        $this->customerPriceProvider = $customerPriceProvider;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->cookieManager = $cookieManager;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->b2bConfig->isEnabled()
            || $this->cookieManager->getCookie(session_name()) === null
            || !$this->customerSession->isLoggedIn()
        ) {
            return;
        }

        $collection = $observer->getEvent()->getCollection();
        if (!$collection instanceof \Magento\Catalog\Model\ResourceModel\Product\Collection) {
            return;
        }

        // Resolve ERP code once per request
        if ($this->erpCode === null && !$this->loaded) {
            $this->loaded = true;
            try {
                $customerId = (int) $this->customerSession->getCustomerId();

                // Check approval status
                $customer = $this->customerRepository->getById($customerId);
                $attr = $customer->getCustomAttribute('b2b_approval_status');
                if (!$attr || $attr->getValue() !== 'approved') {
                    return;
                }

                // Primary: erp_code attribute (definitive, single value)
                $erpAttr = $customer->getCustomAttribute('erp_code');
                $erpCodeStr = ($erpAttr && $erpAttr->getValue()) ? $erpAttr->getValue() : null;

                // Fallback: entity_map table
                if ($erpCodeStr === null) {
                    $erpCodeStr = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
                }

                if ($erpCodeStr !== null && is_numeric($erpCodeStr)) {
                    $this->erpCode = (int) $erpCodeStr;
                }
            } catch (\Exception $e) {
                $this->logger->debug('[ERP] PreloadCustomerPrices: ' . $e->getMessage());
                return;
            }
        }

        if ($this->erpCode === null) {
            return;
        }

        // Collect SKUs from the loaded collection
        $skus = [];
        foreach ($collection as $product) {
            $skus[] = $product->getSku();
        }

        if (empty($skus)) {
            return;
        }

        // Batch pre-load into CustomerPriceProvider's in-memory cache
        $this->customerPriceProvider->getCustomerPrices($this->erpCode, $skus);
    }
}
