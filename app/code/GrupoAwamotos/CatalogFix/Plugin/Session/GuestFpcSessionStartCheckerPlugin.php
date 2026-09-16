<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\Session;

use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Impede session_start() em GET/HEAD de visitantes sem cookie PHPSESSID em rotas FPC-safe.
 *
 * Padrão Magento: plugin em {@see SessionStartChecker} (como GraphQL DisableSession e PayPal
 * TransparentSessionChecker). Afeta Customer, Checkout, Message e demais SessionManager.
 */
class GuestFpcSessionStartCheckerPlugin
{
    /**
     * Módulos/ações que precisam de sessão PHP em GET (minicart, conta, checkout).
     *
     * @var list<string>
     */
    private const SESSION_REQUIRED_MODULES = [
        'checkout',
        'customer',
        'sales',
        'wishlist',
        'review',
        'paypal',
        'b2b',
        'erpintegration',
        'persistent',
        'multishipping',
        'vault',
        'captcha',
        'sendfriend',
        'newsletter',
        'invitation',
        'giftmessage',
        'giftcard',
        'braintree',
        'payment',
    ];

    /** @var list<string> */
    private const SESSION_REQUIRED_ACTIONS = [
        'customer_section_load',
    ];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly AppState $appState
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result) {
            return false;
        }

        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return $result;
            }
        } catch (\Exception) {
            return $result;
        }

        if ($this->request->isPost() || $this->request->isPut() || $this->request->isDelete()) {
            return $result;
        }

        if (!($this->request->isGet() || $this->request->isHead())) {
            return $result;
        }

        if ($this->hasSessionCookie()) {
            return $result;
        }

        if ($this->sessionRequiredForRequest()) {
            return $result;
        }

        return false;
    }

    private function sessionRequiredForRequest(): bool
    {
        if (in_array($this->request->getFullActionName(), self::SESSION_REQUIRED_ACTIONS, true)) {
            return true;
        }

        $moduleName = (string) $this->request->getModuleName();

        return $moduleName !== '' && in_array($moduleName, self::SESSION_REQUIRED_MODULES, true);
    }

    private function hasSessionCookie(): bool
    {
        $cookie = $this->request->getCookie(session_name());

        return $cookie !== null && $cookie !== '';
    }
}
