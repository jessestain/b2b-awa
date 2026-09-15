<?php

/**
 * Helper facade: checksum + Receita lookup. Callers keep injecting this class.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Helper;

use GrupoAwamotos\B2B\Service\CnpjReceitaLookup;
use GrupoAwamotos\B2B\Service\CnpjValidator as CnpjChecksum;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class CnpjValidator extends AbstractHelper
{
    private const CONFIG_PATH_LOOKUP_RATE_LIMIT_ENABLED = 'grupoawamotos_b2b/cnpj_lookup/rate_limit_enabled';
    private const CONFIG_PATH_LOOKUP_RATE_LIMIT_MAX = 'grupoawamotos_b2b/cnpj_lookup/rate_limit_max_requests';
    private const CONFIG_PATH_LOOKUP_RATE_LIMIT_WINDOW = 'grupoawamotos_b2b/cnpj_lookup/rate_limit_window_seconds';

    private const DEFAULT_RATE_LIMIT_MAX = 20;
    private const DEFAULT_RATE_LIMIT_WINDOW = 60;

    private CnpjChecksum $checksum;
    private CnpjReceitaLookup $receitaLookup;

    public function __construct(
        Context $context,
        Curl $curl,
        CacheInterface $cache,
        Json $json,
        ?CnpjChecksum $checksum = null,
        ?CnpjReceitaLookup $receitaLookup = null
    ) {
        parent::__construct($context);
        $this->checksum = $checksum ?? new CnpjChecksum();
        $this->receitaLookup = $receitaLookup ?? new CnpjReceitaLookup(
            $curl,
            $cache,
            $json,
            $this->_logger,
            $this->scopeConfig,
            $this->checksum
        );
    }

    public function validateLocal(string $cnpj): bool
    {
        return $this->checksum->isValid($cnpj);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateApi(string $cnpj, bool $forceRefresh = false): ?array
    {
        return $this->receitaLookup->lookup($cnpj, $forceRefresh);
    }

    /**
     * Cached Receita payload only — never hits the network.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedPayloadOnly(string $cnpj): ?array
    {
        return $this->receitaLookup->getCachedPayloadOnly($cnpj);
    }

    public function format(string $cnpj): string
    {
        return $this->checksum->format($cnpj);
    }

    public function clean(string $cnpj): string
    {
        return $this->checksum->clean($cnpj);
    }

    public function clearCache(?string $cnpj = null): bool
    {
        return $this->receitaLookup->clearCache($cnpj);
    }

    /**
     * @param array<string, mixed>|null $apiData
     * @return array<string, mixed>
     */
    public function toHttpResult(?array $apiData): array
    {
        return $this->receitaLookup->toHttpResult($apiData);
    }

    /**
     * Validate digits/checksum then return the public HTTP payload.
     *
     * @return array<string, mixed>
     */
    public function lookupHttp(string $cnpj, bool $forceRefresh = false): array
    {
        $cnpj = $this->clean($cnpj);

        if (strlen($cnpj) !== 14) {
            return [
                'success' => false,
                'message' => 'CNPJ deve ter 14 dígitos.'
            ];
        }

        if (!$this->validateLocal($cnpj)) {
            return [
                'success' => false,
                'message' => 'CNPJ inválido. Verifique os dígitos.'
            ];
        }

        return $this->toHttpResult($this->validateApi($cnpj, $forceRefresh));
    }

    public function isRateLimitEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOOKUP_RATE_LIMIT_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getRateLimitMaxRequests(): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_LOOKUP_RATE_LIMIT_MAX,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : self::DEFAULT_RATE_LIMIT_MAX;
    }

    public function getRateLimitWindowSeconds(): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_LOOKUP_RATE_LIMIT_WINDOW,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : self::DEFAULT_RATE_LIMIT_WINDOW;
    }
}
