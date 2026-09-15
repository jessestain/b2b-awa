<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Adminhtml\CommercialContact;

use GrupoAwamotos\B2B\CommercialPanel\Api\ContactLogManagementInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::commercial_contact_save';

    public function __construct(
        Context $context,
        private readonly ContactLogManagementInterface $contactLogManagement
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $customerId = (int) $this->getRequest()->getPostValue('customer_id');

        try {
            if (!$this->_formKeyValidator->validate($this->getRequest())) {
                throw new LocalizedException(__('Formulário inválido. Atualize a página e tente novamente.'));
            }

            $adminUser = $this->_auth->getUser();
            if (!$adminUser || !$adminUser->getId()) {
                throw new LocalizedException(__('Sessão expirada.'));
            }

            $this->contactLogManagement->registerContact(
                (array) $this->getRequest()->getPostValue(),
                (int) $adminUser->getId()
            );

            $this->messageManager->addSuccessMessage(__('Contato registrado com sucesso.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Não foi possível registrar o contato.'));
        }

        return $redirect->setPath(
            'awa_commercial/commercialcustomer/view',
            [
                'customer_id' => $customerId > 0 ? $customerId : null,
                '_query' => ['tab' => 'contatos'],
            ]
        );
    }
}
