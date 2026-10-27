<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LlmProvider implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openrouter', 'label' => 'OpenRouter (padrão)'],
            ['value' => 'deepseek', 'label' => 'DeepSeek direto (cai no OpenRouter se falhar)'],
        ];
    }
}
