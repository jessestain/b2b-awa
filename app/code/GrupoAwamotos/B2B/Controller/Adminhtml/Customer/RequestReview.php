<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Adminhtml\Customer;

use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

class RequestReview extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::customer_approval';

    private CustomerApprovalInterface $customerApproval;
    private AdminSession $adminSession;

    public function __construct(
        Context $context,
        CustomerApprovalInterface $customerApproval,
        AdminSession $adminSession
    ) {
        $this->customerApproval = $customerApproval;
        $this->adminSession = $adminSession;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $customerId = (int) $this->getRequest()->getParam('customer_id');

        if (!$customerId) {
            $this->messageManager->addErrorMessage(__('ID do cliente não informado.'));
            return $redirect->setPath('*/*/pending');
        }

        try {
            $adminUserId = (int) $this->adminSession->getUser()->getId();
            $message = $this->getRequest()->getParam('message');

            $this->customerApproval->requestDataReview($customerId, $adminUserId, $message);

            $this->messageManager->addSuccessMessage(
                __('Solicitação de revisão enviada ao cliente #%1.', $customerId)
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Erro ao solicitar revisão: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/pending');
    }
}
