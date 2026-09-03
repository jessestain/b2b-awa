<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Adds the customer's ERP price list code to the HTTP context.
 *
 * Must run on beforeDispatch (not only getVaryString): cacheable PDP/PLP
 * depersonalize the customer session before HTML render, so PriceVisibility
 * can only see ERP state via HttpContext during toHtml().
 */
class CustomerPriceListContextPlugin
{
    private const CONTEXT_PRICE_LIST = 'erp_price_list';

    private CustomerSession $customerSession;
    private Config $config;
    private SyncLogResource $syncLogResource;
    private CustomerPriceProvider $customerPriceProvider;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;
    private ?ErpCodeResolver $erpCodeResolver;
    private HttpContext $httpContext;

    public function __construct(
        CustomerSession $customerSession,
        Config $config,
        SyncLogResource $syncLogResource,
        CustomerPriceProvider $customerPriceProvider,
        CustomerRepositoryInterface $customerRepository,
        HttpContext $httpContext,
        ?LoggerInterface $logger = null,
        ?ErpCodeResolver $erpCodeResolver = null
    ) {
        $this->customerSession = $customerSession;
        $this->config = $config;
        $this->syncLogResource = $syncLogResource;
        $this->customerPriceProvider = $customerPriceProvider;
        $this->customerRepository = $customerRepository;
        $this->httpContext = $httpContext;
        $this->logger = $logger ?? new NullLogger();
        $this->erpCodeResolver = $erpCodeResolver;
    }

    /**
     * Populate ERP price-list context before action dispatch / HTML render.
     *
     * @param object $subject
     * @param RequestInterface $request
     * @return array|null
     */
    public function beforeDispatch(object $subject, RequestInterface $request): ?array
    {
        $this->applyContext($request);
        return null;
    }

    /**
     * Keep FPC vary string in sync with the same ERP price-list token.
     */
    public function beforeGetVaryString(HttpContext $subject): void
    {
        $this->applyContext(null, $subject);
    }

    private function applyContext(?RequestInterface $request = null, ?HttpContext $context = null): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $context = $context ?? $this->httpContext;
        $sessionCookie = $request
            ? $request->getCookie(session_name())
            : ($_COOKIE[session_name()] ?? null);

        if ($sessionCookie === null) {
            return;
        }

        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            $erpCode = $this->resolveErpCode($customerId);

            if ($erpCode === null) {
                $context->setValue(self::CONTEXT_PRICE_LIST, 'logged_in', '0');
                return;
            }

            $contextToken = $this->customerPriceProvider->getContextTokenForCustomer($erpCode);
            $context->setValue(
                self::CONTEXT_PRICE_LIST,
                (string) ($contextToken ?? 'default:1'),
                '0'
            );
        } catch (\Exception $e) {
            $context->setValue(self::CONTEXT_PRICE_LIST, 'logged_in', '0');
            $this->logger->warning('[B2B] Failed to set ERP price list HTTP context', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function resolveErpCode(int $customerId): ?int
    {
        try {
            $customer = $this->customerRepository->getById($customerId);

            if ($this->erpCodeResolver !== null) {
                return $this->erpCodeResolver->resolveForCustomerId($customerId, $customer);
            }

            $attr = $customer->getCustomAttribute('erp_code');
            $erpCode = ($attr && $attr->getValue()) ? $attr->getValue() : null;

            if ($erpCode === null) {
                $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
            }

            return ($erpCode !== null && is_numeric($erpCode)) ? (int) $erpCode : null;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B] Failed to resolve ERP code for HTTP context variation', [
                'customer_id' => $customerId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
