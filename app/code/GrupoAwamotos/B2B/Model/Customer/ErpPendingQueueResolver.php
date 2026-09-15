<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Customer;

use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;

/**
 * Defines which customers belong in the ERP/Sectra pending admin queue.
 */
class ErpPendingQueueResolver
{
    /** @var list<string> */
    public const ERP_PENDING_STATUSES = [
        ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION,
        ErpCustomerSyncStatus::PENDING_ERP_CREATION,
        ErpCustomerSyncStatus::AWAITING_ERP_VALIDATION,
        ErpCustomerSyncStatus::PROSPECT_MAGENTO,
        ErpCustomerSyncStatus::PROSPECT_SENT_SECTRA,
        'pending_erp_validation',
    ];

    /** @var list<string> */
    public const VALIDATED_STATUSES = [
        ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP,
        ErpCustomerSyncStatus::VALIDATED_IN_ERP,
        ErpCustomerSyncStatus::LINKED_EXISTING,
        ErpCustomerSyncStatus::LINKED_BY_CNPJ,
    ];

    public function resolveBlockReason(
        ?string $erpStatus,
        ?string $approvalStatus,
        bool $isErpConfirmed
    ): string {
        if ($isErpConfirmed) {
            return '';
        }

        if ($approvalStatus !== ApprovalStatus::STATUS_APPROVED) {
            return (string) __('Aguardando aprovação comercial');
        }

        if ($erpStatus === ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION
            || $erpStatus === ErpCustomerSyncStatus::AWAITING_ERP_VALIDATION
            || $erpStatus === 'pending_erp_validation'
        ) {
            return (string) __('Aguardando validação ERP/Sectra');
        }

        if ($erpStatus === ErpCustomerSyncStatus::PROSPECT_MAGENTO
            || $erpStatus === ErpCustomerSyncStatus::PROSPECT_SENT_SECTRA
        ) {
            return (string) __('Prospect enviado — aguardando validação ERP');
        }

        return 'customer_not_validated_in_erp';
    }
}
