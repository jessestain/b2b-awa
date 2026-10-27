<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Llm;

use GrupoAwamotos\AiAssistant\Api\Data\LlmResponseInterface;
use GrupoAwamotos\AiAssistant\Api\LlmClientInterface;
use GrupoAwamotos\AiAssistant\Exception\LlmException;
use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\Data\LlmResponse;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * OpenRouter client — unified gateway to 400+ models via OpenAI-compatible API.
 *
 * Hermes models on OpenRouter often lack OpenAI "tools" endpoints. For those we
 * use Hermes native <tool_call> XML prompting (function-calling fine-tune).
 *
 * @see https://openrouter.ai/docs
 */
class OpenRouterClient implements LlmClientInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** DeepSeek fala o mesmo formato OpenAI (tools / tool_calls). */
    private const DEEPSEEK_API_URL = 'https://api.deepseek.com/chat/completions';

    /**
     * @param string $provider 'openrouter' (padrão) ou 'deepseek'. A mesma classe
     *        serve os dois via virtualType em di.xml; muda só URL, chave, modelo
     *        e timeout. Nada mais do fluxo (tools, parse, Hermes) é duplicado.
     */
    public function __construct(
        private readonly Curl            $curl,
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
        private readonly string          $provider = 'openrouter'
    ) {
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return $this->resolveApiKey() !== '';
    }

    private function isDeepSeek(): bool
    {
        return $this->provider === 'deepseek';
    }

    private function resolveApiKey(): string
    {
        return $this->isDeepSeek() ? $this->config->getDeepSeekApiKey() : $this->config->getOpenRouterApiKey();
    }

    private function resolveModel(): string
    {
        return $this->isDeepSeek() ? $this->config->getDeepSeekModel() : $this->config->getOpenRouterModel();
    }

    public function chat(array $messages, array $tools = []): LlmResponseInterface
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            throw new LlmException(new Phrase(
                '%1 API key not configured. Go to Stores › Configuration › AWA Motos › Assistente de IA.',
                [$this->isDeepSeek() ? 'DeepSeek' : 'OpenRouter']
            ));
        }

        $model = $this->resolveModel();
        $hermesNative = $this->isHermesModel($model) && $tools !== [];

        if ($hermesNative) {
            $messages = $this->prepareHermesMessages($messages, $tools);
        }

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $hermesNative ? 0.2 : 0.6,
            'max_tokens'  => 1024,
        ];

        // Only attach OpenAI tools when the provider supports them (non-Hermes).
        if (!$hermesNative && $tools !== []) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new LlmException(new Phrase('Failed to encode LLM request payload.'));
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => 'https://awamotos.com',
                'X-Title'       => 'AWA Motos AI Assistant',
            ]);
            $this->curl->setTimeout($this->isDeepSeek()
                ? $this->config->getDeepSeekTimeoutSeconds()
                : $this->config->getOpenRouterTimeoutSeconds());
            $this->curl->post($this->isDeepSeek() ? self::DEEPSEEK_API_URL : self::API_URL, $body);

            $status   = $this->curl->getStatus();
            $rawBody  = (string) $this->curl->getBody();
            $response = json_decode($rawBody, true);

            if ($status !== 200 || !is_array($response)) {
                $errorMsg = is_array($response) ? ($response['error']['message'] ?? 'unknown') : substr($rawBody, 0, 300);
                /* "Provider returned error" sozinho não diz nada: o motivo real
                 * vem em error.metadata.raw (texto do provedor) e provider_name. */
                $meta = is_array($response) ? ($response['error']['metadata'] ?? []) : [];
                $this->logger->error('[AiAssistant] OpenRouter API error', [
                    'llm'      => $this->provider,
                    'status'   => $status,
                    'error'    => $errorMsg,
                    'model'    => $model,
                    'provider' => is_array($meta) ? ($meta['provider_name'] ?? null) : null,
                    'raw'      => is_array($meta) ? substr((string) ($meta['raw'] ?? ''), 0, 600) : null,
                ]);
                throw new LlmException(new Phrase(
                    '%1 API returned status %2: %3',
                    [$this->isDeepSeek() ? 'DeepSeek' : 'OpenRouter', $status, $errorMsg]
                ));
            }
        } catch (LlmException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[AiAssistant] ' . $this->provider . ' HTTP error: ' . $e->getMessage());
            throw new LlmException(new Phrase('LLM HTTP error: %1', [$e->getMessage()]));
        }

        return $this->parseResponse($response);
    }

    private function isHermesModel(string $model): bool
    {
        return str_contains(strtolower($model), 'hermes');
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    private function prepareHermesMessages(array $messages, array $tools): array
    {
        $toolsJson = [];
        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? $tool;
            $toolsJson[] = [
                'type' => 'function',
                'function' => [
                    'name' => $fn['name'] ?? '',
                    'description' => $fn['description'] ?? '',
                    'parameters' => $fn['parameters'] ?? new \stdClass(),
                ],
            ];
        }

        $toolPrompt = "You are a function calling AI model. You are provided with function signatures "
            . "within <tools></tools> XML tags. You may call one or more functions to assist with the user query. "
            . "Don't make assumptions about what values to plug into functions. Here are the available tools:\n"
            . "<tools>\n"
            . json_encode($toolsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n</tools>\n"
            . 'Use the following pydantic model json schema for each tool call you will make: '
            . '{"properties":{"arguments":{"title":"Arguments","type":"object"},"name":{"title":"Name","type":"string"}},'
            . '"required":["arguments","name"],"title":"FunctionCall","type":"object"} '
            . "For each function call return a json object with function name and arguments within "
            . "<tool_call></tool_call> XML tags as follows:\n"
            . "<tool_call>\n{\"name\": <function-name>, \"arguments\": <args-dict>}\n</tool_call>\n"
            . 'If the system message already contains CATÁLOGO JÁ CONSULTADO, do not call catalog_search '
            . 'or fitment_search — answer only from that JSON.';

        $out = [];
        $systemMerged = false;
        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? 'user');
            if ($role === 'system' && !$systemMerged) {
                $content = trim((string) ($msg['content'] ?? '') . "\n\n" . $toolPrompt);
                $out[] = ['role' => 'system', 'content' => $content];
                $systemMerged = true;
                continue;
            }
            if ($role === 'tool') {
                $out[] = [
                    'role' => 'user',
                    'content' => "<tool_response>\n"
                        . (string) ($msg['content'] ?? '')
                        . "\n</tool_response>",
                ];
                continue;
            }
            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $xml = '';
                foreach ((array) $msg['tool_calls'] as $tc) {
                    $fn = $tc['function'] ?? $tc;
                    $xml .= '<tool_call>' . json_encode([
                        'name' => $fn['name'] ?? ($tc['name'] ?? ''),
                        'arguments' => is_string($fn['arguments'] ?? null)
                            ? (json_decode((string) $fn['arguments'], true) ?: [])
                            : (array) ($fn['arguments'] ?? ($tc['arguments'] ?? [])),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</tool_call>';
                }
                $out[] = ['role' => 'assistant', 'content' => $xml];
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => (string) ($msg['content'] ?? ''),
            ];
        }

        if (!$systemMerged) {
            array_unshift($out, ['role' => 'system', 'content' => $toolPrompt]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function parseResponse(array $response): LlmResponseInterface
    {
        $choice    = $response['choices'][0] ?? [];
        $message   = $choice['message'] ?? [];
        $content   = isset($message['content']) ? (string) $message['content'] : null;
        $toolCalls = [];

        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $rawArgs = $tc['function']['arguments'] ?? '{}';
                $args    = is_string($rawArgs)
                    ? (json_decode($rawArgs, true) ?? [])
                    : (array) $rawArgs;

                $toolCalls[] = [
                    'id'        => (string) ($tc['id'] ?? ''),
                    'name'      => (string) ($tc['function']['name'] ?? ''),
                    'arguments' => $args,
                ];
            }
        }

        if ($toolCalls === [] && $content !== null && (
            str_contains($content, '<tool_call>')
            || (str_contains($content, '"name"') && str_contains($content, '"arguments"'))
            || str_contains($content, '"name": "catalog_search"')
            || str_contains($content, '"name":"catalog_search"')
            || str_contains($content, '"name": "fitment_search"')
            || str_contains($content, '"name":"fitment_search"')
            || preg_match('/"name"\s*:\s*"(catalog_search|fitment_search|product_detail|order_tracking|cart_info|b2b_|admin_)/', $content)
        )) {
            $toolCalls = $this->parseNativeHermesToolCalls($content);
            if ($toolCalls !== []) {
                $content = trim((string) preg_replace('/<tool_call>.*?<\/tool_call>/s', '', $content));
                $content = trim((string) preg_replace('/<tool_call>\{.*?\}(?:<\/tool_call>)?/s', '', $content));
                $content = trim((string) preg_replace('/\{\s*"name"\s*:\s*"[^"]+"\s*,\s*"arguments"\s*:\s*\{.*?\}\s*\}/s', '', $content));
                if ($content === '') {
                    $content = null;
                }
            }
        }

        $usage       = $response['usage'] ?? [];
        $totalTokens = (int) ($usage['total_tokens'] ?? 0);
        $model       = (string) ($response['model'] ?? $this->resolveModel());

        return new LlmResponse($content, $toolCalls, $model, $totalTokens);
    }

    /**
     * @return array<int, array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function parseNativeHermesToolCalls(string $content): array
    {
        $toolCalls = [];
        $chunks = [];

        if (preg_match_all('/<tool_call>\s*(\{[\s\S]*?\})\s*(?:<\/tool_call>)?/u', $content, $matches)) {
            foreach ($matches[1] as $raw) {
                $extracted = $this->extractJsonObject($raw);
                if ($extracted !== null) {
                    $chunks[] = $extracted;
                }
            }
        }

        if ($chunks === []) {
            $pos = 0;
            while (($start = strpos($content, '{', $pos)) !== false) {
                $extracted = $this->extractJsonObject(substr($content, $start));
                if ($extracted === null) {
                    $pos = $start + 1;
                    continue;
                }
                $data = json_decode($extracted, true);
                if (is_array($data) && !empty($data['name']) && array_key_exists('arguments', $data)) {
                    $chunks[] = $extracted;
                }
                $pos = $start + strlen($extracted);
            }
        }

        foreach ($chunks as $idx => $raw) {
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['name'])) {
                continue;
            }
            $toolCalls[] = [
                'id'        => 'hermes_tc_' . $idx,
                'name'      => (string) $data['name'],
                'arguments' => (array) ($data['arguments'] ?? []),
            ];
        }

        return $toolCalls;
    }

    private function extractJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }
}
