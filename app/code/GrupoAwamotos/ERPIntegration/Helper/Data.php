<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\DeploymentConfig;

class Data extends AbstractHelper
{
    private const XML_PREFIX = 'grupoawamotos_erp/';

    /**
     * Environment variable names for secure credential storage
     */
    private const ENV_HOST = 'ERP_SQL_HOST';
    private const ENV_PORT = 'ERP_SQL_PORT';
    private const ENV_DATABASE = 'ERP_SQL_DATABASE';
    private const ENV_USERNAME = 'ERP_SQL_USERNAME';
    private const ENV_PASSWORD = 'ERP_SQL_PASSWORD';
    private const ENV_WRITE_ENABLED = 'ERP_SQL_WRITE_ENABLED';
    private const ENV_WRITE_USERNAME = 'ERP_SQL_WRITE_USERNAME';
    private const ENV_WRITE_PASSWORD = 'ERP_SQL_WRITE_PASSWORD';
    private const ENV_WHATSAPP_TOKEN = 'ERP_WHATSAPP_TOKEN';

    private EncryptorInterface $encryptor;
    private DeploymentConfig $deploymentConfig;

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        DeploymentConfig $deploymentConfig
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->deploymentConfig = $deploymentConfig;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'connection/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if environment variables should be used for credentials
     */
    public function useEnvCredentials(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'connection/use_env',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getHost(): string
    {
        // Priority: ENV -> env.php -> admin config
        if ($this->useEnvCredentials()) {
            $envHost = $this->getEnvValue(self::ENV_HOST);
            if ($envHost) {
                return $envHost;
            }
        }

        // Check env.php (deployment config)
        $deployHost = $this->getDeploymentConfigValue('erp/host');
        if ($deployHost) {
            return $deployHost;
        }

        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/host',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getPort(): int
    {
        if ($this->useEnvCredentials()) {
            $envPort = $this->getEnvValue(self::ENV_PORT);
            if ($envPort) {
                return (int) $envPort;
            }
        }

        $deployPort = $this->getDeploymentConfigValue('erp/port');
        if ($deployPort) {
            return (int) $deployPort;
        }

        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/port',
            ScopeInterface::SCOPE_STORE
        ) ?: 1433);
    }

    public function getDatabase(): string
    {
        $database = '';

        if ($this->useEnvCredentials()) {
            $envDb = $this->getEnvValue(self::ENV_DATABASE);
            if ($envDb) {
                $database = $envDb;
            }
        }

        if ($database === '') {
            $deployDb = $this->getDeploymentConfigValue('erp/database');
            if ($deployDb) {
                $database = $deployDb;
            }
        }

        if ($database === '') {
            $database = (string) $this->scopeConfig->getValue(
                self::XML_PREFIX . 'connection/database',
                ScopeInterface::SCOPE_STORE
            );
        }

        return $this->sanitizeDatabaseName($database);
    }

    /**
     * Sanitize database name to prevent SQL injection in USE statements.
     *
     * Only allows alphanumeric characters, underscores, hyphens, and dots.
     *
     * @throws \InvalidArgumentException if the database name contains invalid characters
     */
    private function sanitizeDatabaseName(string $database): string
    {
        if ($database === '') {
            return '';
        }

        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $database)) {
            throw new \InvalidArgumentException(
                'Invalid ERP database name. Only alphanumeric, underscore, hyphen, and dot characters are allowed.'
            );
        }

        return $database;
    }

    public function getUsername(): string
    {
        if ($this->useEnvCredentials()) {
            $envUser = $this->getEnvValue(self::ENV_USERNAME);
            if ($envUser) {
                return $envUser;
            }
        }

        $deployUser = $this->getDeploymentConfigValue('erp/username');
        if ($deployUser) {
            return $deployUser;
        }

        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/username',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getPassword(): string
    {
        if ($this->useEnvCredentials()) {
            $envPass = $this->getEnvValue(self::ENV_PASSWORD);
            if ($envPass) {
                return $envPass;
            }
        }

        $deployPass = $this->getDeploymentConfigValue('erp/password');
        if ($deployPass) {
            return $deployPass;
        }

        $value = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/password',
            ScopeInterface::SCOPE_STORE
        );
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * Get value from environment variable
     */
    private function getEnvValue(string $name): ?string
    {
        $value = getenv($name);
        return ($value !== false && $value !== '') ? $value : null;
    }

    /**
     * Get value from deployment config (app/etc/env.php)
     */
    private function getDeploymentConfigValue(string $path): ?string
    {
        try {
            $value = $this->deploymentConfig->get($path);
            return $value ? (string) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get credential source for diagnostics
     */
    public function getCredentialSource(): string
    {
        if ($this->useEnvCredentials() && $this->getEnvValue(self::ENV_HOST)) {
            return 'environment';
        }

        if ($this->getDeploymentConfigValue('erp/host')) {
            return 'env.php';
        }

        return 'admin_config';
    }

    public function getDriver(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/driver',
            ScopeInterface::SCOPE_STORE
        ) ?: 'auto');
    }

    public function getConnectionTimeout(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'connection/timeout',
            ScopeInterface::SCOPE_STORE
        ) ?: 30);
    }

    public function getTrustServerCertificate(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'connection/trust_certificate',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isProductSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_products/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getProductSyncFrequency(): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_products/frequency',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function filterComercializa(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_products/filter_comercializa',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Escopo de produtos que a loja deve publicar.
     *
     * POR QUE EXISTE: o filtro antigo (filter_comercializa) publica apenas
     * CKCOMERCIALIZA='S' — 631 produtos. Medi 432 produtos ATIVOS com preço
     * na tabela padrão (R$ 12.076,46) que ficavam fora por causa desse flag.
     * O dono pediu "todos os anúncios ativos no Sectra no B2B".
     *
     * Modos:
     *   comercializa (PADRÃO, comportamento histórico): CCKATIVO='S' AND CKCOMERCIALIZA='S'
     *   uniao:      CCKATIVO='S' AND (CKCOMERCIALIZA='S' OR VLRVDSUG > 0)   <-- RECOMENDADO
     *   com_preco:  CCKATIVO='S' AND VLRVDSUG > 0   <-- CUIDADO: SUBSTITUI o criterio
     *               e REMOVE da loja os comercializaveis sem preco na lista padrao.
     *               Medido em 05/10/2026: 103 produtos (102 deles com preco valido no
     *               Magento) sairiam. Use 'uniao' para somar sem perder.
     *   todos:      CCKATIVO='S'
     *
     * Sem configuração explícita, mantém o comportamento antigo para não haver surpresa.
     */
    /**
     * Classe fiscal padrao dos produtos.
     *
     * O ProductSync nao definia tax_class_id e 498 produtos ficaram com 0 (None)
     * enquanto 678 tinham 2 (Taxable Goods) — medido em 05/10/2026. Sem regra de
     * imposto isso nao altera o preco hoje, mas se houver regra um dia os produtos
     * sem classe ficariam fora do calculo.
     *
     * Le a config do Magento (tax/classes/default_product_tax_class); se vazia,
     * usa 2 (Taxable Goods), que e o padrao do proprio Magento.
     */
    public function getDefaultTaxClassId(): int
    {
        $id = (int) $this->scopeConfig->getValue(
            'tax/classes/default_product_tax_class',
            ScopeInterface::SCOPE_STORE
        );

        return $id > 0 ? $id : 2;
    }

    public function getProductScope(): string
    {
        $scope = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_products/scope',
            ScopeInterface::SCOPE_STORE
        );
        $scope = strtolower(trim($scope));
        if (in_array($scope, ['comercializa', 'uniao', 'com_preco', 'todos'], true)) {
            return $scope;
        }
        // retrocompatível: sem 'scope', vale o booleano antigo
        return $this->filterComercializa() ? 'comercializa' : 'todos';
    }

    public function isStockSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_stock/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isStockRealtime(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_stock/realtime',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getStockFilial(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/filial',
            ScopeInterface::SCOPE_STORE
        ) ?: 1);
    }

    /**
     * Get list of branches for stock aggregation
     * Returns array of branch codes, or single branch if multi-branch is disabled
     */
    public function getStockFiliais(): array
    {
        if (!$this->isMultiBranchEnabled()) {
            return [$this->getStockFilial()];
        }

        $filiais = $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/filiais',
            ScopeInterface::SCOPE_STORE
        );

        if (empty($filiais)) {
            return [$this->getStockFilial()];
        }

        // Parse comma-separated list
        $list = array_map('intval', array_filter(explode(',', $filiais)));

        return !empty($list) ? $list : [$this->getStockFilial()];
    }

    /**
     * Check if multi-branch stock aggregation is enabled
     */
    public function isMultiBranchEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_stock/multi_branch',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get stock aggregation mode (sum, min, max, avg)
     */
    public function getStockAggregationMode(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/aggregation_mode',
            ScopeInterface::SCOPE_STORE
        ) ?: 'sum');
    }

    public function getStockCacheTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/cache_ttl',
            ScopeInterface::SCOPE_STORE
        ) ?: 300);
    }

    public function isCustomerSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_customers/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isOrderSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_orders/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function sendOrderOnPlace(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_orders/send_on_place',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if order sync should use message queue (async mode)
     * Default: true for better resilience
     */
    public function isOrderQueueEnabled(): bool
    {
        // Default to true if not set (async mode is more resilient)
        $value = $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_orders/use_queue',
            ScopeInterface::SCOPE_STORE
        );

        // If not configured, default to true
        return $value === null ? true : (bool) $value;
    }

    /**
     * Get negative cache TTL in seconds (for SKUs not found in ERP)
     */
    public function getNegativeCacheTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/negative_cache_ttl',
            ScopeInterface::SCOPE_STORE
        ) ?: 60);
    }

    public function isPriceSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_prices/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get default price list code (FATORPRECO) for base Magento prices
     * Default: 24 (NACIONAL)
     */
    public function getDefaultPriceList(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_prices/default_price_list',
            ScopeInterface::SCOPE_STORE
        ) ?: 24);
    }

    public function isPriceDeltaSyncEnabled(): bool
    {
        return $this->isPriceSyncEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_prices/delta_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getPriceDeltaBatchSize(): int
    {
        $value = (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_prices/delta_batch_size',
            ScopeInterface::SCOPE_STORE
        ) ?: 500);

        return max(50, min($value, 5000));
    }

    public function getCustomerPriceDeltaBatchSize(): int
    {
        $value = (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_prices/customer_delta_batch_size',
            ScopeInterface::SCOPE_STORE
        ) ?: 500);

        return max(50, min($value, 5000));
    }

    public function isCategorySyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_categories/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getCategoryRootName(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_categories/root_category_name',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Catalogo ERP');
    }

    public function getCategoryIncludeInMenu(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_categories/include_in_menu',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isSuggestionsEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'suggestions/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getMaxSuggestions(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'suggestions/max_suggestions',
            ScopeInterface::SCOPE_STORE
        ) ?: 10);
    }

    // ========== RFM Configuration ==========

    public function isRfmEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'rfm/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getRfmAnalysisPeriod(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'rfm/analysis_period',
            ScopeInterface::SCOPE_STORE
        ) ?: 24);
    }

    public function getRfmCacheTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'rfm/cache_ttl',
            ScopeInterface::SCOPE_STORE
        ) ?: 86400);
    }

    public function isAtRiskAlertEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'rfm/alert_at_risk',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getAtRiskAlertEmail(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'rfm/alert_email',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ========== Forecast Configuration ==========

    public function isForecastEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'forecast/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getForecastMethod(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'forecast/method',
            ScopeInterface::SCOPE_STORE
        ) ?: 'hybrid');
    }

    public function getForecastConfidenceLevel(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'forecast/confidence_level',
            ScopeInterface::SCOPE_STORE
        ) ?: 85);
    }

    public function getMonthlyTarget(): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'forecast/monthly_target',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isForecastAlertEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'forecast/alert_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isAlertAtRiskEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'rfm/alert_at_risk',
            ScopeInterface::SCOPE_STORE
        );
    }
    // ========== Stock Anomaly Alert Configuration ==========

    public function isStockAnomalyAlertEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_stock/anomaly_alert_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getStockAnomalyAlertEmail(): string
    {
        $email = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_stock/anomaly_alert_email',
            ScopeInterface::SCOPE_STORE
        );

        return $email ?: $this->getAtRiskAlertEmail();
    }


    // ========== Suggested Cart Configuration ==========

    public function isSuggestedCartEnabled(): bool
    {
        return $this->isSuggestionsEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'suggestions/cart_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getSuggestedCartMinProducts(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'suggestions/cart_min_products',
            ScopeInterface::SCOPE_STORE
        ) ?: 3);
    }

    public function getSuggestedCartMaxProducts(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'suggestions/cart_max_products',
            ScopeInterface::SCOPE_STORE
        ) ?: 15);
    }

    public function getFreeShippingThreshold(): float
    {
        return (float) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'suggestions/free_shipping_threshold',
            ScopeInterface::SCOPE_STORE
        ) ?: 1500);
    }

    public function getSuggestionsCacheTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'suggestions/cache_ttl',
            ScopeInterface::SCOPE_STORE
        ) ?: 1800);
    }

    // ========== Coupon Configuration ==========

    public function isCouponGenerationEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'coupon/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getCouponValidDays(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'coupon/valid_days',
            ScopeInterface::SCOPE_STORE
        ) ?: 30);
    }

    public function getCouponMinOrderAmount(): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'coupon/min_order_amount',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getCouponDiscountForSegment(string $segment): int
    {
        $discounts = $this->scopeConfig->getValue(
            self::XML_PREFIX . 'coupon/segment_discounts',
            ScopeInterface::SCOPE_STORE
        );

        if (is_string($discounts)) {
            $discounts = json_decode($discounts, true);
        }

        return (int) ($discounts[$segment] ?? 0);
    }

    public function getDefaultCouponDiscount(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'coupon/default_discount',
            ScopeInterface::SCOPE_STORE
        ) ?: 10);
    }

    public function isCouponAutoSendEnabled(): bool
    {
        return $this->isCouponGenerationEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'coupon/auto_send',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ========== WhatsApp Configuration (Z-API) ==========

    public function isWhatsAppEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Z-API Instance ID
     */
    public function getZApiInstanceId(): string
    {
        // Priority: ENV -> deployment config (env.php) -> admin config
        $envValue = $this->getEnvValue('ZAPI_INSTANCE_ID');
        if ($envValue) {
            return $envValue;
        }

        // Check deployment config (env.php)
        $deployValue = $this->getDeploymentConfigValue('system/default/grupoawamotos_erp/whatsapp/zapi_instance_id');
        if ($deployValue) {
            return $deployValue;
        }

        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/zapi_instance_id',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get Z-API Token
     */
    public function getZApiToken(): string
    {
        // Priority: ENV -> deployment config (env.php) -> admin config (encrypted)
        $envValue = $this->getEnvValue('ZAPI_TOKEN');
        if ($envValue) {
            return $envValue;
        }

        // Check deployment config (env.php) - not encrypted
        $deployToken = $this->getDeploymentConfigValue('system/default/grupoawamotos_erp/whatsapp/zapi_token');
        if ($deployToken) {
            return $deployToken;
        }

        // Admin config is encrypted
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/zapi_token',
            ScopeInterface::SCOPE_STORE
        );
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * Get Z-API Client Token (optional security token)
     */
    public function getZApiClientToken(): string
    {
        // Priority: ENV -> deployment config (env.php) -> admin config (encrypted)
        $envValue = $this->getEnvValue('ZAPI_CLIENT_TOKEN');
        if ($envValue) {
            return $envValue;
        }

        // Check deployment config (env.php) - not encrypted
        $deployToken = $this->getDeploymentConfigValue('system/default/grupoawamotos_erp/whatsapp/zapi_client_token');
        if ($deployToken) {
            return $deployToken;
        }

        // Admin config is encrypted
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/zapi_client_token',
            ScopeInterface::SCOPE_STORE
        );
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * Get admin phone number for test messages
     */
    public function getWhatsAppAdminPhone(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/admin_phone',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Legacy method - kept for backwards compatibility
     * @deprecated Use getZApiInstanceId() instead
     */
    public function getWhatsAppPhoneNumberId(): string
    {
        return $this->getZApiInstanceId();
    }

    /**
     * Legacy method - kept for backwards compatibility
     * @deprecated Use getZApiToken() instead
     */
    public function getWhatsAppAccessToken(): string
    {
        return $this->getZApiToken();
    }

    public function getWhatsAppBusinessId(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/business_id',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getWhatsAppReengagementTemplate(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/template_reengagement',
            ScopeInterface::SCOPE_STORE
        ) ?: 'reengagement_coupon');
    }

    public function getWhatsAppSuggestionTemplate(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/template_suggestion',
            ScopeInterface::SCOPE_STORE
        ) ?: 'product_suggestion');
    }

    public function getWhatsAppOrderStatusTemplate(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'whatsapp/template_order_status',
            ScopeInterface::SCOPE_STORE
        ) ?: 'order_status_update');
    }

    public function isWhatsAppReengagementEnabled(): bool
    {
        return $this->isWhatsAppEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/reengagement_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isWhatsAppOrderStatusEnabled(): bool
    {
        return $this->isWhatsAppEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/order_status_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if should notify on new orders
     */
    public function isWhatsAppNewOrderEnabled(): bool
    {
        return $this->isWhatsAppEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/new_order_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if should notify on payment confirmation
     */
    public function isWhatsAppPaymentEnabled(): bool
    {
        return $this->isWhatsAppEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/payment_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if should notify on shipping
     */
    public function isWhatsAppShippingEnabled(): bool
    {
        return $this->isWhatsAppEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'whatsapp/shipping_enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ========== Store Information ==========

    /**
     * Get store name
     */
    public function getStoreName(): string
    {
        return (string) ($this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        ) ?: 'Loja');
    }

    /**
     * Get store base URL
     */
    public function getStoreUrl(): string
    {
        return (string) $this->scopeConfig->getValue(
            'web/secure/base_url',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ========== Image Sync Configuration ==========

    /**
     * Check if image sync is enabled
     */
    public function isImageSyncEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_images/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get image source type (table, folder, url, auto)
     */
    public function getImageSource(): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_images/source',
            ScopeInterface::SCOPE_STORE
        ) ?: 'auto');
    }

    /**
     * Get base path for local/network images
     */
    public function getImageBasePath(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_images/base_path',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get base URL for remote images (with {sku} placeholder)
     */
    public function getImageBaseUrl(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_images/base_url',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if should replace existing images
     */
    public function shouldReplaceImages(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_images/replace_existing',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get image sync frequency in minutes
     */
    public function getImageSyncFrequency(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'sync_images/frequency',
            ScopeInterface::SCOPE_STORE
        ) ?: 720);
    }

    // ==================== Orphan Image Cleanup ====================

    /**
     * Check if orphan image cleanup is enabled
     */
    public function isOrphanCleanupEnabled(): bool
    {
        return $this->isImageSyncEnabled()
            && $this->scopeConfig->isSetFlag(
                self::XML_PREFIX . 'sync_images/clean_orphans_enabled',
                ScopeInterface::SCOPE_STORE
            );
    }

    /**
     * Check if orphan cleanup is in dry-run mode
     */
    public function isOrphanCleanupDryRun(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_images/clean_orphans_dry_run',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if should keep manually uploaded images
     */
    public function shouldKeepManualImages(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'sync_images/clean_orphans_keep_manual',
            ScopeInterface::SCOPE_STORE
        );
    }

    // ==================== Write Connection ====================

    public function isWriteConnectionEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->getEnvValue(self::ENV_WRITE_ENABLED) === '1') {
            return $this->getWriteUsername() !== '' && $this->getWritePassword() !== '';
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PREFIX . 'write_connection/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getWriteUsername(): string
    {
        $envUser = $this->getEnvValue(self::ENV_WRITE_USERNAME);
        if ($envUser !== null && $envUser !== '') {
            return $envUser;
        }

        return (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'write_connection/username',
            ScopeInterface::SCOPE_STORE
        ) ?? '');
    }

    public function getWritePassword(): string
    {
        $envPass = $this->getEnvValue(self::ENV_WRITE_PASSWORD);
        if ($envPass !== null && $envPass !== '') {
            return $envPass;
        }

        $value = (string) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'write_connection/password',
            ScopeInterface::SCOPE_STORE
        ) ?? '');
        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    // ==================== Circuit Breaker Configuration ====================

    public function getCircuitBreakerFailureThreshold(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'circuit_breaker/failure_threshold',
            ScopeInterface::SCOPE_STORE
        ) ?: 5);
    }

    public function getCircuitBreakerOpenTimeout(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'circuit_breaker/open_timeout',
            ScopeInterface::SCOPE_STORE
        ) ?: 60);
    }

    public function getCircuitBreakerSuccessThreshold(): int
    {
        return (int) ($this->scopeConfig->getValue(
            self::XML_PREFIX . 'circuit_breaker/success_threshold',
            ScopeInterface::SCOPE_STORE
        ) ?: 3);
    }
}
