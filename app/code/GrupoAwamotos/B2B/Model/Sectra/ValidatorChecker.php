<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Sectra;

use GrupoAwamotos\ERPIntegration\Model\B2BClientRegistration;
use Magento\Framework\App\ResourceConnection;

/**
 * Purchase gate: customer must appear in oc_customer_b2b_confirmed (synced from Sectra validador).
 */
class ValidatorChecker
{
    private const OC_CUSTOMER_ID_OFFSET = 200000;

    public function __construct(
        private readonly B2BClientRegistration $b2bClientRegistration,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function resolveSectraChave(int $magentoCustomerId): ?int
    {
        if ($magentoCustomerId <= 0) {
            return null;
        }

        // Prefer definitive ERP code when available; fallback to legacy bridge id.
        $erpCode = $this->getCustomerErpCode($magentoCustomerId);
        if ($erpCode !== null && $erpCode > 0) {
            return $erpCode;
        }

        $connection = $this->resourceConnection->getConnection();
        $chave = $connection->fetchOne(
            'SELECT old_oc_customer_id FROM oc_customer_id_map WHERE magento_customer_id = ?',
            [$magentoCustomerId]
        );

        if ($chave !== false && (int) $chave > 0) {
            return (int) $chave;
        }

        return $magentoCustomerId + self::OC_CUSTOMER_ID_OFFSET;
    }

    /**
     * Fail-closed purchase gate — uses local oc_customer_b2b_confirmed (populated only after ERP validation).
     */
    public function isCustomerValidatedInSectra(int $magentoCustomerId): bool
    {
        $sectraChave = $this->resolveSectraChave($magentoCustomerId);
        if ($sectraChave === null || $sectraChave <= 0) {
            return false;
        }

        $inCadastroValidator = $this->isSectraChaveRegistered($sectraChave);
        if ($inCadastroValidator) {
            $this->ensureConfirmedLocally($sectraChave);
        }
        $inB2bConfirmed = $this->isInB2bConfirmedTable($sectraChave);

        return $inB2bConfirmed && $inCadastroValidator;
    }

    /**
     * Passive ERP poll — read-only check against GR_INTEGRACAOVALIDADOR.
     */
    public function isRegisteredInErpValidator(int $magentoCustomerId): bool
    {
        $sectraChave = $this->resolveSectraChave($magentoCustomerId);
        if ($this->isSectraChaveRegistered($sectraChave)) {
            return true;
        }

        $erpCode = $this->getCustomerErpCode($magentoCustomerId);

        return $erpCode !== null && $this->isErpCodeRegistered($erpCode);
    }

    public function isSectraChaveRegistered(int $sectraChave): bool
    {
        return $this->b2bClientRegistration->isClientReadyForSectraOrderImport($sectraChave);
    }

    public function isErpCodeRegistered(int $erpCode): bool
    {
        if ($erpCode <= 0) {
            return false;
        }

        return $this->b2bClientRegistration->isClientReadyForSectraOrderImport($erpCode);
    }

    public function isInB2bConfirmedTable(int $sectraChave): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $exists = $connection->fetchOne(
            'SELECT customer_id FROM oc_customer_b2b_confirmed WHERE customer_id = ?',
            [$sectraChave]
        );

        return $exists !== false;
    }

    /**
     * Keep the local OpenCart confirmation row in sync when Sectra already
     * has Cadastro de Cliente. The OpenCart bridge cron is parked in production
     * (Feb-30 schedule), so this MySQL upsert is the release path for held orders.
     */
    public function ensureConfirmedLocally(int $sectraChave): void
    {
        if ($sectraChave <= 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName('oc_customer_b2b_confirmed'),
            [
                'customer_id' => $sectraChave,
                'synced_at' => date('Y-m-d H:i:s'),
            ],
            ['synced_at']
        );
    }

    public function getCustomerErpCode(int $magentoCustomerId): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $value = $connection->fetchOne(
            "SELECT erp_attr.value
             FROM customer_entity_varchar erp_attr
             INNER JOIN eav_attribute ea
                 ON ea.attribute_id = erp_attr.attribute_id
                 AND ea.attribute_code = 'erp_code'
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                 AND et.entity_type_code = 'customer'
             WHERE erp_attr.entity_id = ?
               AND erp_attr.value REGEXP '^[0-9]+$'",
            [$magentoCustomerId]
        );

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        $code = (int) $value;

        return $code > 0 ? $code : null;
    }
}
