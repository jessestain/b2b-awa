<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * CNPJ company lookup (ReceitaWS + BrasilAPI fallback, cache, audit).
 */
class CnpjReceitaLookup
{
    private const CONFIG_PATH_LOOKUP_ENABLED = 'grupoawamotos_b2b/cnpj_lookup/enabled';
    private const CONFIG_PATH_LOOKUP_API_URL = 'grupoawamotos_b2b/cnpj_lookup/api_url';
    private const CONFIG_PATH_LOOKUP_TIMEOUT = 'grupoawamotos_b2b/cnpj_lookup/timeout';
    private const CONFIG_PATH_LOOKUP_REQUIRE_ACTIVE = 'grupoawamotos_b2b/cnpj_lookup/require_active_status';
    private const CONFIG_PATH_LOOKUP_ALLOW_FALLBACK = 'grupoawamotos_b2b/cnpj_lookup/allow_local_fallback';
    private const CONFIG_PATH_LOOKUP_CACHE_ENABLED = 'grupoawamotos_b2b/cnpj_lookup/cache_enabled';
    private const CONFIG_PATH_LOOKUP_CACHE_TTL = 'grupoawamotos_b2b/cnpj_lookup/cache_ttl';

    private const DEFAULT_API_URL = 'https://receitaws.com.br/v1/cnpj/';
    private const FALLBACK_API_URL = 'https://brasilapi.com.br/api/cnpj/v1/';
    private const CNPJWS_API_URL = 'https://publica.cnpj.ws/cnpj/';
    private const LOOKUP_USER_AGENT = 'AWA-Motos/1.0 (+https://awamotos.com)';
    private const DEFAULT_TIMEOUT = 10;
    private const DEFAULT_CACHE_TTL = 86400;
    private const NEGATIVE_CACHE_TTL = 3600;

    private const CACHE_KEY_PREFIX = 'grupoawamotos_b2b_cnpj_lookup_';
    private const CACHE_TAG = 'GRUPOAWAMOTOS_B2B_CNPJ_LOOKUP';

    public function __construct(
        private readonly Curl $curl,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CnpjValidator $checksum
    ) {
    }

    /**
     * Read a previously cached Receita payload without calling external APIs.
     *
     * @return array<string, mixed>|null
     */
    public function getCachedPayloadOnly(string $cnpj): ?array
    {
        if (!$this->checksum->isValid($cnpj)) {
            return null;
        }

        return $this->loadFromCache($this->checksum->clean($cnpj));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $cnpj, bool $forceRefresh = false): ?array
    {
        if (!$this->checksum->isValid($cnpj)) {
            return null;
        }

        $cnpjClean = $this->checksum->clean($cnpj);

        if (!$this->isLookupEnabled()) {
            $this->audit('lookup_disabled', $cnpjClean);

            return $this->buildLocalFallbackPayload(
                'Consulta externa desabilitada. CNPJ validado localmente.'
            );
        }

        if ($forceRefresh) {
            $this->audit('force_refresh', $cnpjClean);
        }

        if (!$forceRefresh) {
            $cachedPayload = $this->loadFromCache($cnpjClean);
            if ($cachedPayload !== null) {
                $this->audit('cache_hit', $cnpjClean);
                return $cachedPayload;
            }
        }

        try {
            $this->curl->setOption(CURLOPT_TIMEOUT, $this->getLookupTimeout());
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $this->curl->setHeaders([
                'Accept' => 'application/json',
                'User-Agent' => self::LOOKUP_USER_AGENT,
            ]);

            $endpoint = rtrim($this->getLookupApiUrl(), '/') . '/' . $cnpjClean;
            $this->curl->get($endpoint);

            $httpStatus = (int) $this->curl->getStatus();
            $response = (string) $this->curl->getBody();
            $data = json_decode($response, true);

            if ($this->isRateLimitedResponse($httpStatus, is_array($data) ? $data : null)) {
                $this->audit('api_rate_limited', $cnpjClean, ['http_status' => $httpStatus]);
                return $this->resolveWithFallbacks($cnpjClean, 'api_rate_limited_fallback_ok');
            }

            if (!is_array($data) || (isset($data['status']) && strtoupper((string) $data['status']) === 'ERROR')) {
                $this->audit('api_not_found_or_error', $cnpjClean, [
                    'http_status' => $httpStatus,
                    'message' => is_array($data) ? (string) ($data['message'] ?? '') : 'invalid_json',
                ]);
                return $this->resolveWithFallbacks($cnpjClean, 'api_not_found_fallback_ok');
            }

            if (!$this->isCompleteCompanyPayload($data)) {
                $this->audit('api_incomplete_payload', $cnpjClean, [
                    'http_status' => $httpStatus,
                    'message' => (string) ($data['message'] ?? ''),
                ]);
                return $this->resolveWithFallbacks($cnpjClean, 'api_incomplete_fallback_ok');
            }

            if ($this->isRequireActiveStatusEnabled()
                && isset($data['situacao'])
                && strtoupper((string) $data['situacao']) !== 'ATIVA'
            ) {
                $fallbackSituacao = $this->crossVerifyWithFallbackApi($cnpjClean);

                if ($fallbackSituacao !== null && strtoupper($fallbackSituacao) === 'ATIVA') {
                    $this->audit('api_status_mismatch_resolved', $cnpjClean, [
                        'primary_situacao' => (string) $data['situacao'],
                        'fallback_situacao' => $fallbackSituacao
                    ]);
                    $data['situacao'] = $fallbackSituacao;
                } else {
                    $invalidPayload = [
                        'valid' => false,
                        'message' => (string) __('CNPJ com situação: %1', $data['situacao']),
                        'source' => 'api',
                        'data' => $data
                    ];

                    $this->saveToCache($cnpjClean, $invalidPayload, self::NEGATIVE_CACHE_TTL);
                    $this->audit('api_invalid_status', $cnpjClean, [
                        'situacao' => (string) $data['situacao'],
                        'fallback_situacao' => $fallbackSituacao ?? 'unavailable'
                    ]);

                    return $invalidPayload;
                }
            }

            $payload = [
                'valid' => true,
                'source' => 'api',
                'razao_social' => $data['nome'] ?? '',
                'nome_fantasia' => $data['fantasia'] ?? '',
                'cnpj' => $data['cnpj'] ?? $cnpj,
                'situacao' => $data['situacao'] ?? '',
                'tipo' => $data['tipo'] ?? '',
                'porte' => $data['porte'] ?? '',
                'natureza_juridica' => $data['natureza_juridica'] ?? '',
                'atividade_principal' => $data['atividade_principal'][0]['text'] ?? '',
                'logradouro' => $data['logradouro'] ?? '',
                'numero' => $data['numero'] ?? '',
                'complemento' => $data['complemento'] ?? '',
                'bairro' => $data['bairro'] ?? '',
                'municipio' => $data['municipio'] ?? '',
                'uf' => $data['uf'] ?? '',
                'cep' => $data['cep'] ?? '',
                'telefone' => $data['telefone'] ?? '',
                'email' => $data['email'] ?? '',
                'data' => $data
            ];

            $this->saveToCache($cnpjClean, $payload);
            $this->audit('api_success', $cnpjClean);

            return $payload;
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Erro ao validar CNPJ via API (%s): %s',
                    $cnpjClean,
                    $exception->getMessage()
                )
            );

            if ($this->isLocalFallbackEnabled()) {
                $this->audit('api_exception_fallback', $cnpjClean, [
                    'error' => $exception->getMessage()
                ]);

                return $this->buildLocalFallbackPayload(
                    'Validação via API indisponível. CNPJ validado localmente.'
                );
            }

            $this->audit('api_exception_no_fallback', $cnpjClean, [
                'error' => $exception->getMessage()
            ]);

            return null;
        }
    }

    public function clearCache(?string $cnpj = null): bool
    {
        if ($cnpj !== null && trim($cnpj) !== '') {
            $cleanCnpj = $this->checksum->clean($cnpj);
            if (strlen($cleanCnpj) !== 14) {
                return false;
            }

            $removed = $this->cache->remove($this->getCacheId($cleanCnpj));
            $this->audit('cache_clear_single', $cleanCnpj, ['removed' => $removed ? 1 : 0]);

            return $removed;
        }

        $cleaned = $this->cache->clean([self::CACHE_TAG]);
        $this->audit('cache_clear_all', 'all', ['removed' => $cleaned ? 1 : 0]);

        return $cleaned;
    }

    /**
     * Map lookup payload to the public JSON used by storefront/admin controllers.
     *
     * @param array<string, mixed>|null $apiData
     * @return array<string, mixed>
     */
    public function toHttpResult(?array $apiData): array
    {
        if ($apiData === null) {
            return [
                'success' => false,
                'message' => 'CNPJ não encontrado na Receita Federal.'
            ];
        }

        if (isset($apiData['valid']) && !$apiData['valid']) {
            return [
                'success' => false,
                'message' => (string) ($apiData['message'] ?? 'CNPJ com situação irregular.'),
                'situacao' => $apiData['data']['situacao'] ?? ''
            ];
        }

        if (isset($apiData['api_error']) && $apiData['api_error']) {
            return [
                'success' => true,
                'source' => $apiData['source'] ?? 'fallback',
                'cached' => !empty($apiData['cached']),
                'api_unavailable' => true,
                'message' => (string) ($apiData['message'] ?? 'API indisponível. CNPJ validado localmente.')
            ];
        }

        return [
            'success' => true,
            'source' => $apiData['source'] ?? 'api',
            'cached' => !empty($apiData['cached']),
            'razao_social' => $apiData['razao_social'] ?? '',
            'nome_fantasia' => $apiData['nome_fantasia'] ?? '',
            'situacao' => $apiData['situacao'] ?? '',
            'tipo' => $apiData['tipo'] ?? '',
            'porte' => $apiData['porte'] ?? '',
            'atividade_principal' => $apiData['atividade_principal'] ?? '',
            'logradouro' => $apiData['logradouro'] ?? '',
            'numero' => $apiData['numero'] ?? '',
            'complemento' => $apiData['complemento'] ?? '',
            'bairro' => $apiData['bairro'] ?? '',
            'municipio' => $apiData['municipio'] ?? '',
            'uf' => $apiData['uf'] ?? '',
            'cep' => $apiData['cep'] ?? '',
            'telefone' => $apiData['telefone'] ?? '',
            'email' => $apiData['email'] ?? '',
        ];
    }

    private function isLookupEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOOKUP_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getLookupApiUrl(): string
    {
        $configuredUrl = trim((string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_LOOKUP_API_URL,
            ScopeInterface::SCOPE_STORE
        ));

        return $configuredUrl !== '' ? $configuredUrl : self::DEFAULT_API_URL;
    }

    private function getLookupTimeout(): int
    {
        $timeout = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_LOOKUP_TIMEOUT,
            ScopeInterface::SCOPE_STORE
        );

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    private function isRequireActiveStatusEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOOKUP_REQUIRE_ACTIVE,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isLocalFallbackEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOOKUP_ALLOW_FALLBACK,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isLookupCacheEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_LOOKUP_CACHE_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getLookupCacheTtl(): int
    {
        $ttl = (int) $this->scopeConfig->getValue(
            self::CONFIG_PATH_LOOKUP_CACHE_TTL,
            ScopeInterface::SCOPE_STORE
        );

        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    private function getCacheId(string $cnpj): string
    {
        return self::CACHE_KEY_PREFIX . $cnpj;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFromCache(string $cnpj): ?array
    {
        if (!$this->isLookupCacheEnabled()) {
            return null;
        }

        $cached = $this->cache->load($this->getCacheId($cnpj));
        if (!$cached) {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning(
                sprintf('Cache de CNPJ inválido para %s: %s', $cnpj, $exception->getMessage())
            );

            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $originalSource = trim((string) ($decoded['source'] ?? ''));
        if ($originalSource === '' || $originalSource === 'cache') {
            $originalSource = 'api';
        }
        $decoded['source'] = $originalSource;
        $decoded['cached'] = true;

        if ($this->isPoisonedCachePayload($decoded)) {
            $this->cache->remove($this->getCacheId($cnpj));
            $this->audit('cache_poison_ignored', $cnpj);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function isRateLimitedResponse(int $httpStatus, ?array $data): bool
    {
        if ($httpStatus === 429) {
            return true;
        }

        if ($data === null) {
            return false;
        }

        $message = strtolower((string) ($data['message'] ?? ''));
        if ($message !== '' && str_contains($message, 'too many requests')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isCompleteCompanyPayload(array $data): bool
    {
        if (isset($data['nome']) && trim((string) $data['nome']) !== '') {
            return true;
        }

        if (isset($data['atividade_principal']) && is_array($data['atividade_principal']) && $data['atividade_principal'] !== []) {
            return true;
        }

        if (isset($data['razao_social']) && trim((string) $data['razao_social']) !== '') {
            return true;
        }

        if (isset($data['cnae_fiscal'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isPoisonedCachePayload(array $payload): bool
    {
        if (($payload['valid'] ?? null) === false) {
            return false;
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return false;
        }

        if ($this->isRateLimitedResponse(0, $data)) {
            return true;
        }

        return !$this->isCompleteCompanyPayload($data);
    }

    /**
     * BrasilAPI, then CNPJ.ws. Local checksum fallback only when every public API misses.
     *
     * @return array<string, mixed>|null
     */
    private function resolveWithFallbacks(string $cnpjClean, string $successAuditEvent): ?array
    {
        $payload = $this->fetchFromBrasilApi($cnpjClean);
        if ($payload === null) {
            $payload = $this->fetchFromCnpjWs($cnpjClean);
        }

        if ($payload !== null) {
            $payload = $this->applyActiveStatusRequirement($cnpjClean, $payload);
            if (($payload['valid'] ?? false) === true) {
                $this->saveToCache($cnpjClean, $payload);
                $this->audit($successAuditEvent, $cnpjClean, [
                    'source' => (string) ($payload['source'] ?? ''),
                ]);
            }

            return $payload;
        }

        if ($this->isLocalFallbackEnabled()) {
            $this->audit('api_not_found_local_fallback', $cnpjClean);

            return $this->buildLocalFallbackPayload(
                (string) __('CNPJ válido, mas ainda não consta nas bases públicas da Receita. Preencha os dados manualmente. A conta ficará pendente de aprovação.')
            );
        }

        $this->audit('api_not_found_no_fallback', $cnpjClean);

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyActiveStatusRequirement(string $cnpjClean, array $payload): array
    {
        if (!$this->isRequireActiveStatusEnabled()) {
            return $payload;
        }

        $situacao = strtoupper(trim((string) ($payload['situacao'] ?? '')));
        if ($situacao === '' || $situacao === 'ATIVA') {
            return $payload;
        }

        $invalidPayload = [
            'valid' => false,
            'message' => (string) __('CNPJ com situação: %1', $payload['situacao']),
            'source' => $payload['source'] ?? 'api',
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
        ];

        $this->saveToCache($cnpjClean, $invalidPayload, self::NEGATIVE_CACHE_TTL);
        $this->audit('api_invalid_status', $cnpjClean, [
            'situacao' => (string) $payload['situacao'],
            'source' => (string) ($payload['source'] ?? ''),
        ]);

        return $invalidPayload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromCnpjWs(string $cnpjClean): ?array
    {
        try {
            $status = 0;
            $body = $this->httpGetJson(self::CNPJWS_API_URL . $cnpjClean, $status);
            $data = json_decode($body, true);

            if ($status >= 400 || !is_array($data) || !$this->isCompleteCompanyPayload($data)) {
                $this->audit('cnpjws_unavailable', $cnpjClean, ['http_status' => $status]);
                return null;
            }

            $est = is_array($data['estabelecimento'] ?? null) ? $data['estabelecimento'] : [];
            $atividade = is_array($est['atividade_principal'] ?? null) ? $est['atividade_principal'] : [];
            $cnaeCode = trim((string) ($atividade['id'] ?? $atividade['subclasse'] ?? ''));
            $cnaeText = trim((string) ($atividade['descricao'] ?? ''));
            $situacao = strtoupper(trim((string) ($est['situacao_cadastral'] ?? '')));
            $razao = trim((string) ($data['razao_social'] ?? $data['nome'] ?? ''));
            $logradouro = trim(
                (string) ($est['tipo_logradouro'] ?? '') . ' ' . (string) ($est['logradouro'] ?? '')
            );
            $ddd = preg_replace('/\D/', '', (string) ($est['ddd1'] ?? '')) ?? '';
            $telefone = preg_replace('/\D/', '', (string) ($est['telefone1'] ?? '')) ?? '';
            if ($ddd !== '' && $telefone !== '' && !str_starts_with($telefone, $ddd)) {
                $telefone = $ddd . $telefone;
            }

            $normalized = $data;
            $normalized['nome'] = $razao;
            $normalized['situacao'] = $situacao;
            $normalized['cnae_fiscal'] = preg_replace('/\D/', '', $cnaeCode) ?: $cnaeCode;
            if ($cnaeText !== '' || $cnaeCode !== '') {
                $normalized['atividade_principal'] = [[
                    'code' => $cnaeCode !== '' ? $cnaeCode : $cnaeText,
                    'text' => $cnaeText,
                ]];
            }

            $porte = $data['porte'] ?? '';
            $porteText = is_array($porte) ? (string) ($porte['descricao'] ?? '') : (string) $porte;
            $natureza = $data['natureza_juridica'] ?? '';
            $naturezaText = is_array($natureza) ? (string) ($natureza['descricao'] ?? '') : (string) $natureza;

            return [
                'valid' => true,
                'source' => 'cnpjws',
                'razao_social' => $razao,
                'nome_fantasia' => trim((string) ($est['nome_fantasia'] ?? $data['nome_fantasia'] ?? '')),
                'cnpj' => $cnpjClean,
                'situacao' => $situacao,
                'tipo' => trim((string) ($est['tipo'] ?? '')),
                'porte' => trim($porteText),
                'natureza_juridica' => trim($naturezaText),
                'atividade_principal' => $cnaeText,
                'logradouro' => $logradouro,
                'numero' => trim((string) ($est['numero'] ?? '')),
                'complemento' => trim((string) ($est['complemento'] ?? '')),
                'bairro' => trim((string) ($est['bairro'] ?? '')),
                'municipio' => $this->nestedNameOrSigla($est['cidade'] ?? null, 'nome'),
                'uf' => $this->nestedNameOrSigla($est['estado'] ?? null, 'sigla'),
                'cep' => preg_replace('/\D/', '', (string) ($est['cep'] ?? '')) ?: '',
                'telefone' => $telefone,
                'email' => trim((string) ($est['email'] ?? $data['email'] ?? '')),
                'data' => $normalized,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('CNPJ.ws lookup failed: ' . $e->getMessage());
            $this->audit('cnpjws_exception', $cnpjClean, ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function httpGetJson(string $url, int &$status): string
    {
        $this->curl->setOption(CURLOPT_TIMEOUT, $this->getLookupTimeout());
        $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $this->curl->setHeaders([
            'Accept' => 'application/json',
            'User-Agent' => self::LOOKUP_USER_AGENT,
        ]);
        $this->curl->get($url);
        $status = (int) $this->curl->getStatus();

        return (string) $this->curl->getBody();
    }

    private function nestedNameOrSigla(mixed $node, string $preferredKey): string
    {
        if (is_array($node)) {
            if (isset($node[$preferredKey]) && trim((string) $node[$preferredKey]) !== '') {
                return trim((string) $node[$preferredKey]);
            }
            if (isset($node['nome'])) {
                return trim((string) $node['nome']);
            }
            if (isset($node['sigla'])) {
                return trim((string) $node['sigla']);
            }

            return '';
        }

        return trim((string) $node);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromBrasilApi(string $cnpjClean): ?array
    {
        try {
            $status = 0;
            $body = $this->httpGetJson(self::FALLBACK_API_URL . $cnpjClean, $status);
            $fallbackData = json_decode($body, true);

            if ($status >= 400 || !is_array($fallbackData) || !$this->isCompleteCompanyPayload($fallbackData)) {
                $this->audit('brasilapi_unavailable', $cnpjClean, ['http_status' => $status]);
                return null;
            }

            $situacao = $fallbackData['descricao_situacao_cadastral']
                ?? $fallbackData['situacao_cadastral']
                ?? '';
            if (is_numeric($situacao)) {
                $situacao = ((int) $situacao === 2) ? 'ATIVA' : (string) $situacao;
            }

            $cnaeCode = isset($fallbackData['cnae_fiscal'])
                ? (string) $fallbackData['cnae_fiscal']
                : '';
            $cnaeText = (string) ($fallbackData['cnae_fiscal_descricao'] ?? '');

            if ($cnaeCode !== '' && !isset($fallbackData['atividade_principal'])) {
                $fallbackData['atividade_principal'] = [
                    [
                        'code' => $cnaeCode,
                        'text' => $cnaeText,
                    ],
                ];
            }
            if (!isset($fallbackData['nome']) && isset($fallbackData['razao_social'])) {
                $fallbackData['nome'] = $fallbackData['razao_social'];
            }
            if ($situacao !== '' && !isset($fallbackData['situacao'])) {
                $fallbackData['situacao'] = strtoupper((string) $situacao);
            }

            return [
                'valid' => true,
                'source' => 'brasilapi',
                'razao_social' => (string) ($fallbackData['razao_social'] ?? $fallbackData['nome'] ?? ''),
                'nome_fantasia' => (string) ($fallbackData['nome_fantasia'] ?? $fallbackData['fantasia'] ?? ''),
                'cnpj' => $cnpjClean,
                'situacao' => strtoupper((string) ($fallbackData['situacao'] ?? $situacao)),
                'tipo' => (string) ($fallbackData['descricao_tipo_de_logradouro'] ?? ''),
                'porte' => (string) ($fallbackData['porte'] ?? ''),
                'natureza_juridica' => (string) ($fallbackData['natureza_juridica'] ?? ''),
                'atividade_principal' => $cnaeText,
                'logradouro' => (string) ($fallbackData['logradouro'] ?? ''),
                'numero' => (string) ($fallbackData['numero'] ?? ''),
                'complemento' => (string) ($fallbackData['complemento'] ?? ''),
                'bairro' => (string) ($fallbackData['bairro'] ?? ''),
                'municipio' => (string) ($fallbackData['municipio'] ?? ''),
                'uf' => (string) ($fallbackData['uf'] ?? ''),
                'cep' => (string) ($fallbackData['cep'] ?? ''),
                'telefone' => (string) ($fallbackData['ddd_telefone_1'] ?? ''),
                'email' => (string) ($fallbackData['email'] ?? ''),
                'data' => $fallbackData,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('BrasilAPI full lookup failed: ' . $e->getMessage());
            $this->audit('brasilapi_exception', $cnpjClean, ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveToCache(string $cnpj, array $payload, ?int $ttlOverride = null): void
    {
        if (!$this->isLookupCacheEnabled()) {
            return;
        }

        if ($this->isPoisonedCachePayload($payload)) {
            $this->audit('cache_skip_poison', $cnpj);
            return;
        }

        try {
            $this->cache->save(
                $this->json->serialize($payload),
                $this->getCacheId($cnpj),
                [self::CACHE_TAG],
                $ttlOverride ?? $this->getLookupCacheTtl()
            );
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf('Falha ao salvar cache de CNPJ %s: %s', $cnpj, $exception->getMessage())
            );
        }
    }

    private function crossVerifyWithFallbackApi(string $cnpjClean): ?string
    {
        try {
            $fallbackCurl = clone $this->curl;
            $fallbackCurl->setOption(CURLOPT_TIMEOUT, 5);
            $fallbackCurl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $fallbackCurl->setHeaders(['Accept' => 'application/json']);

            $fallbackCurl->get(self::FALLBACK_API_URL . $cnpjClean);
            $body = (string) $fallbackCurl->getBody();
            $fallbackData = json_decode($body, true);

            if (!is_array($fallbackData)) {
                return null;
            }

            $situacao = $fallbackData['descricao_situacao_cadastral']
                ?? $fallbackData['situacao_cadastral']
                ?? null;

            if ($situacao !== null) {
                if (is_numeric($situacao)) {
                    $situacao = ((int) $situacao === 2) ? 'ATIVA' : 'BAIXADA';
                }
                return strtoupper((string) $situacao);
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('BrasilAPI fallback failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalFallbackPayload(string $message): array
    {
        return [
            'valid' => true,
            'api_error' => true,
            'source' => 'fallback',
            'message' => $message
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $event, string $cnpj, array $context = []): void
    {
        $payload = array_merge(
            [
                'event' => $event,
                'cnpj' => $this->maskForLog($cnpj)
            ],
            $context
        );

        $this->logger->info('[B2B][CNPJ] ' . $this->json->serialize($payload));
    }

    private function maskForLog(string $cnpj): string
    {
        $clean = $this->checksum->clean($cnpj);
        if (strlen($clean) !== 14) {
            return $clean;
        }

        return substr($clean, 0, 2) . '******' . substr($clean, -4);
    }
}
