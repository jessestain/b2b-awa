<?php

/**
 * Plugin to add B2B approval status to HTTP Context for FPC cache variation.
 *
 * Without this, Magento's Full Page Cache uses the same cache key for all
 * logged-in customers in the same customer_group — a page rendered for a
 * "pending" customer (prices hidden) would be served to "approved" customers.
 *
 * Vary only by auth + approval status. Personal data (name, company, orders)
 * must stay in customer-data sections (b2b_panel, last-ordered-items), not HTML.
 * customer_id in the vary string created one Varnish object per customer and
 * forced PHP (~1s TTFB) on every logged-in catalog/home hit.
 *
 * Price lists stay on CustomerPriceListContextPlugin (~13 variants).
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\App;

use GrupoAwamotos\B2B\Helper\Config;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;

class HttpContextPlugin
{
    public function __construct(
        private readonly HttpContext $httpContext,
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly PhpCookieManager $cookieManager
    ) {
    }

    /**
     * Set B2B approval status in HTTP context before action dispatch.
     *
     * IMPORTANT PERFORMANCE NOTE: This method must NOT start a PHP session for anonymous
     * requests. Calling customerSession->isLoggedIn() starts the session, which:
     * 1. Prevents the PhpCookieDisabler from removing PHPSESSID on FPC HIT responses
     * 2. Adds Redis session overhead even when the Full Page Cache serves the HTML
     *
     * Guard: if there is no session cookie in the request, the user is definitively a guest —
     * skip session start entirely and set the default context value directly.
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
     * Ensure FPC vary string includes B2B auth context before it is hashed.
     */
    public function beforeGetVaryString(HttpContext $subject): void
    {
        $this->applyContext();
    }

    private function applyContext(?RequestInterface $request = null): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // If there is no session cookie in the request, the visitor cannot be logged in.
        // Avoid starting the PHP session (session_start) entirely for anonymous hits so
        // that the FPC PhpCookieDisabler can strip PHPSESSID from cached responses.
        // NOTE: use PHP's session_name() — do NOT call $this->customerSession->*() here
        // because CustomerSession extends SessionManager whose constructor starts the session.
        $sessionCookie = $request
            ? $request->getCookie(session_name())
            : $this->cookieManager->getCookie(session_name());
        if ($sessionCookie === null) {
            $this->httpContext->setValue(CustomerContext::CONTEXT_AUTH, false, false);
            $this->httpContext->setValue('b2b_approval_status', 'guest', 'guest');
            return;
        }

        $approvalStatus = 'guest';
        $isLoggedIn = false;

        if ($this->customerSession->isLoggedIn()) {
            $isLoggedIn = true;
            $customerData = $this->customerSession->getCustomerData();
            if ($customerData) {
                $attr = $customerData->getCustomAttribute('b2b_approval_status');
                $approvalStatus = $attr ? (string) $attr->getValue() : 'approved';
            }
        }

        $this->httpContext->setValue(CustomerContext::CONTEXT_AUTH, $isLoggedIn, false);
        $this->httpContext->setValue('b2b_approval_status', $approvalStatus, 'guest');
    }
}
