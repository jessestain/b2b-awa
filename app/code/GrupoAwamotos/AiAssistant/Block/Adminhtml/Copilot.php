<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Block\Adminhtml;

use GrupoAwamotos\AiAssistant\Helper\Config;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Block for the Admin Copilot page.
 *
 * NOTE: $formKey is declared without `readonly` because Magento\Backend\Block\Template
 * already declares it as non-readonly, and PHP 8.4 prohibits redeclaring a non-readonly
 * property as readonly in a subclass.
 */
class Copilot extends Template
{
    private Config $aiConfig;
    private Json $jsonSerializer;
    private FormKey $formKeyHelper;

    public function __construct(
        Context $context,
        Config  $config,
        Json    $json,
        FormKey $formKey,
        array   $data = []
    ) {
        $this->aiConfig       = $config;
        $this->jsonSerializer = $json;
        $this->formKeyHelper  = $formKey;
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->aiConfig->isAdminCopilotEnabled();
    }

    public function getCopilotConfigJson(): string
    {
        return $this->jsonSerializer->serialize([
            'endpoint' => $this->getUrl('grupoawamotos_aiassistant/copilot/chat'),
            'formKey'  => $this->formKeyHelper->getFormKey(),
        ]);
    }
}
