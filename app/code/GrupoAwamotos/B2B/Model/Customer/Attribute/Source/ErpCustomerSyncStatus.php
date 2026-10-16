<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Customer\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * ERP customer sync status — approval is commercial; ERP links via order pull when applicable.
 */
class ErpCustomerSyncStatus extends AbstractSource
{
    public const NOT_APPLICABLE_PULL_ORDER = 'not_applicable_pull_order';
    public const LINKED_EXISTING = 'linked_existing';
    public const LINKED_BY_CNPJ = 'linked_by_cnpj';
    public const PENDING_ERP_CREATION = 'pending_erp_creation';
    public const PROSPECT_MAGENTO = 'prospect_magento';
    public const PROSPECT_SENT_SECTRA = 'prospect_sent_sectra';
    public const AWAITING_ERP_VALIDATION = 'awaiting_erp_validation';
    public const VALIDATED_IN_ERP = 'validated_in_erp';
    public const CUSTOMER_PENDING_ERP_VALIDATION = 'customer_pending_erp_validation';
    public const CUSTOMER_VALIDATED_IN_ERP = 'customer_validated_in_erp';
    public const PROSPECT_FEED_FAILED = 'prospect_feed_failed';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => '', 'label' => __('—')],
                [
                    'value' => self::NOT_APPLICABLE_PULL_ORDER,
                    'label' => __('Aguardando pedido — integração via Sectra'),
                ],
                [
                    'value' => self::LINKED_EXISTING,
                    'label' => __('Vinculado (ERP existente)'),
                ],
                [
                    'value' => self::LINKED_BY_CNPJ,
                    'label' => __('Vinculado por CNPJ no ERP'),
                ],
                [
                    'value' => self::PENDING_ERP_CREATION,
                    'label' => __('Pendente (legado — não usar)'),
                ],
                [
                    'value' => self::PROSPECT_MAGENTO,
                    'label' => __('Prospect criado no Magento'),
                ],
                [
                    'value' => self::PROSPECT_SENT_SECTRA,
                    'label' => __('Prospect enviado ao Sectra'),
                ],
                [
                    'value' => self::PROSPECT_FEED_FAILED,
                    'label' => __('Falha ao gravar o prospect no feed do ERP'),
                ],
                [
                    'value' => self::AWAITING_ERP_VALIDATION,
                    'label' => __('Aguardando validação ERP'),
                ],
                [
                    'value' => self::VALIDATED_IN_ERP,
                    'label' => __('Validado no Sectra'),
                ],
                [
                    'value' => self::CUSTOMER_PENDING_ERP_VALIDATION,
                    'label' => __('Pendente validação ERP'),
                ],
                [
                    'value' => self::CUSTOMER_VALIDATED_IN_ERP,
                    'label' => __('Cliente validado no ERP'),
                ],
            ];
        }

        return $this->_options;
    }
}
