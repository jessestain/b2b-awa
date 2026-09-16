<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\LlmClientInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Exception\LlmException;
use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\Guide\SlotGuide;
use GrupoAwamotos\AiAssistant\Model\Query\ProductQueryParser;
use Psr\Log\LoggerInterface;

/**
 * Core AI orchestrator.
 *
 * Catalog facts come from Magento tools first. Hermes only writes language
 * on top of that JSON — it is not allowed to invent SKU, price or URL.
 *
 * Non-catalog turns still use the tool-calling loop (orders, cart, admin).
 */
class Orchestrator implements AssistantOrchestratorInterface
{
    private const MAX_TOOL_ROUNDS = 3;

    private const CATALOG_TOOLS = ['catalog_search', 'fitment_search'];

    private PiiRedactor $piiRedactor;

    private const SYSTEM_PROMPTS = [
        self::CHANNEL_STOREFRONT => <<<'PROMPT'
Você é a assistente virtual da AWA Motos, uma distribuidora de peças para motos em Araraquara, SP, Brasil.
Você é simpática, objetiva e especialista em peças de moto.
Ajude o cliente a encontrar produtos no catálogo, conferir compatibilidade com a moto, rastrear pedidos e tirar dúvidas gerais.
Para procedimentos da loja (cadastro, pagamento, entrega, troca, B2B), chame knowledge_base_search antes de responder e cite a URL do tópico.
Sempre responda em português brasileiro.
Não invente preços, prazos, estoque, SKU, URL ou compatibilidade — use somente dados das ferramentas ou do bloco CATÁLOGO JÁ CONSULTADO.
Quando o bloco CATÁLOGO JÁ CONSULTADO estiver presente, liste só esses itens (até 5). Não chame catalog_search nem fitment_search de novo. Não invente produto fora da lista.
Se products estiver vazio, diga que não achou e ofereça WhatsApp (16) 3301-1890. Nunca invente produto, SKU ou link.
Se strategy for catalog_fallback ou catalog_match, avise que a compatibilidade precisa ser conferida na ficha do produto.
Não envie brand Yamaha para Biz, CG, Titan, Bros, Fan, Pop, XRE ou CB — esses modelos são Honda, salvo o cliente dizer outra marca.
Fazer, Factor e YBR são Yamaha. Ninja é Kawasaki.
Nunca peça senha, dados de cartão ou informações sensíveis.
Resposta curta e direta (máximo 2 frases + um convite). Não use markdown de link ([texto](url)). Não liste SKU, preço ou URL em bullets — o card já mostra isso. Se o JSON não tiver price_formatted, não cite preço.
PROMPT,
        self::CHANNEL_B2B => <<<'PROMPT'
Você é a assistente comercial B2B da AWA Motos.
Você atende revendedores e empresas com CNPJ cadastrado na plataforma.
Auxilie com: busca de produtos, compatibilidade peça × moto, cotações, reposição de pedidos anteriores e consulta de status de cadastro CNPJ.
Para políticas e procedimentos (cadastro CNPJ, crédito, pedidos, entrega), chame knowledge_base_search e cite a URL do tópico.
Quando o bloco CATÁLOGO JÁ CONSULTADO estiver presente, use SOMENTE esses produtos. Não invente SKU, preço ou URL.
Você NUNCA aprova ou reprova crédito — apenas informa o status calculado pelo sistema.
Decisões de aprovação são sempre da equipe comercial humana.
Responda de forma profissional e objetiva em português brasileiro.
Não invente preços, prazos, compatibilidade, SKU ou URL.
PROMPT,
        self::CHANNEL_ADMIN => <<<'PROMPT'
Você é o copiloto interno da equipe AWA Motos no painel administrativo.
Você acessa dados reais do sistema (vendas, estoque, pedidos, clientes) para apoiar decisões.
Responda de forma objetiva, com dados precisos.
Você é SOMENTE LEITURA — não executa ações de escrita, aprovações ou mudanças no sistema.
Se pedirem uma ação de escrita, explique que isso deve ser feito diretamente no painel.
Responda sempre em português.
PROMPT,
    ];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        private readonly LlmClientInterface  $llmClient,
        private readonly ConversationLogger  $logger,
        private readonly Config              $config,
        private readonly LoggerInterface     $log,
        private array $tools = [],
        ?PiiRedactor $piiRedactor = null
    ) {
        $this->piiRedactor = $piiRedactor ?? new PiiRedactor();
    }

    public function handle(
        string $channel,
        string $sessionId,
        string $userMessage,
        array $history = [],
        array $context = []
    ): array {
        $context['channel'] = $channel;
        $systemPrompt  = self::SYSTEM_PROMPTS[$channel] ?? self::SYSTEM_PROMPTS[self::CHANNEL_STOREFRONT];
        $channelTools  = $this->getToolsForChannel($channel);
        $toolSchemas   = $this->buildToolSchemas($channelTools);
        $parser        = new ProductQueryParser();
        $intent        = $parser->parse($userMessage);
        $safeHistory   = $this->redactHistory($history);
        $safeMessage   = $this->piiRedactor->redact($userMessage);
        $slotGuide     = new SlotGuide();
        $isB2b         = !empty($context['is_b2b']);
        $guideState    = $slotGuide->sanitize(is_array($context['guide'] ?? null) ? $context['guide'] : []);
        $guideSkipLlm  = false;

        if ($this->piiRedactor->containsSecret($userMessage)) {
            $reply = 'Por segurança, não envie senha, dados de cartão ou tokens neste chat. '
                . 'Fale com um atendente pelo WhatsApp se precisar de ajuda com a conta.';
            $this->logger->log(
                sessionId: $sessionId,
                channel: $channel,
                userMessage: $safeMessage,
                assistantResponse: $reply,
                toolsCalled: [],
                model: 'none',
                tokensUsed: 0,
                status: 'blocked',
                errorDetail: 'secret_payload',
                customerId: isset($context['customer_id']) ? (int) $context['customer_id'] : null,
                ipAddress: $context['ip_address'] ?? null
            );

            return [
                'reply' => $reply,
                'history' => array_merge($safeHistory, [
                    ['role' => 'user', 'content' => $safeMessage],
                    ['role' => 'assistant', 'content' => $reply],
                ]),
                'products' => [],
                'deferred_write' => null,
                'guide' => $slotGuide->toClient($slotGuide->idle(), $isB2b),
            ];
        }

        if ($guideState['active']) {
            $advanced = $slotGuide->advance($guideState, $safeMessage, $intent);
            if ($advanced['exit']) {
                $guideState = $slotGuide->idle();
            } elseif (!$advanced['ready']) {
                $reply = $advanced['reply'];
                $guideState = $advanced['state'];
                $this->logger->log(
                    sessionId: $sessionId,
                    channel: $channel,
                    userMessage: $safeMessage,
                    assistantResponse: $reply,
                    toolsCalled: [],
                    model: 'guide',
                    tokensUsed: 0,
                    status: 'ok',
                    errorDetail: null,
                    customerId: isset($context['customer_id']) ? (int) $context['customer_id'] : null,
                    ipAddress: $context['ip_address'] ?? null
                );
                $maxHistory = $this->config->getMaxHistoryMessages();
                $newHistory = $history;
                $newHistory[] = ['role' => 'user', 'content' => $userMessage];
                $newHistory[] = ['role' => 'assistant', 'content' => $reply];
                if (count($newHistory) > $maxHistory * 2) {
                    $newHistory = array_slice($newHistory, -($maxHistory * 2));
                }

                return [
                    'reply' => $reply,
                    'history' => $newHistory,
                    'products' => [],
                    'deferred_write' => null,
                    'guide' => $slotGuide->toClient($guideState, $isB2b),
                ];
            } else {
                $guideState = $advanced['state'];
                $intent = $slotGuide->intentFromState($guideState, $intent);
                $guideSkipLlm = true;
            }
        }

        $messages      = $this->buildMessages($systemPrompt, $safeHistory, $safeMessage);

        $reply         = '';
        $toolsCalled   = [];
        $productsOut   = [];
        $totalTokens   = 0;
        $modelUsed     = $this->config->getOpenRouterModel() ?: $this->config->getModel();
        $status        = 'ok';
        $errorDetail   = null;
        $forcedSearchRetry = false;
        $catalogPrefetched = false;
        $deferredWrite = null;

        $isHelpCenterQuestion = $this->isHelpCenterQuestion($safeMessage);
        $prefetch = $isHelpCenterQuestion
            ? ['ran' => false, 'tool' => '', 'products' => [], 'result' => []]
            : $this->prefetchCatalog($intent, $channelTools, $context);
        if ($prefetch['ran']) {
            $catalogPrefetched = true;
            $toolsCalled[] = $prefetch['tool'];
            $productsOut = $this->presentProducts($prefetch['products'], $channel, $context);
            $messages[0]['content'] .= "\n\n" . $this->buildGroundedCatalogPrompt(
                $prefetch['tool'],
                $prefetch['result'],
                $productsOut
            );
            $toolSchemas = $this->withoutCatalogTools($toolSchemas);
        }

        try {
            if ($guideSkipLlm && $catalogPrefetched) {
                $guideState['step'] = SlotGuide::STEP_RESULTS;
                $reply = $productsOut !== []
                    ? $slotGuide->composeFoundReply($guideState, $productsOut, $isB2b)
                    : $slotGuide->composeMissReply($guideState);
                $modelUsed = 'guide+catalog';
                $totalTokens = 0;
            } else {
                for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                    $response     = $this->llmClient->chat($messages, $toolSchemas);
                    $totalTokens += $response->getTotalTokens();
                    $modelUsed    = $response->getModel();

                    if (!$response->hasToolCalls()) {
                        $reply = (string) $response->getContent();
                        if (!$forcedSearchRetry
                        && !$catalogPrefetched
                        && !$isHelpCenterQuestion
                        && $round < self::MAX_TOOL_ROUNDS - 1
                        && $this->shouldForceCatalogTool($intent, $reply, $productsOut, $toolsCalled)
                        ) {
                            $forcedSearchRetry = true;
                            $messages[] = ['role' => 'assistant', 'content' => $reply];
                            $messages[] = [
                            'role' => 'user',
                            'content' => 'Essa resposta inventou catálogo. Ignore-a. '
                                . 'Chame fitment_search (se houver moto) ou catalog_search agora. '
                                . 'Responda somente com o tool_call, sem texto e sem inventar SKU.',
                            ];
                            $reply = '';
                            continue;
                        }
                        break;
                    }

                    $messages[] = [
                    'role'       => 'assistant',
                    'content'    => $response->getContent() ?? '',
                    'tool_calls' => array_map(fn($tc) => [
                        'id'       => $tc['id'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tc['name'],
                            'arguments' => json_encode($tc['arguments']),
                        ],
                    ], $response->getToolCalls()),
                    ];

                    foreach ($response->getToolCalls() as $tc) {
                        $toolName  = $tc['name'];
                        $toolsCalled[] = $toolName;
                        $toolResult = $this->executeTool($toolName, $tc['arguments'], $context, $channelTools);

                        if (in_array($toolName, self::CATALOG_TOOLS, true)
                        && is_array($toolResult)
                        && !empty($toolResult['products'])
                        ) {
                            $productsOut = $this->presentProducts(
                                array_slice($toolResult['products'], 0, 5),
                                $channel,
                                $context
                            );
                        }

                        $llmToolResult = $toolResult;
                        if (!empty($toolResult['deferred_write'])) {
                            $deferredWrite = [
                            'tool'    => $toolName,
                            'action'  => (string) ($toolResult['action'] ?? ''),
                            'payload' => is_array($toolResult['payload'] ?? null) ? $toolResult['payload'] : [],
                            'summary' => (string) ($toolResult['summary'] ?? ''),
                            ];
                            $llmToolResult = [
                            'status'  => 'pending_confirmation',
                            'message' => 'Aguardando o cliente confirmar no botão da interface. '
                                . 'Não chame esta ferramenta de escrita novamente neste turno.',
                            ];
                        }

                        $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $tc['id'],
                        'name'         => $toolName,
                        'content'      => json_encode($llmToolResult, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                }

                if ($reply === '') {
                    $reply = 'Desculpe, não consegui processar sua solicitação. Tente novamente ou fale com nosso atendimento.';
                }

                $reply = $this->reconcileReplyWithProducts($reply, $productsOut);
            }
        } catch (LlmException $e) {
            $reply       = 'No momento o assistente está temporariamente indisponível. Por favor, use nosso chat ao vivo ou entre em contato pelo WhatsApp.';
            $status      = 'llm_error';
            $errorDetail = 'llm_exception';
            $this->log->error('[AiAssistant] LlmException: ' . $this->piiRedactor->redact($e->getMessage()));
        } catch (\Exception $e) {
            $reply       = 'Ocorreu um erro inesperado. Por favor, tente novamente.';
            $status      = 'error';
            $errorDetail = $e::class;
            $this->log->error('[AiAssistant] Unexpected error: ' . $this->piiRedactor->redact($e->getMessage()));
        }

        if ($productsOut !== []) {
            if ($guideState['active']) {
                $guideState['step'] = SlotGuide::STEP_RESULTS;
                $reply = $slotGuide->composeFoundReply($guideState, $productsOut, $isB2b);
            } elseif ($channel === self::CHANNEL_STOREFRONT) {
                $reply = $this->composeFoundReply($productsOut);
            } else {
                $reply = $this->reconcileReplyWithProducts($reply, $productsOut);
                if (preg_match('/temporariamente indisponível|erro inesperado|não consegui processar/iu', $reply) === 1) {
                    $reply = $this->composeFoundReply($productsOut);
                }
            }
            $hidePrices = $channel === self::CHANNEL_STOREFRONT && empty($context['is_b2b']);
            $reply = $this->stripUrlsWhenCardsPresent($reply, $hidePrices);
        }

        $this->logger->log(
            sessionId: $sessionId,
            channel: $channel,
            userMessage: $safeMessage,
            assistantResponse: $reply,
            toolsCalled: $toolsCalled,
            model: $modelUsed,
            tokensUsed: $totalTokens,
            status: $status,
            errorDetail: $errorDetail,
            customerId: isset($context['customer_id']) ? (int) $context['customer_id'] : null,
            ipAddress: $context['ip_address'] ?? null
        );

        $maxHistory  = $this->config->getMaxHistoryMessages();
        $newHistory  = $history;
        $newHistory[] = ['role' => 'user', 'content' => $userMessage];
        $newHistory[] = ['role' => 'assistant', 'content' => $reply];

        if (count($newHistory) > $maxHistory * 2) {
            $newHistory = array_slice($newHistory, -($maxHistory * 2));
        }

        return [
            'reply' => $reply,
            'history' => $newHistory,
            'products' => $productsOut,
            'deferred_write' => $deferredWrite,
            'guide' => $slotGuide->toClient($guideState, $isB2b),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array{reply: string, history: array<int, array{role: string, content: string}>, products: array}
     */
    public function executeConfirmedWrite(string $toolName, array $arguments, array $context): array
    {
        $channel = AssistantOrchestratorInterface::CHANNEL_B2B;
        $context['write_confirmed'] = true;
        $context['is_b2b'] = true;
        $channelTools = $this->getToolsForChannel($channel);
        $toolResult = $this->executeTool($toolName, $arguments, $context, $channelTools);

        $reply = (string) ($toolResult['message'] ?? $toolResult['error'] ?? 'Ação concluída.');
        if (!empty($toolResult['error'])) {
            $reply = (string) $toolResult['error'];
        } elseif (isset($toolResult['checkout_url']) || isset($toolResult['quote_id']) || isset($toolResult['success'])) {
            $reply = 'Pronto. A ação foi concluída com a sua confirmação.';
        }

        $this->logger->log(
            sessionId: hash('sha256', 'confirmed-write-' . ($context['customer_id'] ?? '0')),
            channel: $channel,
            userMessage: '[confirm] ' . $toolName,
            assistantResponse: $reply,
            toolsCalled: [$toolName],
            model: 'none',
            tokensUsed: 0,
            status: empty($toolResult['error']) ? 'ok' : 'error',
            errorDetail: isset($toolResult['error']) ? (string) $toolResult['error'] : null,
            customerId: isset($context['customer_id']) ? (int) $context['customer_id'] : null,
            ipAddress: $context['ip_address'] ?? null
        );

        return [
            'reply' => $reply,
            'history' => [
                ['role' => 'assistant', 'content' => $reply],
            ],
            'products' => [],
        ];
    }

    private function isHelpCenterQuestion(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'central de ajuda');
    }

    /**
     * @param array<string, mixed> $intent
     * @param ToolInterface[] $channelTools
     * @param array<string, mixed> $context
     * @return array{ran: bool, tool: string, products: array<int, array<string, mixed>>, result: array<string, mixed>}
     */
    private function prefetchCatalog(array $intent, array $channelTools, array $context): array
    {
        $empty = ['ran' => false, 'tool' => '', 'products' => [], 'result' => []];
        if (empty($intent['is_product_query'])) {
            return $empty;
        }

        $result = [];
        $tool = '';

        if (!empty($intent['prefers_fitment'])) {
            $tool = 'fitment_search';
            $result = $this->executeTool('fitment_search', [
                'brand' => (string) ($intent['brand'] ?? ''),
                'model' => (string) ($intent['model'] ?? ''),
                'year'  => (string) ($intent['year'] ?? ''),
                'part'  => (string) ($intent['part'] ?? ''),
            ], $context, $channelTools);
        }

        if ($this->productCount($result) === 0) {
            $tool = 'catalog_search';
            $query = trim((string) ($intent['search_query'] ?? ''));
            $result = $this->executeTool('catalog_search', [
                'query' => $query !== '' ? $query : (string) ($intent['part'] ?? ''),
            ], $context, $channelTools);
        }

        $products = [];
        if (is_array($result) && !empty($result['products']) && is_array($result['products'])) {
            $products = array_slice($result['products'], 0, 5);
        }

        return [
            'ran' => true,
            'tool' => $tool,
            'products' => $products,
            'result' => is_array($result) ? $result : [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, array<string, mixed>> $products
     */
    private function buildGroundedCatalogPrompt(string $tool, array $result, array $products): string
    {
        $payload = [
            'tool' => $tool,
            'strategy' => (string) ($result['strategy'] ?? ''),
            'note' => (string) ($result['note'] ?? ''),
            'total' => (int) ($result['total'] ?? count($products)),
            'products' => $products,
        ];

        $count = count($products);
        $hasPrice = false;
        foreach ($products as $product) {
            if (!empty($product['price_formatted'])) {
                $hasPrice = true;
                break;
            }
        }
        $priceRule = $hasPrice
            ? 'Pode citar só o preço que estiver no JSON.'
            : 'Não cite preço (visitante / sem price_formatted).';
        $lead = $count > 0
            ? "Há {$count} produto(s) real(is) no JSON abaixo. É PROIBIDO dizer que não encontrou. "
                . 'Fale só desses itens, sem inventar SKU, preço ou URL. '
                . 'Não use markdown. Não despeje lista de SKU/preço/URL: o card já mostra. '
                . $priceRule
            : 'products está vazio. Diga que não encontrou e ofereça WhatsApp (16) 3301-1890. Não invente produto.';

        return "CATÁLOGO JÁ CONSULTADO pelo Magento (fonte única de SKU/URL/preço).\n"
            . $lead . "\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nNão chame catalog_search nem fitment_search nesta resposta.";
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function presentProducts(array $products, string $channel, array $context): array
    {
        $hidePrices = $channel === self::CHANNEL_STOREFRONT && empty($context['is_b2b']);
        $out = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $row = [
                'sku' => (string) ($product['sku'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'url' => (string) ($product['url'] ?? ''),
                'fitment' => (string) ($product['fitment'] ?? ''),
                'image_url' => (string) ($product['image_url'] ?? ''),
            ];
            if (!$hidePrices) {
                $row['price'] = $product['price'] ?? null;
                $row['price_formatted'] = $product['price_formatted'] ?? null;
            }
            if ($row['name'] === '' && $row['sku'] === '') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array{type: string, function: array<string, mixed>}> $schemas
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    private function withoutCatalogTools(array $schemas): array
    {
        $filtered = [];
        foreach ($schemas as $schema) {
            $name = (string) ($schema['function']['name'] ?? '');
            if (in_array($name, self::CATALOG_TOOLS, true)) {
                continue;
            }
            $filtered[] = $schema;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function productCount(array $result): int
    {
        return count($result['products'] ?? []);
    }

    /**
     * @param ToolInterface[] $channelTools
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    private function buildToolSchemas(array $channelTools): array
    {
        $schemas = [];
        foreach ($channelTools as $tool) {
            $schemas[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters'  => $tool->getParametersSchema(),
                ],
            ];
        }
        return $schemas;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function redactHistory(array $history): array
    {
        $out = [];
        foreach ($history as $turn) {
            $out[] = [
                'role' => (string) ($turn['role'] ?? 'user'),
                'content' => $this->piiRedactor->redact((string) ($turn['content'] ?? '')),
            ];
        }

        return $out;
    }

    private function buildMessages(string $systemPrompt, array $history, string $userMessage): array
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $turn) {
            if (in_array($turn['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];
        return $messages;
    }

    /**
     * @return ToolInterface[]
     */
    private function getToolsForChannel(string $channel): array
    {
        $result = [];
        foreach ($this->tools as $tool) {
            if (in_array($channel, $tool->getAllowedChannels(), true)) {
                $result[] = $tool;
            }
        }
        return $result;
    }

    /**
     * @param ToolInterface[] $channelTools
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeTool(
        string $toolName,
        array $arguments,
        array $context,
        array $channelTools
    ): array {
        $channel = (string) ($context['channel'] ?? '');
        foreach ($channelTools as $tool) {
            if ($tool->getName() !== $toolName) {
                continue;
            }
            $allowed = $tool->getAllowedChannels();
            if ($channel !== '' && !in_array($channel, $allowed, true)) {
                return ['error' => 'Ferramenta não disponível neste canal.'];
            }
            if ($allowed === [AssistantOrchestratorInterface::CHANNEL_ADMIN]
                && empty($context['is_admin'])
            ) {
                return ['error' => 'Ferramenta não disponível neste canal.'];
            }
            try {
                return $tool->execute($arguments, $context);
            } catch (\Exception $e) {
                $this->log->warning('[AiAssistant] Tool ' . $toolName . ' error: ' . $e->getMessage());
                return ['error' => 'Ferramenta temporariamente indisponível.'];
            }
        }
        return ['error' => 'Ferramenta desconhecida: ' . $toolName];
    }

    /**
     * Force a catalog tool call only when Magento has not already searched
     * and the model answered a product query from its own weights.
     *
     * @param array<string, mixed> $intent
     * @param array<int, array<string, mixed>> $productsOut
     * @param string[] $toolsCalled
     */
    private function shouldForceCatalogTool(
        array $intent,
        string $reply,
        array $productsOut,
        array $toolsCalled
    ): bool {
        if (empty($intent['is_product_query'])) {
            return false;
        }
        if ($productsOut !== []) {
            return false;
        }
        foreach ($toolsCalled as $name) {
            if (in_array($name, self::CATALOG_TOOLS, true)) {
                return false;
            }
        }

        return $reply !== '';
    }

    /**
     * Magento catalog is source of truth: never let the LLM deny items already found.
     *
     * @param array<int, array<string, mixed>> $productsOut
     */
    private function reconcileReplyWithProducts(string $reply, array $productsOut): string
    {
        if ($productsOut === []) {
            return $reply;
        }

        if (preg_match(
            '/não\s+(achei|encontrei|localizei|encontrou|há|ha)|nao\s+(achei|encontrei)|nenhum produto|não encontrei/iu',
            $reply
        ) !== 1) {
            return $reply;
        }

        return $this->composeFoundReply($productsOut);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function composeFoundReply(array $products): string
    {
        $lines = ['Encontrei no catálogo da AWA Motos:'];
        foreach ($products as $product) {
            $name = trim((string) ($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $lines[] = '• ' . $name;
        }
        $lines[] = 'Confira a compatibilidade na ficha do produto. Toque no card para abrir a página.';

        return implode("\n", $lines);
    }

    /**
     * Product cards carry URL, SKU and (when allowed) price. Keep the spoken reply clean.
     */
    private function stripUrlsWhenCardsPresent(string $reply, bool $hidePrices): string
    {
        $cleaned = preg_replace('/\[[^\]]*]\(\s*https?:\/\/[^)]*\)/u', '', $reply) ?? $reply;
        $cleaned = preg_replace('/\[[^\]]*]\(/u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('#https?://[^\s<>"\']+#u', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^\s*(?:[-*•]\s*)?(SKU|Preço|Preco|URL|Link|Nome)\s*:.*$/imu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\b(o link direto é|link direto|url:|link:)\s*[.:]*/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\b(veja|ver|confira)\s+(mais\s+)?detalhes\s+aqui\s*[:.]*/iu', '', $cleaned) ?? $cleaned;
        if ($hidePrices) {
            $cleaned = preg_replace('/\bPreço\s*:\s*R\$\s*[\d.,]+/iu', '', $cleaned) ?? $cleaned;
            $cleaned = preg_replace('/R\$\s*[\d.,]+/u', '', $cleaned) ?? $cleaned;
        }
        $cleaned = preg_replace('/[ \t]+\n/u', "\n", $cleaned) ?? $cleaned;
        $cleaned = preg_replace("/\n{3,}/u", "\n\n", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+([.,;:!?])/u', '$1', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/^\s*[-*•]\s*$/mu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/([.!?])\s+[eE],\s+/u', '$1 ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/:\s*$/u', '.', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
