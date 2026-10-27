<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
    }
    private const XML_ENABLED             = 'ai_assistant/general/enabled';
    private const XML_GROQ_KEY            = 'ai_assistant/general/groq_api_key';
    private const XML_MODEL               = 'ai_assistant/general/model';
    private const XML_TIMEOUT             = 'ai_assistant/general/timeout_seconds';
    private const XML_MAX_HISTORY         = 'ai_assistant/general/max_history_messages';

    private const XML_STOREFRONT_ENABLED  = 'ai_assistant/storefront/enabled';
    private const XML_WIDGET_TITLE        = 'ai_assistant/storefront/widget_title';
    private const XML_WELCOME_MSG         = 'ai_assistant/storefront/welcome_message';
    private const XML_RATE_LIMIT_PER_HOUR = 'ai_assistant/storefront/rate_limit_per_hour';

    private const XML_ADMIN_COPILOT       = 'ai_assistant/admin_copilot/enabled';

    private const XML_OR_KEY              = 'ai_assistant/openrouter/api_key';
    private const XML_OR_MODEL            = 'ai_assistant/openrouter/model';
    private const XML_OR_TIMEOUT          = 'ai_assistant/openrouter/timeout_seconds';

    private const XML_PROVIDER            = 'ai_assistant/general/provider';
    private const XML_DS_KEY              = 'ai_assistant/deepseek/api_key';
    private const XML_DS_MODEL            = 'ai_assistant/deepseek/model';
    private const XML_DS_TIMEOUT          = 'ai_assistant/deepseek/timeout_seconds';

    private const XML_USE_ERP_PRICE       = 'ai_assistant/b2b/use_erp_price';

    private const XML_GUIDED_ENABLED      = 'ai_assistant/guided_coach/enabled';
    private const XML_GUIDED_DELAY_MS     = 'ai_assistant/guided_coach/delay_ms';
    private const XML_GUIDED_AUTO_DISMISS = 'ai_assistant/guided_coach/auto_dismiss_ms';
    private const XML_GUIDED_GUEST_MSG    = 'ai_assistant/guided_coach/guest_message';
    private const XML_GUIDED_AUTH_MSG     = 'ai_assistant/guided_coach/auth_message';
    private const XML_GUIDED_HOW_TO_BUY   = 'ai_assistant/guided_coach/how_to_buy_cms_url';

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getGroqApiKey(): string
    {
        return $this->decrypt($this->scopeConfig->getValue(self::XML_GROQ_KEY));
    }

    public function getModel(): string
    {
        $model = (string) $this->scopeConfig->getValue(self::XML_MODEL);
        return $model ?: 'llama-3.3-70b-versatile';
    }

    public function getTimeoutSeconds(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_TIMEOUT) ?: 20;
    }

    public function getMaxHistoryMessages(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_MAX_HISTORY) ?: 10;
    }

    public function isStorefrontEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_STOREFRONT_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getWidgetTitle(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_WIDGET_TITLE, ScopeInterface::SCOPE_STORE)
            ?: 'Assistente AWA';
    }

    public function getWelcomeMessage(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_WELCOME_MSG, ScopeInterface::SCOPE_STORE)
            ?: 'Olá! Como posso ajudar?';
    }

    public function getRateLimitPerHour(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_RATE_LIMIT_PER_HOUR) ?: 20;
    }

    public function isAdminCopilotEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_ADMIN_COPILOT);
    }

    public function getOpenRouterApiKey(): string
    {
        return $this->decrypt($this->scopeConfig->getValue(self::XML_OR_KEY));
    }

    /**
     * Decrypts a value stored via Magento\Config\Model\Config\Backend\Encrypted.
     * Returns the raw string when the value is not encrypted (e.g. set via config:set without --lock-env).
     */
    private function decrypt(mixed $value): string
    {
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        // Magento encrypted values start with '<version>:<cipher>:' (e.g. "0:3:")
        if (preg_match('/^\d+:\d+:/', $str)) {
            return (string) $this->encryptor->decrypt($str);
        }
        return $str;
    }

    public function getOpenRouterModel(): string
    {
        $model = (string) $this->scopeConfig->getValue(self::XML_OR_MODEL);
        return $model ?: 'meta-llama/llama-3.3-70b-instruct';
    }

    public function getOpenRouterTimeoutSeconds(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_OR_TIMEOUT) ?: 25;
    }

    /**
     * 'openrouter' (padrão) ou 'deepseek'. Qualquer outro valor cai em openrouter.
     */
    public function getLlmProvider(): string
    {
        $provider = strtolower(trim((string) $this->scopeConfig->getValue(self::XML_PROVIDER)));
        return $provider === 'deepseek' ? 'deepseek' : 'openrouter';
    }

    public function getDeepSeekApiKey(): string
    {
        return $this->decrypt($this->scopeConfig->getValue(self::XML_DS_KEY));
    }

    public function getDeepSeekModel(): string
    {
        $model = trim((string) $this->scopeConfig->getValue(self::XML_DS_MODEL));
        return $model !== '' ? $model : 'deepseek-chat';
    }

    public function getDeepSeekTimeoutSeconds(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_DS_TIMEOUT) ?: 45;
    }

    /**
     * A5 — preço por tabela ERP do cliente nas tools do assistente.
     * OFF por padrão; ligar por config após validação no HML.
     */
    public function getUseErpPrice(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_USE_ERP_PRICE);
    }

    public function isGuidedCoachEnabled(): bool
    {
        return $this->isStorefrontEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_GUIDED_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getGuidedCoachDelayMs(): int
    {
        $delay = (int) $this->scopeConfig->getValue(self::XML_GUIDED_DELAY_MS, ScopeInterface::SCOPE_STORE);

        return $delay > 0 ? $delay : 4000;
    }

    public function getGuidedCoachAutoDismissMs(): int
    {
        $ms = (int) $this->scopeConfig->getValue(self::XML_GUIDED_AUTO_DISMISS, ScopeInterface::SCOPE_STORE);

        return $ms > 0 ? $ms : 20000;
    }

    public function getGuidedCoachGuestMessage(): string
    {
        $msg = trim((string) $this->scopeConfig->getValue(self::XML_GUIDED_GUEST_MSG, ScopeInterface::SCOPE_STORE));

        return $msg !== ''
            ? $msg
            : 'Precisa de ajuda para encontrar a peça certa? Eu te guio em poucos cliques.';
    }

    public function getGuidedCoachAuthMessage(): string
    {
        $msg = trim((string) $this->scopeConfig->getValue(self::XML_GUIDED_AUTH_MSG, ScopeInterface::SCOPE_STORE));

        return $msg !== ''
            ? $msg
            : 'Precisa de ajuda para encontrar uma peça ou acompanhar seu pedido?';
    }

    public function getHowToBuyCmsUrl(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_GUIDED_HOW_TO_BUY, ScopeInterface::SCOPE_STORE));
    }

    public function getRetentionGuestDays(): int
    {
        $days = (int) $this->scopeConfig->getValue('ai_assistant/privacy/retention_guest_days');

        return $days > 0 ? $days : 30;
    }

    public function getRetentionCustomerDays(): int
    {
        $days = (int) $this->scopeConfig->getValue('ai_assistant/privacy/retention_customer_days');

        return $days > 0 ? $days : 90;
    }

    public function getRetentionErrorDays(): int
    {
        $days = (int) $this->scopeConfig->getValue('ai_assistant/privacy/retention_error_days');

        return $days > 0 ? $days : 14;
    }

    public function getPrivacyPolicyUrlPath(): string
    {
        $path = trim((string) $this->scopeConfig->getValue(
            'ai_assistant/privacy/privacy_policy_url',
            ScopeInterface::SCOPE_STORE
        ));

        return $path !== '' ? $path : 'privacy-policy-cookie-restriction-mode';
    }

    public function getHumanHandoffUrl(): string
    {
        $url = trim((string) $this->scopeConfig->getValue(
            'ai_assistant/privacy/handoff_url',
            ScopeInterface::SCOPE_STORE
        ));

        return $url !== '' ? $url : 'https://wa.me/5516997367588';
    }
}
