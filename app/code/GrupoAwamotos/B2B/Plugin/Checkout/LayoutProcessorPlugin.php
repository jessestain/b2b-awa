<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\Helper\Config;
use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Ajusta o jsLayout do checkout para o fluxo B2B.
 */
class LayoutProcessorPlugin
{
    private const HIDDEN_B2B_ADDRESS_FIELDS = [
        'person_type',
        'b2b_person_type',
        'cpf',
        'rg',
        'taxvat',
        'vat_id',
        'b2b_cnpj',
        'b2b_razao_social',
        'company',
        'company_name',
        'trade_name',
        'b2b_nome_fantasia',
        'ie',
        'b2b_inscricao_estadual',
        'inscricao_municipal',
        'municipal_registration',
        'telefone_comercial',
        'commercial_phone',
        'b2b_phone',
        'b2b_transportadora',
        'transportadora_b2b',
        'whatsapp_optin',
        'whatsapp_opt_in',
        'whatsapp_lgpd',
        'country_id',
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        if (!$this->config->isEnabled()) {
            return $jsLayout;
        }

        $this->simplifyAddressFieldsets($jsLayout);

        if ($this->config->isDeliveryDateEnabled()) {
            return $jsLayout;
        }

        // IMPORTANTE: validar a existência de CADA nó sem usar referência (=&),
        // que provocaria auto-vivification de nós-fantasma e quebraria o render do KO.
        if (!isset(
            $jsLayout['components']['checkout']['children']['steps']['children']
                ['shipping-step']['children']['shippingAddress']['children']
                ['before-shipping-method-form']['children']
        ) || !is_array(
            $jsLayout['components']['checkout']['children']['steps']['children']
                ['shipping-step']['children']['shippingAddress']['children']
                ['before-shipping-method-form']['children']
        )
        ) {
            return $jsLayout;
        }

        unset(
            $jsLayout['components']['checkout']['children']['steps']['children']
                ['shipping-step']['children']['shippingAddress']['children']
                ['before-shipping-method-form']['children']['rokanthemes_opc_shipping_delivery_date'],
            $jsLayout['components']['checkout']['children']['steps']['children']
                ['shipping-step']['children']['shippingAddress']['children']
                ['before-shipping-method-form']['children']['rokanthemes_opc_shipping_delivery_comment']
        );

        return $jsLayout;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function simplifyAddressFieldsets(array &$node): void
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }

        if ($this->looksLikeAddressFieldset($node['children'])) {
            $this->applyB2BAddressFieldRules($node['children']);
        }

        foreach ($node['children'] as &$child) {
            if (is_array($child)) {
                $this->simplifyAddressFieldsets($child);
            }
        }
        unset($child);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function looksLikeAddressFieldset(array $fields): bool
    {
        return isset($fields['street'], $fields['postcode'], $fields['city'])
            && (isset($fields['firstname']) || isset($fields['lastname']));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyB2BAddressFieldRules(array &$fields): void
    {
        $this->setField($fields, 'firstname', (string) __('Nome do contato'), 10, null, true);
        $this->setField($fields, 'lastname', (string) __('Empresa / razão social'), 20, null, true);
        $this->setField($fields, 'cnpj', (string) __('CNPJ'), 30, '00.000.000/0000-00', false);
        $this->setField($fields, 'postcode', (string) __('CEP'), 40, '00000-000', true);
        $this->setField($fields, 'city', (string) __('Cidade'), 80, null, true);
        $this->setField($fields, 'region_id', (string) __('Estado'), 90, null, true);
        $this->setField($fields, 'region', (string) __('Estado'), 90, null, true);
        $this->setField($fields, 'telephone', (string) __('Telefone do contato'), 100, '(00) 00000-0000', true);

        foreach (self::HIDDEN_B2B_ADDRESS_FIELDS as $fieldCode) {
            if (isset($fields[$fieldCode]) && is_array($fields[$fieldCode])) {
                $this->hideField($fields[$fieldCode], $fieldCode);
            }
        }

        if (isset($fields['cnpj']) && is_array($fields['cnpj'])) {
            $fields['cnpj']['visible'] = true;
        }

        if (isset($fields['person_type']) && is_array($fields['person_type'])) {
            $fields['person_type']['default'] = 'pj';
            $fields['person_type']['value'] = 'pj';
        }

        if (isset($fields['country_id']) && is_array($fields['country_id'])) {
            $fields['country_id']['default'] = 'BR';
            $fields['country_id']['value'] = 'BR';
        }

        $this->configureStreetFields($fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function configureStreetFields(array &$fields): void
    {
        if (!isset($fields['street']) || !is_array($fields['street'])) {
            return;
        }

        $fields['street']['label'] = (string) __('Endereço');
        $fields['street']['sortOrder'] = 50;
        $this->appendAdditionalClass($fields['street'], 'awa-b2b-address-street');

        if (!isset($fields['street']['children']) || !is_array($fields['street']['children'])) {
            return;
        }

        $streetLabels = [
            0 => [(string) __('Rua / avenida'), 50, true],
            1 => [(string) __('Número'), 60, true],
            2 => [(string) __('Complemento'), 70, false],
            3 => [(string) __('Bairro'), 75, false],
        ];

        foreach ($streetLabels as $index => [$label, $sortOrder, $required]) {
            $key = (string) $index;
            if (!isset($fields['street']['children'][$key]) || !is_array($fields['street']['children'][$key])) {
                continue;
            }

            $fields['street']['children'][$key]['label'] = $label;
            $fields['street']['children'][$key]['sortOrder'] = $sortOrder;
            $fields['street']['children'][$key]['placeholder'] = '';
            $this->setRequired($fields['street']['children'][$key], $required);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function setField(
        array &$fields,
        string $fieldCode,
        string $label,
        int $sortOrder,
        ?string $placeholder = null,
        ?bool $required = null
    ): void {
        if (!isset($fields[$fieldCode]) || !is_array($fields[$fieldCode])) {
            return;
        }

        $fields[$fieldCode]['label'] = $label;
        $fields[$fieldCode]['sortOrder'] = $sortOrder;
        $this->appendAdditionalClass($fields[$fieldCode], 'awa-b2b-address-field awa-b2b-address-field--' . $fieldCode);

        if ($placeholder !== null) {
            $fields[$fieldCode]['placeholder'] = $placeholder;
        }

        if ($required !== null) {
            $this->setRequired($fields[$fieldCode], $required);
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function hideField(array &$field, string $fieldCode): void
    {
        $field['visible'] = false;
        $this->setRequired($field, false);
        $this->appendAdditionalClass($field, 'awa-b2b-address-field--hidden awa-b2b-address-field--hidden-' . $fieldCode);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function setRequired(array &$field, bool $required): void
    {
        if (!isset($field['validation']) || !is_array($field['validation'])) {
            $field['validation'] = [];
        }

        if ($required) {
            $field['validation']['required-entry'] = true;
            return;
        }

        unset(
            $field['validation']['required-entry'],
            $field['validation']['min_text_length']
        );
    }

    /**
     * @param array<string, mixed> $field
     */
    private function appendAdditionalClass(array &$field, string $className): void
    {
        if (!isset($field['config']) || !is_array($field['config'])) {
            $field['config'] = [];
        }

        $current = trim((string) ($field['config']['additionalClasses'] ?? ''));
        if ($current !== '' && str_contains(' ' . $current . ' ', ' ' . $className . ' ')) {
            return;
        }

        $field['config']['additionalClasses'] = trim($current . ' ' . $className);
    }
}
