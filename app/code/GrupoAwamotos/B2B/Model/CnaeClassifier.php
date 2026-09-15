<?php

/**
 * CNAE Classifier - classifies B2B customers by their economic activity code
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Helper\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class CnaeClassifier
{
    public const PROFILE_DIRECT = 'direct';
    public const PROFILE_ADJACENT = 'adjacent';
    public const PROFILE_OFF = 'off_profile';

    public const XML_PATH_CNAE_ENABLED = 'grupoawamotos_b2b/cnae_profiling/enabled';
    public const XML_PATH_CNAE_DIRECT = 'grupoawamotos_b2b/cnae_profiling/direct_cnaes';
    public const XML_PATH_CNAE_ADJACENT = 'grupoawamotos_b2b/cnae_profiling/adjacent_cnaes';
    public const XML_PATH_CNAE_AUTO_APPROVE_DIRECT = 'grupoawamotos_b2b/cnae_profiling/auto_approve_direct';

    /**
     * Default CNAE codes for motorcycle-related businesses (direct target)
     */
    public const DEFAULT_DIRECT_CNAES = [
        '4541-2/01', // Comércio por atacado de motocicletas e motonetas
        '4541-2/02', // Comércio por atacado de peças e acessórios para motocicletas e motonetas
        '4541-2/03', // Comércio a varejo de motocicletas e motonetas novas
        '4541-2/04', // Comércio a varejo de motocicletas e motonetas usadas
        '4541-2/05', // Comércio a varejo de peças e acessórios para motocicletas e motonetas
        '4541-2/06', // Comércio a varejo de peças e acessórios novos para motocicletas e motonetas
        '4543-9/00', // Manutenção e reparação de motocicletas e motonetas
        '3091-1/00', // Fabricação de motocicletas, peças e acessórios
    ];

    /**
     * Default CNAE codes for adjacent businesses (related but not core)
     */
    public const DEFAULT_ADJACENT_CNAES = [
        '4530-7/01', // Comércio por atacado de peças e acessórios novos para veículos automotores
        '4530-7/02', // Comércio por atacado de pneumáticos e câmaras-de-ar
        '4530-7/03', // Comércio a varejo de peças e acessórios novos para veículos automotores
        '4530-7/04', // Comércio a varejo de peças e acessórios usados para veículos automotores
        '4520-0/01', // Serviços de manutenção e reparação mecânica de veículos automotores
        '4520-0/06', // Serviços de borracharia para veículos automotores
        '4511-1/01', // Comércio a varejo de automóveis, camionetas e utilitários novos
        '4512-9/01', // Representantes comerciais de veículos automotores
        '4520-0/04', // Serviços de alinhamento e balanceamento de veículos automotores
        '4520-0/05', // Serviços de lavagem, lubrificação e polimento de veículos automotores
    ];

    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Check if CNAE profiling is enabled
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CNAE_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if direct profile customers should be auto-approved
     */
    public function isAutoApproveDirectEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CNAE_AUTO_APPROVE_DIRECT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Classify a CNAE code into a profile category
     *
     * @param string $cnaeCode Raw CNAE code from ReceitaWS (e.g. "45.41-2-01" or "4541-2/01")
     * @return string One of: direct, adjacent, off_profile
     */
    public function classify(string $cnaeCode): string
    {
        if (empty($cnaeCode)) {
            return self::PROFILE_OFF;
        }

        $normalized = $this->normalizeCnae($cnaeCode);

        $directCnaes = $this->getDirectCnaes();
        foreach ($directCnaes as $target) {
            if ($this->normalizeCnae($target) === $normalized) {
                return self::PROFILE_DIRECT;
            }
        }

        $adjacentCnaes = $this->getAdjacentCnaes();
        foreach ($adjacentCnaes as $target) {
            if ($this->normalizeCnae($target) === $normalized) {
                return self::PROFILE_ADJACENT;
            }
        }

        // Also check by CNAE group (first 4 digits) for broader matching
        $normalizedGroup = substr($normalized, 0, 4);
        foreach ($directCnaes as $target) {
            if (substr($this->normalizeCnae($target), 0, 4) === $normalizedGroup) {
                return self::PROFILE_DIRECT;
            }
        }

        return self::PROFILE_OFF;
    }

    /**
     * Get profile label in Portuguese
     */
    public function getProfileLabel(string $profile): string
    {
        $labels = [
            self::PROFILE_DIRECT => 'Perfil Direto (Motos)',
            self::PROFILE_ADJACENT => 'Perfil Adjacente (Automotivo)',
            self::PROFILE_OFF => 'Fora do Perfil',
        ];

        return $labels[$profile] ?? $labels[self::PROFILE_OFF];
    }

    /**
     * Extract CNAE code from ReceitaWS raw API response data
     *
     * @param array $apiData The raw 'data' array from CnpjValidator
     * @return string The CNAE code or empty string
     */
    public function extractCnaeCode(array $apiData): string
    {
        // ReceitaWS format: atividade_principal[0]['code']
        if (isset($apiData['atividade_principal'][0]['code'])) {
            return (string) $apiData['atividade_principal'][0]['code'];
        }

        // BrasilAPI format: cnae_fiscal (integer)
        if (isset($apiData['cnae_fiscal'])) {
            return (string) $apiData['cnae_fiscal'];
        }

        return '';
    }

    /**
     * Extract CNAE description from API data
     */
    public function extractCnaeDescription(array $apiData): string
    {
        if (isset($apiData['atividade_principal'][0]['text'])) {
            return (string) $apiData['atividade_principal'][0]['text'];
        }

        if (isset($apiData['cnae_fiscal_descricao'])) {
            return (string) $apiData['cnae_fiscal_descricao'];
        }

        return '';
    }

    /**
     * Get configured direct CNAE codes
     */
    public function getDirectCnaes(): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CNAE_DIRECT,
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return self::DEFAULT_DIRECT_CNAES;
        }

        return $this->parseCnaeList($value);
    }

    /**
     * Get configured adjacent CNAE codes
     */
    public function getAdjacentCnaes(): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CNAE_ADJACENT,
            ScopeInterface::SCOPE_STORE
        );

        if (empty($value)) {
            return self::DEFAULT_ADJACENT_CNAES;
        }

        return $this->parseCnaeList($value);
    }

    /**
     * Normalize CNAE code to digits only for comparison
     * e.g., "45.41-2-01", "4541-2/01", "45412/01" → "4541201"
     */
    private function normalizeCnae(string $cnae): string
    {
        return preg_replace('/\D/', '', $cnae);
    }

    /**
     * Parse comma-separated CNAE list from config
     */
    private function parseCnaeList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        return array_filter($items, function ($item) {
            return $item !== '';
        });
    }
}
