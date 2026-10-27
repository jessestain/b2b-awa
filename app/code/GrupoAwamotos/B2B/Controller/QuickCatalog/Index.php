<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Helper\GuestLoginRedirect;
use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Página "Comprar produtos" — catálogo de compra rápida B2B.
 *
 * Visitante → login B2B com retorno para esta página (GuestLoginRedirect).
 * Conta sem liberação (pendente/rejeitada/suspensa/tabela em definição) →
 * a própria página renderiza o gate com a mensagem oficial da vitrine; o
 * catálogo e os endpoints JSON permanecem bloqueados (fail-closed).
 */
class Index implements HttpGetActionInterface
{
    /**
     * Inicializa o controller com as dependências de página e autorização.
     *
     * @param PageFactory $pageFactory
     * @param GuestLoginRedirect $guestLoginRedirect
     * @param AuthorizationService $authorizationService
     */
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly GuestLoginRedirect $guestLoginRedirect,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    /**
     * Renderiza a página do catálogo ou redireciona o visitante para o login.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $context = $this->authorizationService->resolve();

        if ($context['reason'] === AuthorizationService::REASON_LOGIN_REQUIRED) {
            return $this->guestLoginRedirect->create('b2b/quickcatalog');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Comprar produtos'));
        $page->getConfig()->addBodyClass('b2b-quickcatalog-page');

        return $page;
    }
}
