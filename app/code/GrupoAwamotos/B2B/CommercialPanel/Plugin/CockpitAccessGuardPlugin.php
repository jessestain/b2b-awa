<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Plugin;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\CockpitAccessGuard;
use Magento\Backend\App\AbstractAction;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Bloqueia acesso direto a controllers técnicos para perfis cockpit-only.
 */
class CockpitAccessGuardPlugin
{
    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly CockpitAccessGuard $accessGuard,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlInterface $backendUrl,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /**
     * @param AbstractAction $subject
     * @param callable(RequestInterface): mixed $proceed
     * @return mixed
     */
    public function aroundDispatch(AbstractAction $subject, callable $proceed, RequestInterface $request)
    {
        $path = strtolower(
            (string) $request->getRouteName() . '/' . $request->getControllerName() . '/' . $request->getActionName()
        );
        $isCockpitOnly = $this->portfolioScope->isCockpitOnlyUser();
        $allowed = !$isCockpitOnly || $this->accessGuard->isRequestAllowed($request);
        $isOrderCreate = str_contains($path, 'order_create') || $path === 'sales/order/index';
        if ($isOrderCreate || ($isCockpitOnly && !$allowed)) {
        }

        if (!$isCockpitOnly) {
            return $proceed($request);
        }

        if ($allowed) {
            return $proceed($request);
        }

        $this->messageManager->addErrorMessage(
            __('Acesso negado. Utilize o menu AWA Comercial.')
        );

        $redirect = $this->redirectFactory->create();
        $redirect->setUrl(
            $this->backendUrl->getUrl('awa_commercial/commercialdashboard/index')
        );

        return $redirect;
    }
}
