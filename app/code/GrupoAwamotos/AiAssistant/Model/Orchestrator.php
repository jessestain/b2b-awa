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

    /** Domínios cujos links podem aparecer na resposta final (A4). */
    private const URL_ALLOWLIST = ['awamotos.com', 'wa.me'];

    private PiiRedactor $piiRedactor;

    private const SYSTEM_PROMPTS = [
        self::CHANNEL_STOREFRONT => <<<'PROMPT'
Você é a assistente virtual da AWA Motos, uma distribuidora de peças para motos em Araraquara, SP, Brasil.
Você é simpática, objetiva e especialista em peças de moto.
Ajude o cliente a encontrar produtos no catálogo, conferir compatibilidade com a moto, rastrear pedidos e tirar dúvidas gerais.
Para procedimentos da loja (cadastro, pagamento, entrega, troca, B2B), chame knowledge_base_search antes de responder e cite a URL do tópico.
Se a pessoa perguntar por que não vê preço, não consegue comprar ou onde está o botão de compra, chame b2b_price_table_status e explique o resultado em linguagem simples. Se perguntar o que falta no cadastro B2B ou quanto tempo demora a aprovação, chame b2b_pending_requirements. Nunca prometa prazo nem diga que vai liberar preço: quem decide é a equipe comercial; ofereça o contato que a ferramenta devolver.
Sempre responda em português brasileiro.
Não invente preços, prazos, estoque, SKU, URL ou compatibilidade — use somente dados das ferramentas ou do bloco CATÁLOGO JÁ CONSULTADO. Cite apenas URLs devolvidas pelas ferramentas; links inventados são removidos automaticamente.
Quando o bloco CATÁLOGO JÁ CONSULTADO estiver presente, liste só esses itens (até 5). Não chame catalog_search nem fitment_search de novo. Não invente produto fora da lista.
Se products estiver vazio, diga que não achou e ofereça WhatsApp (16) 99736-7588. Nunca invente produto, SKU ou link.
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
Se o cliente aprovado não vê preço ou não consegue comprar, chame b2b_price_table_status; se perguntar o que falta no cadastro, chame b2b_pending_requirements. Explique em linguagem simples, sem prometer prazo: liberar tabela de preços é decisão da equipe comercial.
Quando o bloco CATÁLOGO JÁ CONSULTADO estiver presente, use SOMENTE esses produtos. Não invente SKU, preço ou URL.
Você NUNCA aprova ou reprova crédito — apenas informa o status calculado pelo sistema.
Decisões de aprovação são sempre da equipe comercial humana.
Responda de forma profissional e objetiva em português brasileiro.
Não invente preços, prazos, compatibilidade, SKU ou URL. Cite apenas URLs devolvidas pelas ferramentas; links inventados são removidos automaticamente.
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
        if ($channel === self::CHANNEL_ADMIN) {
            $systemPrompt .= $this->buildAdminScopeNote($context);
        }
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
        $turnStartedAt = microtime(true);

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
                latencyMs: $this->elapsedMs($turnStartedAt),
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
                    latencyMs: $this->elapsedMs($turnStartedAt),
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
            // P0: degradação graciosa — sem LLM, o guia roteirizado (100% PHP)
            // continua funcionando; o cliente recebe os chips do guia + WhatsApp.
            $reply       = 'No momento meu modo inteligente está temporariamente indisponível. '
                . 'Sem problema: toque numa opção abaixo que eu te guio na busca da peça, '
                . 'ou fale com a gente no WhatsApp (16) 99736-7588.';
            $status      = 'llm_error';
            $errorDetail = 'llm_exception';
            $guideState  = $slotGuide->start();
            $this->log->error('[AiAssistant] LlmException: ' . $this->piiRedactor->redact($e->getMessage()));
        } catch (\Exception $e) {
            $reply       = 'Ocorreu um erro inesperado. Toque numa opção abaixo para eu te guiar na busca, '
                . 'ou fale com a gente no WhatsApp (16) 99736-7588.';
            $status      = 'error';
            $errorDetail = $e::class;
            $guideState  = $slotGuide->start();
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

        // A4: allowlist de URLs em TODA resposta (com ou sem cards) — links
        // alucinados são substituídos pelo handoff humano.
        $reply = $this->sanitizeUrls($reply);

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
            latencyMs: $this->elapsedMs($turnStartedAt),
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
        $skuMode = !empty($intent['sku']);

        if ($skuMode) {
            // SKU detectado: product_detail (match exato). Sem fallback fuzzy —
            // se não existir, o prompt aterrado manda dizer "não encontrei".
            $tool = 'product_detail';
            $result = $this->executeTool('product_detail', [
                'sku' => (string) $intent['sku'],
            ], $context, $channelTools);
            if (is_array($result) && !empty($result['sku']) && !isset($result['products'])) {
                $result = ['products' => [$result], 'total' => 1, 'strategy' => 'sku_exact'];
            } elseif (is_array($result) && isset($result['error'])) {
                $result = [
                    'products' => [],
                    'total' => 0,
                    'strategy' => 'sku_exact',
                    'note' => (string) $result['error'],
                ];
            }
        } elseif (!empty($intent['prefers_fitment'])) {
            $tool = 'fitment_search';
            $result = $this->executeTool('fitment_search', [
                'brand' => (string) ($intent['brand'] ?? ''),
                'model' => (string) ($intent['model'] ?? ''),
                'year'  => (string) ($intent['year'] ?? ''),
                'part'  => (string) ($intent['part'] ?? ''),
            ], $context, $channelTools);
        }

        if (!$skuMode && $this->productCount($result) === 0) {
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
        // product_detail devolve in_stock/qty fora da apresentação dos cards;
        // injetamos no prompt aterrado para o modelo poder responder estoque real.
        $stockSentence = '';
        if ($tool === 'product_detail' && !empty($result['products'][0]) && is_array($result['products'][0])) {
            $first = $result['products'][0];
            if (array_key_exists('in_stock', $first)) {
                $stockSentence = ' Estoque real deste item: ' . (!empty($first['in_stock']) ? 'em estoque' : 'sem estoque')
                    . (isset($first['qty']) ? ' (' . (int) $first['qty'] . ' un).' : '.');
            }
        }
        $priceRule = $hasPrice
            ? 'Pode citar só o preço que estiver no JSON.'
            : 'Não cite preço (visitante / sem price_formatted).';
        $lead = $count > 0
            ? "Há {$count} produto(s) real(is) no JSON abaixo. É PROIBIDO dizer que não encontrou. "
                . 'Fale só desses itens, sem inventar SKU, preço ou URL. '
                . 'Não use markdown. Não despeje lista de SKU/preço/URL: o card já mostra. '
                . $stockSentence
                . $priceRule
            : 'products está vazio. Diga que não encontrou e ofereça WhatsApp (16) 99736-7588. Não invente produto.';

        return "CATÁLOGO JÁ CONSULTADO pelo Magento (fonte única de SKU/URL/preço).\n"
            . $lead . "\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nNão chame catalog_search, fitment_search nem product_detail nesta resposta (dados já estão no JSON).";
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
            if (!empty($context['is_b2b']) && array_key_exists('in_stock', $product)) {
                $inStock = (bool) $product['in_stock'];
                $qty = isset($product['qty']) ? (int) $product['qty'] : null;
                $row['in_stock'] = $inStock;
                $row['qty'] = $qty;
                $row['stock_label'] = $inStock
                    ? (($qty !== null && $qty > 0) ? $qty . ' un.' : 'Em estoque')
                    : 'Sem estoque';
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
     * P0: latência do turno em ms (observabilidade por conversa).
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Quem está falando e até onde enxerga. As tools aplicam o escopo de
     * verdade (PortfolioScope); aqui só evitamos que o modelo prometa o que
     * não pode entregar.
     *
     * @param array<string, mixed> $context
     */
    private function buildAdminScopeNote(array $context): string
    {
        $role = trim((string) ($context['admin_role'] ?? ''));
        $name = trim((string) ($context['attendant_name'] ?? ''));
        $note = "\nUsuário atual: " . ($role !== '' ? 'papel "' . $role . '"' : 'usuário do painel');
        if ($name !== '') {
            $note .= ', atendente "' . $name . '"';
        }
        $note .= !empty($context['portfolio_restricted'])
            ? '. Escopo: somente a própria carteira de clientes — pedidos, scores e vendas de clientes '
                . 'fora da carteira não estão disponíveis; se pedirem, explique o limite sem revelar dados.'
            : '. Escopo: sem restrição de carteira.';
        return $note;
    }

    /**
     * @param ToolInterface[] $channelTools
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    private function buildToolSchemas(array $channelTools): array
    {
        $schemas = [];
        foreach ($channelTools as $tool) {
            $parameters = $tool->getParametersSchema();
            /* JSON Schema exige que `properties` seja objeto. Em PHP, um array
             * vazio serializa como `[]` e todo provedor com tools nativas
             * (Anthropic, OpenAI, Google) rejeita com 400
             * "properties: Input should be an object". O Hermes, por usar
             * prompt XML, nunca expôs o problema. */
            if (isset($parameters['properties']) && $parameters['properties'] === []) {
                $parameters['properties'] = new \stdClass();
            }
            $schemas[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters'  => $parameters,
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
     * Pós-validação de URL (A4): só sobrevivem links de domínios da allowlist
     * (awamotos.com e wa.me). Qualquer outro link — alucinado pelo modelo — é
     * substituído pelo handoff humano (WhatsApp). URLs vindas das tools já são
     * reais e passam sem mudança.
     */
    private function sanitizeUrls(string $reply): string
    {
        if ($reply === '') {
            return $reply;
        }
        $handoff = trim((string) $this->config->getHumanHandoffUrl());
        if ($handoff === '') {
            $handoff = 'https://wa.me/5516997367588';
        }
        $pattern = '~((?:https?://)[^\s<>"\']+|(?:www\.)[\w.-]+(?:/[^\s<>"\']*)?|'
            . '(?<![\w@./-])[\w-]+\.(?:com|com\.br|net|org|io|store)(?:/[^\s<>"\']*)?)~iu';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $match) use ($handoff): string {
                $url = $match[0];
                $host = (string) (parse_url(
                    'http://' . preg_replace('~^https?://~i', '', $url),
                    PHP_URL_HOST
                ) ?? '');
                foreach (self::URL_ALLOWLIST as $allowed) {
                    if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                        return $url;
                    }
                }

                return $handoff;
            },
            $reply
        );
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
