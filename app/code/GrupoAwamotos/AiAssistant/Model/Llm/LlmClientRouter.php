<?php
declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Llm;

use GrupoAwamotos\AiAssistant\Api\Data\LlmResponseInterface;
use GrupoAwamotos\AiAssistant\Api\LlmClientInterface;
use GrupoAwamotos\AiAssistant\Exception\LlmException;
use GrupoAwamotos\AiAssistant\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o provedor de LLM pela config `ai_assistant/general/provider`.
 *
 * - `openrouter` (padrão): comportamento idêntico ao de antes.
 * - `deepseek`: API direta da DeepSeek; se ela falhar (rede, 4xx/5xx, chave),
 *   cai no OpenRouter na mesma requisição, para o chat não parar. A queda é
 *   registrada no log com o motivo.
 *
 * A troca de provedor é configuração (`config:set`), não deploy.
 */
class LlmClientRouter implements LlmClientInterface
{
    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly OpenRouterClient $deepSeek,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function chat(array $messages, array $tools = []): LlmResponseInterface
    {
        if ($this->config->getLlmProvider() !== 'deepseek' || !$this->deepSeek->isConfigured()) {
            return $this->openRouter->chat($messages, $tools);
        }

        try {
            return $this->deepSeek->chat($messages, $tools);
        } catch (LlmException $e) {
            if (!$this->openRouter->isConfigured()) {
                throw $e;
            }
            $this->logger->warning('[AiAssistant] DeepSeek falhou; usando OpenRouter nesta requisicao', [
                'reason' => substr($e->getMessage(), 0, 300),
            ]);

            return $this->openRouter->chat($messages, $tools);
        }
    }
}
