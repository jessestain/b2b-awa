<?php

declare(strict_types=1);

namespace GrupoAwamotos\SmartSuggestions\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Configuration Helper
 */
class Config extends AbstractHelper
{
    private const XML_PATH_GENERAL = 'smart_suggestions/general/';
    private const XML_PATH_RFM = 'smart_suggestions/rfm/';
    private const XML_PATH_SUGGESTIONS = 'smart_suggestions/suggestions/';
    private const XML_PATH_FORECAST = 'smart_suggestions/forecast/';
    private const XML_PATH_WHATSAPP = 'smart_suggestions/whatsapp/';
    private const XML_PATH_CRON = 'smart_suggestions/cron/';
    private const XML_PATH_EXPORT = 'smart_suggestions/export/';

    private EncryptorInterface $encryptor;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    // ============ GENERAL ============

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDebugMode(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_GENERAL . 'debug_mode',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ============ RFM ============

    public function getRfmAnalysisPeriod(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_RFM . 'analysis_period_days',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 365;
    }

    public function getRfmRecencyWeight(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_RFM . 'recency_weight',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.35;
    }

    public function getRfmFrequencyWeight(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_RFM . 'frequency_weight',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.35;
    }

    public function getRfmMonetaryWeight(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_RFM . 'monetary_weight',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.30;
    }

    public function getRfmMinOrders(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_RFM . 'min_orders',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 2;
    }

    // ============ SUGGESTIONS ============

    public function getMaxRepurchaseItems(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'max_repurchase_items',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 10;
    }

    public function getMaxCrossSellItems(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'max_crosssell_items',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 5;
    }

    public function getMaxSimilarItems(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'max_similar_items',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 5;
    }

    public function getCycleTolerancePercent(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'cycle_tolerance_percent',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 20;
    }

    public function getMinSupportCrossSell(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'min_support_crosssell',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 3;
    }

    public function getRepurchaseCycleDefault(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'repurchase_cycle_default',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 30;
    }

    public function getRepurchaseMinCycleRatio(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'repurchase_min_cycle_ratio',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.5;
    }

    public function getRepurchaseLookbackYears(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'repurchase_lookback_years',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 2;
    }

    public function getCrossSellLookbackMonths(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_SUGGESTIONS . 'crosssell_lookback_months',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 6;
    }

    // ============ FORECAST ============

    public function getMonteCarloIterations(): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_FORECAST . 'monte_carlo_iterations',
            ScopeInterface::SCOPE_STORE
        ) ?: 1000;
    }

    public function getMovingAvgDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_FORECAST . 'moving_avg_days',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 14;
    }

    public function getTrendWeight(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_FORECAST . 'trend_weight',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.3;
    }

    public function getSeasonalityWeight(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_FORECAST . 'seasonality_weight',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 0.2;
    }

    public function getMonthlyGoal(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_FORECAST . 'monthly_goal',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 500000;
    }

    // ============ WHATSAPP ============

    public function isWhatsappEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WHATSAPP . 'enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWhatsappProvider(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'provider',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'meta';
    }

    public function getWhatsappApiUrl(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'api_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '';
    }

    public function getWhatsappApiToken(?int $storeId = null): string
    {
        // FIX (19/10/2026): o campo tem backend_model Encrypted — o ScopeConfig ja
        // devolve o valor DESCRIPTOGRAFADO; descriptografar de novo gerava lixo
        // (quebrava a autenticacao no provedor). Nao descriptografar aqui.
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'api_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWhatsappPhoneNumberId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'phone_number_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '';
    }

    public function getTwilioSid(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'twilio_sid',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '';
    }

    public function getTwilioFrom(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'twilio_from',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '';
    }

    public function getEvolutionInstance(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'evolution_instance',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'awamotos';
    }

    public function getWhatsappMessageTemplate(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'message_template',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: '';
    }

    // ============ CRON ============


    public function getZApiInstanceId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'zapi_instance_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getZApiToken(?int $storeId = null): string
    {
        // FIX (19/10/2026): campo com backend_model Encrypted — getValue ja devolve
        // descriptografado; nao descriptografar de novo.
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'zapi_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getZApiClientToken(?int $storeId = null): string
    {
        // FIX (19/10/2026): idem acima.
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'zapi_client_token',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isRfmCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CRON . 'rfm_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getRfmCronSchedule(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CRON . 'rfm_schedule',
            ScopeInterface::SCOPE_STORE
        ) ?: '0 2 * * *';
    }

    public function isSuggestionsCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CRON . 'suggestions_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getSuggestionsCronSchedule(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CRON . 'suggestions_schedule',
            ScopeInterface::SCOPE_STORE
        ) ?: '0 6 * * 1';
    }

    public function isAutoSendWhatsappEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CRON . 'auto_send_whatsapp',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ============ EXPORT ============

    public function getCsvDelimiter(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_EXPORT . 'csv_delimiter',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: ';';
    }

    public function includeHeaders(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXPORT . 'include_headers',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    // ============ WHATSAPP BUSINESS HOURS ============

    public function getWhatsappStartHour(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'start_hour',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? (int) $value : 8;
    }

    public function getWhatsappEndHour(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP . 'end_hour',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== null ? (int) $value : 20;
    }

    public function skipWeekendsForWhatsapp(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WHATSAPP . 'skip_weekends',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
