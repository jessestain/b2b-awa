<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\Customer;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Session\SessionManager;

/**
 * Impede session_start() em visitantes sem cookie PHPSESSID (FPC / Varnish).
 *
 * Magento\Catalog\Block\Category\Plugin\PriceBoxTags, Mirasvit SearchAutocomplete e
 * outros blocos chamam getCustomerGroupId() no render da PLP — isso iniciava sessão e
 * marcava a página como UNCACHEABLE.
 */
class SessionFpcGuardPlugin
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly AppState $appState
    ) {
    }

    /**
     * Bloqueia session_start() em GET/HEAD guest (FPC). POST/PUT/DELETE seguem para login/forms.
     *
     * @param callable(): SessionManager $proceed
     */
    public function aroundStart(SessionManager $subject, callable $proceed): SessionManager
    {
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return $proceed();
            }
        } catch (\Exception) {
            return $proceed();
        }

        if ($this->hasSessionCookie()) {
            return $proceed();
        }

        if ($this->request->isPost() || $this->request->isPut() || $this->request->isDelete()) {
            return $proceed();
        }

        if ($this->request->isGet() || $this->request->isHead()) {
            return $subject;
        }

        return $proceed();
    }

    private function hasSessionCookie(): bool
    {
        return $this->request->getCookie(session_name()) !== null;
    }

    /**
     * @param callable(): bool $proceed
     */
    public function aroundIsLoggedIn(Session $subject, callable $proceed): bool
    {
        if (!$this->hasSessionCookie()) {
            return false;
        }

        return (bool) $proceed();
    }

    /**
     * @param callable(): int|string|null $proceed
     * @return int|string|null
     */
    public function aroundGetCustomerId(Session $subject, callable $proceed)
    {
        if (!$this->hasSessionCookie()) {
            return null;
        }

        return $proceed();
    }

    /**
     * @param callable(): int $proceed
     */
    public function aroundGetCustomerGroupId(Session $subject, callable $proceed): int
    {
        if (!$this->hasSessionCookie()) {
            return 0;
        }

        return (int) $proceed();
    }
}
