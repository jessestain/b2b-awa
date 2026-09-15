<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

/**
 * Pre-warms ERP price cache for all products in a loaded collection.
 *
 * Converts N individual ERP queries (one per product in afterGetPrice plugin)
 * into a single batch query when a product collection loads on frontend pages.
 *
 * Works because CustomerPriceProvider is a singleton: warming its in-memory
 * $priceCache here means GroupPricePlugin::afterGetPrice() hits the cache
 * instead of querying the ERP for each product.
 */
class B2BPriceWarmObserver implements ObserverInterface
{
    private Config $config;
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private CustomerPriceProvider $customerPriceProvider;
    private SyncLogResource $syncLogResource;
    private LoggerInterface $logger;
    private ?ErpCodeResolver $erpCodeResolver;
    private PhpCookieManager $cookieManager;

    /** @var int|null|false  false = not yet fetched */
    private int|null|false $erpCodeCache = false;

    /** @var string|null|false  false = not yet fetched */
    private string|null|false $approvalCache = false;

    /** @var array<string, bool> */
    private array $warmedCollections = [];

    public function __construct(
        Config $config,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        CustomerPriceProvider $customerPriceProvider,
        SyncLogResource $syncLogResource,
        LoggerInterface $logger,
        PhpCookieManager $cookieManager,
        ?ErpCodeResolver $erpCodeResolver = null
    ) {
        $this->config                = $config;
        $this->customerSession       = $customerSession;
        $this->customerRepository    = $customerRepository;
        $this->customerPriceProvider = $customerPriceProvider;
        $this->syncLogResource       = $syncLogResource;
        $this->logger                = $logger;
        $this->cookieManager         = $cookieManager;
        $this->erpCodeResolver       = $erpCodeResolver;
    }

    public function execute(Observer $observer): void
    {
        // Avoid session_start() for guests: check cookie before calling isLoggedIn().
        if (!$this->config->isEnabled()
            || $this->cookieManager->getCookie(session_name()) === null
            || !$this->customerSession->isLoggedIn()
        ) {
            return;
        }

        if ($this->getApprovalStatus() !== 'approved') {
            return;
        }

        $erpCode = $this->getErpCode();
        if ($erpCode === null) {
            return;
        }

        $collection = $observer->getData('collection');
        if (!$collection) {
            return;
        }

        $skus = [];
        foreach ($collection as $product) {
            $sku = $product->getSku();
            if ($sku !== null && $sku !== '') {
                $skus[] = $sku;
            }
        }

        if (empty($skus)) {
            return;
        }

        // Avoid enormous IN-clauses on full catalog pages — cap at 300 SKUs.
        // Prices for SKUs beyond this limit will be fetched individually by
        // GroupPricePlugin::afterGetPrice() via getPriceFromList() (single-SKU path),
        // which checks Redis before hitting ERP.
        $skus = array_unique($skus);
        if (count($skus) > 300) {
            $skus = array_slice($skus, 0, 300);
        }

        $warmKey = $erpCode . ':' . hash('xxh128', implode('|', array_values($skus)));
        if (isset($this->warmedCollections[$warmKey])) {
            return;
        }

        // Single batch query warms in-memory + persistent cache in CustomerPriceProvider.
        // GroupPricePlugin::afterGetPrice() finds all prices already cached; zero ERP round-trips per product.
        $this->customerPriceProvider->getCustomerPrices($erpCode, $skus);
        $this->warmedCollections[$warmKey] = true;
    }

    private function getErpCode(): ?int
    {
        if ($this->erpCodeCache !== false) {
            return $this->erpCodeCache;
        }
        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                $this->erpCodeCache = null;
                return null;
            }
            $customer = $this->customerRepository->getById($customerId);

            if ($this->erpCodeResolver !== null) {
                $this->erpCodeCache = $this->erpCodeResolver->resolveForCustomerId($customerId, $customer);
                return $this->erpCodeCache;
            }

            $attr = $customer->getCustomAttribute('erp_code');
            $erpCode = ($attr && $attr->getValue()) ? $attr->getValue() : null;
            if ($erpCode === null) {
                $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
            }
            $this->erpCodeCache = ($erpCode !== null && is_numeric($erpCode)) ? (int) $erpCode : null;
        } catch (\Exception $e) {
            $this->logger->error('[B2B PriceWarm] getErpCode error: ' . $e->getMessage());
            $this->erpCodeCache = null;
        }
        return $this->erpCodeCache;
    }

    private function getApprovalStatus(): ?string
    {
        if ($this->approvalCache !== false) {
            return $this->approvalCache;
        }
        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                $this->approvalCache = null;
                return null;
            }
            $customer            = $this->customerRepository->getById($customerId);
            $attr                = $customer->getCustomAttribute('b2b_approval_status');
            $this->approvalCache = $attr ? $attr->getValue() : null;
        } catch (\Exception $e) {
            $this->approvalCache = null;
        }
        return $this->approvalCache;
    }
}
