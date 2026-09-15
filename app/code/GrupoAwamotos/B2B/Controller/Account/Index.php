<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Account;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Helper\GuestLoginRedirect;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;

/**
 * Página B2B da conta do cliente: /b2b/account/index
 * Mostra status de aprovação, dados da empresa, CNPJ.
 * Redireciona para login se não autenticado.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly Session $customerSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly Config $config,
        private readonly GuestLoginRedirect $guestLoginRedirect
    ) {
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $isEnabled = $this->config->isEnabled();
        $isLoggedIn = $this->customerSession->isLoggedIn();

        if (!$isEnabled) {
            return $this->redirectFactory->create()->setPath('/');
        }

        if (!$isLoggedIn) {
            return $this->guestLoginRedirect->create('b2b/account');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Minha Conta B2B'));
        $page->getConfig()->addBodyClass('b2b-account-dashboard');

        return $page;
    }
}
