<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\HelpCenter\Api\Data\CategoryInterface;
use GrupoAwamotos\HelpCenter\Api\TopicRepositoryInterface;
use Magento\Framework\UrlInterface;

/**
 * Permite ao assistente buscar tópicos na Central de Ajuda AWA.
 *
 * - Somente tópicos públicos (audience = all, customer ou b2b) são expostos no
 *   storefront/B2B; tópicos de seller/supervisor/admin nunca chegam ao usuário final.
 * - O conteúdo retornado é saneado (strip_tags) antes de enviado ao LLM.
 */
class KnowledgeBaseSearchTool implements ToolInterface
{
    private const MAX_RESULTS = 4;
    private const SELLER_AUDIENCES = [
        CategoryInterface::AUDIENCE_SELLER,
        CategoryInterface::AUDIENCE_SUPERVISOR,
        CategoryInterface::AUDIENCE_ADMIN,
    ];

    public function __construct(
        private readonly TopicRepositoryInterface $topicRepository,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    public function getName(): string
    {
        return 'knowledge_base_search';
    }

    public function getDescription(): string
    {
        return 'Busca na Central de Ajuda da AWA Motos informações sobre processos, políticas e procedimentos. '
            . 'Use quando o usuário fizer perguntas sobre como comprar, pagamento, troca, frete, cadastro B2B, '
            . 'compatibilidade de peças, garantia ou procedimentos gerais da loja. '
            . 'Prefira esta ferramenta antes de inventar respostas procedimentais.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Termos de busca relacionados à dúvida do usuário (ex.: "como rastrear pedido", "troca devolução", "cadastro B2B").',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['topics' => [], 'message' => 'Termo de busca vazio.'];
        }

        $audiences = $this->resolveAudiences($context);
        $topics    = $this->topicRepository->search($query, $audiences, self::MAX_RESULTS);

        if (empty($topics)) {
            return ['topics' => [], 'message' => 'Nenhum tópico encontrado para: ' . $query];
        }

        $results = [];
        foreach ($topics as $topic) {
            if ($this->isInternalTopic($topic->getAudience(), $context)) {
                continue;
            }
            $results[] = [
                'title'   => $topic->getTitle(),
                'summary' => $topic->getSummary() ?? '',
                'content' => $this->sanitizeContent((string) $topic->getContent()),
                'url'     => $this->buildTopicUrl((int) $topic->getTopicId()),
            ];
        }

        if (empty($results)) {
            return ['topics' => [], 'message' => 'Nenhum tópico autorizado encontrado.'];
        }

        return ['topics' => $results];
    }

    public function getAllowedChannels(): array
    {
        return [
            AssistantOrchestratorInterface::CHANNEL_STOREFRONT,
            AssistantOrchestratorInterface::CHANNEL_B2B,
            AssistantOrchestratorInterface::CHANNEL_ADMIN,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return string[]
     */
    private function resolveAudiences(array $context): array
    {
        $audiences = [CategoryInterface::AUDIENCE_ALL];
        $customerId = (int) ($context['customer_id'] ?? $context['customerId'] ?? 0);
        if ($customerId > 0) {
            $audiences[] = CategoryInterface::AUDIENCE_CUSTOMER;
        }

        $isB2B = (bool) ($context['is_b2b'] ?? $context['isB2B'] ?? false);
        if ($isB2B) {
            $audiences[] = CategoryInterface::AUDIENCE_B2B;
        }

        /* Canal admin: a equipe interna enxerga os tópicos seller/supervisor/
         * admin do Help Center (11 tópicos já cadastrados e nunca expostos). */
        if (!empty($context['is_admin']) || !empty($context['isAdmin'])) {
            $audiences[] = CategoryInterface::AUDIENCE_SELLER;
            $audiences[] = CategoryInterface::AUDIENCE_SUPERVISOR;
            $audiences[] = CategoryInterface::AUDIENCE_ADMIN;
        }

        return array_values(array_unique($audiences));
    }

    /**
     * Prevent internal seller/admin topics from reaching end users.
     *
     * @param array<string, mixed> $context
     */
    private function isInternalTopic(string $audience, array $context): bool
    {
        if (!in_array($audience, self::SELLER_AUDIENCES, true)) {
            return false;
        }
        return empty($context['is_admin']) && empty($context['isAdmin']);
    }

    private function sanitizeContent(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
        return mb_substr(trim($text), 0, 1200);
    }

    private function buildTopicUrl(int $topicId): string
    {
        return $this->urlBuilder->getUrl('ajuda', ['_fragment' => 'topico-' . $topicId]);
    }
}
