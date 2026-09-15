<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Adminhtml\CommercialCustomer;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::commercial_customer_360';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly PortfolioScopeInterface $portfolioScope
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id');
        $canBypass = $this->portfolioScope->canBypassPortfolioScope();
        $isCockpitOnly = $this->portfolioScope->isCockpitOnlyUser();
        $canAccess = $customerId > 0 && $this->portfolioScope->canAccessCustomer($customerId);
        $outcome = $customerId <= 0 ? 'redirect_missing_id' : ($canAccess ? 'render_360' : 'redirect_out_of_portfolio');
        // #region agent log
        file_put_contents('/home/jessessh/htdocs/srv1113343.hstgr.cloud/.cursor/debug-302306.log', json_encode(['sessionId' => '302306', 'runId' => 'verify', 'hypothesisId' => 'H2', 'location' => 'CommercialCustomer/View.php:execute', 'message' => 'ficha 360 access', 'data' => ['customerId' => $customerId, 'canBypass' => $canBypass, 'isCockpitOnly' => $isCockpitOnly, 'canAccess' => $canAccess, 'outcome' => $outcome], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        if ($customerId <= 0) {
            $this->messageManager->addErrorMessage(__('Cliente não informado.'));
            return $this->resultRedirectFactory->create()->setPath('awa_commercial/commercialportfolio/index');
        }

        if (!$canAccess) {
            $this->messageManager->addErrorMessage(__('Cliente fora da sua carteira comercial.'));
            return $this->resultRedirectFactory->create()->setPath('awa_commercial/commercialportfolio/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('GrupoAwamotos_B2B::commercial_portfolio');
        $resultPage->getConfig()->getTitle()->prepend(__('Ficha 360° do Cliente'));

        return $resultPage;
    }
}
