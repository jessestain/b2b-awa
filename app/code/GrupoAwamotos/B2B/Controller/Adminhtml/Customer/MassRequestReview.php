<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Adminhtml\Customer;

use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

class MassRequestReview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::customer_approval';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly CustomerApprovalInterface $customerApproval,
        private readonly AdminSession $adminSession,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $message = trim((string) $this->getRequest()->getParam('message', ''));
        $message = $message !== '' ? $message : 'Precisamos de informações adicionais para concluir a análise.';

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $collection->addAttributeToSelect('b2b_approval_status');
            $collection->addAttributeToFilter(
                'b2b_approval_status',
                ['eq' => ApprovalStatus::STATUS_PENDING]
            );

            $adminUserId = (int) $this->adminSession->getUser()->getId();
            $updated = 0;
            $skipped = 0;

            foreach ($collection as $customer) {
                $customerId = (int) $customer->getId();
                if ($customerId <= 0) {
                    $skipped++;
                    continue;
                }

                try {
                    $ok = $this->customerApproval->requestDataReview($customerId, $adminUserId, $message);
                    if ($ok) {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        '[B2B MassRequestReview] Falha no cliente #' . $customerId . ': ' . $e->getMessage()
                    );
                    $skipped++;
                }
            }

            if ($updated > 0) {
                $this->messageManager->addSuccessMessage(
                    __('%1 cadastro(s) enviado(s) para revisão de dados.', $updated)
                );
            }

            if ($skipped > 0) {
                $this->messageManager->addWarningMessage(
                    __('%1 registro(s) ignorado(s) ou com erro.', $skipped)
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('[B2B MassRequestReview] Erro: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Erro interno. Verifique os logs.'));
        }

        return $redirect->setPath('*/*/pending');
    }
}
