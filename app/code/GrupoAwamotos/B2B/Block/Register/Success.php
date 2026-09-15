<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Register;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Success extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProtocol(): string
    {
        return trim((string) $this->customerSession->getData('b2b_register_protocol'));
    }
}
