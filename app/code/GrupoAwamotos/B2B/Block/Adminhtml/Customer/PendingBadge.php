<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Adminhtml\Customer;

use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;

/**
 * Exibe o número de cadastros B2B pendentes de aprovação no topo da lista admin.
 */
class PendingBadge extends Template
{
    protected $_template = 'GrupoAwamotos_B2B::customer/pending_badge.phtml';

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPendingCount(): int
    {
        return (int) $this->collectionFactory->create()
            ->addAttributeToFilter(
                'b2b_approval_status',
                ['eq' => ApprovalStatus::STATUS_PENDING]
            )
            ->getSize();
    }
}
