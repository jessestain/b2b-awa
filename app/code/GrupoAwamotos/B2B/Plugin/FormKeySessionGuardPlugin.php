<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;

/**
 * Prevents PHP session_start() on FPC HIT paths caused by RegisterFormKeyFromCookie.
 *
 * Skip FormKey::set() only on cacheable GET/HEAD without PHPSESSID and outside
 * login/checkout/cart routes. Auth and checkout forms must persist form_key server-side.
 */
class FormKeySessionGuardPlugin
{
    public function __construct(
        private readonly HttpRequest $request
    ) {
    }

    /**
     * @param FormKey $subject
     * @param callable $proceed
     * @param string|null $value
     * @return void
     */
    public function aroundSet(FormKey $subject, callable $proceed, ?string $value): void
    {
        if ($value === null) {
            $proceed($value);
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $proceed($value);
            return;
        }

        if ($this->request->isPost() || $this->request->isPut() || $this->request->isDelete()) {
            $proceed($value);
            return;
        }

        if ($this->shouldPersistFormKeyOnGet()) {
            $proceed($value);
            return;
        }
    }

    /**
     * Routes and sessions that render POST forms and require server-side form_key.
     */
    private function shouldPersistFormKeyOnGet(): bool
    {
        $cookie = $this->request->getCookie(session_name());
        if ($cookie !== null && $cookie !== '') {
            return true;
        }

        $path = rtrim($this->request->getPathInfo(), '/') ?: '/';

        foreach ([
            '/b2b/account/login',
            '/customer/account/login',
            '/checkout/cart',
            '/checkout',
            '/onepagecheckout',
            '/expresscheckout.html',
            ] as $criticalPath) {
            if ($path === $criticalPath || str_starts_with($path, $criticalPath . '/')) {
                return true;
            }
        }

        $module = (string) $this->request->getModuleName();
        if (in_array($module, ['b2b', 'customer', 'checkout'], true)) {
            return true;
        }

        return false;
    }
}
