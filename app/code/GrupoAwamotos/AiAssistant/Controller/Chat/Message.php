<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Controller\Chat;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\RateLimiter;
use GrupoAwamotos\B2B\Model\CustomerApproval;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\Helper\Security;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * POST /aiassistant/chat/message
 *
 * Request JSON body:
 *   { "message": "...", "history": [...], "confirm_token": "..." }
 */
class Message implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const SESSION_PENDING_WRITE = 'awa_ai_pending_write';
    private const MAX_BODY_BYTES = 65536;
    private const MAX_TURN_CHARS = 2000;
    private const WRITE_TTL_SECONDS = 600;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Json $jsonSerializer,
        private readonly Config $config,
        private readonly AssistantOrchestratorInterface $orchestrator,
        private readonly RateLimiter $rateLimiter,
        private readonly CustomerSession $customerSession,
        private readonly CustomerApproval $customerApproval,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isStorefrontEnabled()) {
            return $result->setData(['reply' => null, 'history' => [], 'error' => 'Assistente de IA não disponível.']);
        }

        $ipAddress = (string) $this->request->getServer('REMOTE_ADDR', '0.0.0.0');
        $loggedCustomerId = $this->customerSession->isLoggedIn()
            ? (int) $this->customerSession->getCustomerId()
            : 0;

        if (!$this->rateLimiter->checkAndIncrement(
            $ipAddress,
            $loggedCustomerId > 0 ? $loggedCustomerId : null
        )) {
            /* 429 real: antes respondia 200 com `error` no corpo, o que escondia
             * bloqueios nas métricas e impedia o widget de tratar o caso. */
            $result->setHttpResponseCode(429);
            $result->setHeader('Retry-After', '600', true);
            return $result->setData([
                'reply'   => null,
                'history' => [],
                'error'   => 'Limite de mensagens por hora atingido. Tente novamente mais tarde ou utilize nosso chat ao vivo.',
            ]);
        }

        $rawBody = (string) $this->request->getContent();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $result->setHttpResponseCode(413)->setData([
                'reply' => null,
                'history' => [],
                'error' => 'Requisição inválida.',
            ]);
        }

        try {
            $body = $this->jsonSerializer->unserialize($rawBody);
        } catch (\Exception $e) {
            return $result->setData(['reply' => null, 'history' => [], 'error' => 'Requisição inválida.']);
        }

        if (!is_array($body)) {
            return $result->setData(['reply' => null, 'history' => [], 'error' => 'Requisição inválida.']);
        }

        $confirmToken = trim((string) ($body['confirm_token'] ?? ''));
        if ($confirmToken !== '') {
            return $this->executeConfirmedWrite($result, $confirmToken, $ipAddress);
        }

        $userMessage = trim((string) ($body['message'] ?? ''));
        if ($userMessage === '' || mb_strlen($userMessage) > 1000) {
            return $result->setData(['reply' => null, 'history' => [], 'error' => 'Mensagem inválida.']);
        }

        $history = $this->sanitizeHistory(is_array($body['history'] ?? null) ? (array) $body['history'] : []);

        // RODADA D 14/10/2026 — handoff humano em QUALQUER ponto do fluxo
        // roteirizado. Antes, "quero falar com minha vendedora" no meio do
        // fluxo era ignorado e o script repetia a pergunta ("Qual é a moto?").
        $guideContext = $this->sanitizeGuide($body['guide'] ?? null);
        if (!empty($guideContext['active']) && $this->isHandoffIntent($userMessage)) {
            return $result->setData([
                'reply' => 'Certo! Vou te encaminhar para atendimento humano. Toque no botão abaixo para falar com nossa equipe no WhatsApp.',
                'history' => $history,
                'products' => [],
                'confirmation' => null,
                'guide' => [
                    'active' => false,
                    'step' => 'part',
                    'part' => '',
                    'brand' => '',
                    'model' => '',
                    'year' => '',
                    'skipped_part' => false,
                    'chips' => [
                        [
                            'id' => 'handoff',
                            'caption' => 'Falar no WhatsApp',
                            'value' => '',
                            'action' => 'handoff',
                            'primary' => true,
                        ],
                    ],
                ],
                'error' => null,
            ]);
        }

        $isB2BApproved = false;
        if ($this->customerSession->isLoggedIn()) {
            $customerId    = (int) $this->customerSession->getCustomerId();
            $isB2BApproved = $this->customerApproval->isApproved($customerId);
        }

        $channel = $isB2BApproved
            ? AssistantOrchestratorInterface::CHANNEL_B2B
            : AssistantOrchestratorInterface::CHANNEL_STOREFRONT;

        $context = [
            'ip_address' => $ipAddress,
            'is_b2b'     => $isB2BApproved,
            'channel'    => $channel,
            'guide'      => $this->sanitizeGuide($body['guide'] ?? null),
        ];

        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $context['customer_id']    = (int) $customer->getId();
            $context['customer_phone'] = $this->getCustomerPhone((int) $customer->getId());
        }

        try {
            $handled = $this->orchestrator->handle(
                channel: $channel,
                sessionId: $this->buildSessionId($ipAddress),
                userMessage: $userMessage,
                history: $history,
                context: $context
            );

            $confirmation = $this->issueWriteConfirmation($handled['deferred_write'] ?? null);

            return $result->setData([
                'reply' => $handled['reply'] ?? null,
                'history' => $handled['history'] ?? [],
                'products' => $handled['products'] ?? [],
                'confirmation' => $confirmation,
                'guide' => is_array($handled['guide'] ?? null) ? $handled['guide'] : null,
                'error' => null,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AiAssistant] Controller error: ' . $e->getMessage());
            return $result->setData(['reply' => null, 'history' => $history, 'error' => 'Erro interno. Tente novamente.']);
        }
    }

    /**
     * @param array<string, mixed>|null $deferred
     * @return array<string, string>|null
     */
    private function issueWriteConfirmation(?array $deferred): ?array
    {
        if ($deferred === null || $deferred === []) {
            return null;
        }
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->customerSession->setData(self::SESSION_PENDING_WRITE, [
            'token'       => $token,
            'customer_id' => (int) $this->customerSession->getCustomerId(),
            'tool'        => (string) ($deferred['tool'] ?? ''),
            'action'      => (string) ($deferred['action'] ?? ''),
            'payload'     => is_array($deferred['payload'] ?? null) ? $deferred['payload'] : [],
            'expires'     => time() + self::WRITE_TTL_SECONDS,
        ]);

        return [
            'token'        => $token,
            'summary'      => (string) ($deferred['summary'] ?? 'Confirmar esta ação no site.'),
            'action_label' => (string) ($deferred['action'] ?? 'confirm'),
        ];
    }

    private function executeConfirmedWrite(
        \Magento\Framework\Controller\Result\Json $result,
        string $token,
        string $ipAddress
    ): \Magento\Framework\Controller\Result\Json {
        $pending = $this->customerSession->getData(self::SESSION_PENDING_WRITE);
        $this->customerSession->unsetData(self::SESSION_PENDING_WRITE);

        if (!is_array($pending)
            || empty($pending['token'])
            || !Security::compareStrings((string) $pending['token'], $token)
            || (int) ($pending['expires'] ?? 0) < time()
            || !$this->customerSession->isLoggedIn()
            || (int) ($pending['customer_id'] ?? 0) !== (int) $this->customerSession->getCustomerId()
        ) {
            return $result->setHttpResponseCode(403)->setData([
                'reply' => null,
                'history' => [],
                'error' => 'Confirmação inválida ou expirada. Tente novamente.',
            ]);
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$this->customerApproval->isApproved($customerId)) {
            return $result->setHttpResponseCode(403)->setData([
                'reply' => null,
                'history' => [],
                'error' => 'Ação disponível apenas para clientes B2B aprovados.',
            ]);
        }

        $context = [
            'ip_address'      => $ipAddress,
            'is_b2b'          => true,
            'channel'         => AssistantOrchestratorInterface::CHANNEL_B2B,
            'customer_id'     => $customerId,
            'customer_phone'  => $this->getCustomerPhone($customerId),
            'write_confirmed' => true,
        ];

        $arguments = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];
        $arguments['action'] = (string) ($pending['action'] ?? '');

        try {
            $handled = $this->orchestrator->executeConfirmedWrite(
                (string) ($pending['tool'] ?? ''),
                $arguments,
                $context
            );

            return $result->setData([
                'reply' => $handled['reply'] ?? 'Ação concluída.',
                'history' => $handled['history'] ?? [],
                'products' => $handled['products'] ?? [],
                'confirmation' => null,
                'error' => null,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AiAssistant] Confirmed write error: ' . $e->getMessage());
            return $result->setData([
                'reply' => null,
                'history' => [],
                'error' => 'Não foi possível concluir a ação. Tente pelo painel B2B.',
            ]);
        }
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{role: string, content: string}>
     */
    private function sanitizeHistory(array $raw): array
    {
        $maxTurns = max(2, $this->config->getMaxHistoryMessages() * 2);
        $out = [];

        foreach ($raw as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = (string) ($turn['role'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $content = trim((string) ($turn['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content) > self::MAX_TURN_CHARS) {
                $content = mb_substr($content, 0, self::MAX_TURN_CHARS);
            }
            $out[] = ['role' => $role, 'content' => $content];
            if (count($out) >= $maxTurns) {
                break;
            }
        }

        return $out;
    }

    /**
     * Reconhece intenção de handoff humano ("falar com vendedora/atendente…").
     */
    private function isHandoffIntent(string $text): bool
    {
        $normalized = ' ' . mb_strtolower(
            preg_replace('/\s+/u', ' ', trim(strip_tags($text))) ?: ''
        ) . ' ';
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalized);
        if (is_string($plain) && $plain !== '') {
            $normalized = $plain;
        }
        $normalized = strtolower($normalized);

        if (!preg_match('/(falar|fala|atend|vendedora?|humano|pessoa|consultor|whatsapp)/i', $normalized)) {
            return false;
        }
        $intents = [
            '/falar com (a |minha |o |meu )?(vendedora|vendedor|atendente|consultor)/',
            '/falar com (um |uma |alguem|humano|pessoa|atendente)/',
            '/(quero|queria|preciso|gostaria de|posso) (falar com|atendimento|ser atendido)/',
            '/(atendente|atendimento (humano|pessoal|real))/',
            '/vendedora? (por favor|agora|ja|!)/',
            '/whatsapp/',
            '/(humano|pessoa real|gente de verdade)/',
        ];
        foreach ($intents as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeGuide(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['active' => false];
        }

        $allowedSteps = ['part', 'bike', 'year', 'results'];
        $step = (string) ($raw['step'] ?? 'part');
        $clip = static function (mixed $value): string {
            $text = trim(strip_tags((string) $value));
            return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) : $text;
        };

        return [
            'active' => !empty($raw['active']),
            'step' => in_array($step, $allowedSteps, true) ? $step : 'part',
            'part' => $clip($raw['part'] ?? ''),
            'brand' => $clip($raw['brand'] ?? ''),
            'model' => $clip($raw['model'] ?? ''),
            'year' => $clip($raw['year'] ?? ''),
            'skipped_part' => !empty($raw['skipped_part']),
        ];
    }

    private function buildSessionId(string $ipAddress): string
    {
        $sid = session_id() ?: $ipAddress;
        return hash('sha256', $sid . $ipAddress);
    }

    private function getCustomerPhone(int $customerId): string
    {
        try {
            $conn   = $this->resource->getConnection();
            $select = $conn->select()
                ->from($this->resource->getTableName('customer_address_entity'), ['telephone'])
                ->where('parent_id = ?', $customerId)
                ->where('telephone IS NOT NULL')
                ->order('entity_id DESC')
                ->limit(1);

            $phone = (string) ($conn->fetchOne($select) ?? '');
            return preg_replace('/\D/', '', $phone) ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData([
            'reply' => null,
            'history' => [],
            'error' => 'Sessão expirada. Recarregue a página e tente de novo.',
        ]);

        return new InvalidRequestException($result);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $provided = trim((string) $request->getHeader('X-Magento-Form-Key'));
        if ($provided === '') {
            $provided = trim((string) $request->getParam('form_key', ''));
        }
        $cookieKey = trim((string) $request->getCookie('form_key', ''));
        if ($provided === '' || $cookieKey === '') {
            return false;
        }

        return Security::compareStrings($provided, $cookieKey);
    }
}
