<?php

declare(strict_types=1);

namespace Ayo\Curriculo\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

class Form extends Template
{
    public const XML_PATH_ENABLED = 'ayo_curriculo/general/enabled';
    public const XML_PATH_MAX_FILE_SIZE_MB = 'ayo_curriculo/general/max_file_size_mb';
    private const DEFAULT_MAX_FILE_SIZE_MB = 5;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var DataPersistorInterface
     */
    private $dataPersistor;

    /**
     * @var array|null
     */
    private $postData;

    /**
     * @var array|null
     */
    private $postErrors;

    public function __construct(
        Template\Context $context,
        FormKey $formKey,
        ScopeConfigInterface $scopeConfig,
        DataPersistorInterface $dataPersistor,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->formKey = $formKey;
        $this->scopeConfig = $scopeConfig;
        $this->dataPersistor = $dataPersistor;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getPostUrl(): string
    {
        return $this->getUrl('trabalhe-conosco/index/post');
    }

    public function getFormKeyValue(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getMaxFileSizeMb(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_FILE_SIZE_MB, ScopeInterface::SCOPE_STORE);
        if ($value <= 0) {
            return self::DEFAULT_MAX_FILE_SIZE_MB;
        }
        return $value;
    }

    public function getAllowedExtensionsLabel(): string
    {
        return 'PDF, DOC, DOCX';
    }

    /**
     * @return array<int, string>
     */
    public function getWorkAreas(): array
    {
        return [
            'Vendas / Comercial',
            'Estoque / Logística',
            'Atendimento ao Cliente',
            'Marketing / E-commerce',
            'Financeiro / Administrativo',
            'TI / Tecnologia',
            'Mecânica / Oficina',
            'Compras',
            'RH / Gestão de Pessoas',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getSpecialties(): array
    {
        return [
            'Peças de Motos',
            'Acessórios e Equipamentos',
            'Mecânica de Motos',
            'Atendimento B2B / Atacado',
            'E-commerce / Marketplace',
            'Logística / Expedição',
            'Marketing Digital',
            'Gestão de Equipes',
            'Sistemas ERP / Magento',
            'Vendas Externas',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getExperienceLevels(): array
    {
        return ['Estágio', 'Júnior', 'Pleno', 'Sênior', 'Liderança'];
    }

    /**
     * @return array<int, string>
     */
    public function getCnhOptions(): array
    {
        return ['Não possuo', 'A', 'B', 'AB', 'C', 'D', 'E', 'Outra'];
    }

    /**
     * @return array<int, string>
     */
    public function getAvailabilityOptions(): array
    {
        return ['Imediata', '15 dias', '30 dias', 'A combinar'];
    }

    /**
     * @return array<int, string>
     */
    public function getContractTypes(): array
    {
        return ['CLT', 'PJ', 'Estágio', 'Temporário', 'Ambos (CLT ou PJ)'];
    }

    /**
     * @return array<int, string>
     */
    public function getReferralSources(): array
    {
        return ['Site AWA Motos', 'Redes Sociais', 'Indicação', 'LinkedIn', 'Indeed / Vagas.com', 'Google', 'Outro'];
    }

    /**
     * Returns all 27 Brazilian states as ['UF' => 'Nome'].
     *
     * @return array<string, string>
     */
    public function getBrazilianStates(): array
    {
        return [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
        ];
    }

    public function getStatusPageUrl(): string
    {
        return $this->getUrl('trabalhe-conosco/index/status');
    }

    public function isSuccess(): bool
    {
        return (bool)$this->getRequest()->getParam('success');
    }

    public function getPostValue(string $key): string
    {
        if ($this->postData === null) {
            $this->postData = (array)$this->dataPersistor->get('ayo_curriculo');
            $this->dataPersistor->clear('ayo_curriculo');
        }

        if (!isset($this->postData[$key])) {
            return '';
        }

        $value = $this->postData[$key];

        // Campos multiplos (ex.: name="specialties[]") chegam como array.
        // O cast direto (string) gerava "Array to string conversion" -> CRITICAL no log.
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn($item): string => is_scalar($item) ? (string)$item : '',
                $value
            ));
        }

        return is_scalar($value) ? (string)$value : '';
    }

    public function isPostValueChecked(string $key): bool
    {
        $value = $this->getPostValue($key);
        if ($value === '') {
            return false;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes'], true);
    }

    public function getFieldError(string $key): string
    {
        if ($this->postErrors === null) {
            $this->postErrors = (array)$this->dataPersistor->get('ayo_curriculo_errors');
            $this->dataPersistor->clear('ayo_curriculo_errors');
        }

        return isset($this->postErrors[$key]) ? (string)$this->postErrors[$key] : '';
    }

    /**
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        if ($this->postErrors === null) {
            $this->postErrors = (array)$this->dataPersistor->get('ayo_curriculo_errors');
            $this->dataPersistor->clear('ayo_curriculo_errors');
        }

        return $this->postErrors;
    }

    public function hasFieldError(string $key): bool
    {
        return $this->getFieldError($key) !== '';
    }
}
