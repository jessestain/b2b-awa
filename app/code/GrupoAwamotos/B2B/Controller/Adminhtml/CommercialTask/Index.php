<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Adminhtml\CommercialTask;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::commercial_tasks';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly PortfolioScopeInterface $portfolioScope
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('GrupoAwamotos_B2B::commercial_tasks');
        $isSupervisor = $this->portfolioScope->canViewAllPortfolios();
        $resultPage->getConfig()->getTitle()->prepend(
            $isSupervisor ? __('Tarefas da Equipe') : __('Minhas Tarefas')
        );

        return $resultPage;
    }
}
