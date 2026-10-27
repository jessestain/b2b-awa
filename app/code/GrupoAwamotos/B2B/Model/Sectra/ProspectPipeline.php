<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Sectra;

use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;
use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\B2B\Model\CustomerCnpjResolver;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Passive B2B prospect registration in Magento bridge tables (no Sectra write/import).
 */
class ProspectPipeline
{
    private const OC_CUSTOMER_ID_OFFSET = 200000;
    /** Keep the cron short; each validator lookup can take close to one second. */
    private const POLL_BATCH_SIZE = 10;
    private const POLL_COOLDOWN_HOURS = 24;

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerCnpjResolver $cnpjResolver,
        private readonly ValidatorChecker $validatorChecker,
        private readonly SectraSyncLogger $syncLogger,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     customer_id: int,
     *     sectra_chave: int|null,
     *     cnpj: string|null,
     *     erp_customer_sync_status: string,
     *     message: string
     * }
     */
    public function processApprovedCustomer(int $customerId): array
    {
        $result = [
            'success' => false,
            'customer_id' => $customerId,
            'sectra_chave' => null,
            'cnpj' => null,
            'erp_customer_sync_status' => '',
            'message' => '',
        ];

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Exception $e) {
            $result['message'] = 'Cliente não encontrado.';
            return $result;
        }

        $statusAttr = $customer->getCustomAttribute('b2b_approval_status');
        if ($statusAttr === null || (string) $statusAttr->getValue() !== ApprovalStatus::STATUS_APPROVED) {
            $result['message'] = 'Cliente não está aprovado comercialmente.';
            return $result;
        }

        if (!B2bGroupIds::contains($this->resourceConnection, (int) $customer->getGroupId())) {
            $result['message'] = 'Cliente não pertence a grupo B2B.';
            return $result;
        }

        $resolved = $this->cnpjResolver->resolveWithSource($customer);
        if ($resolved === null) {
            $result['message'] = 'CNPJ ausente — prospect não enviado ao Sectra.';
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::PROSPECT_MAGENTO);
            $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::PROSPECT_MAGENTO;
            $result['success'] = true;
            return $result;
        }

        $cnpj = $resolved['digits'];
        $result['cnpj'] = $cnpj;

        if (!$this->cnpjResolver->isValidCnpj($cnpj)) {
            $result['message'] = 'CNPJ inválido — corrija antes de enviar ao Sectra.';
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::PROSPECT_MAGENTO);
            $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::PROSPECT_MAGENTO;
            $result['success'] = true;
            return $result;
        }

        $duplicateId = $this->findMagentoCustomerByCnpj($cnpj, $customerId);
        if ($duplicateId !== null) {
            $result['message'] = sprintf(
                'CNPJ já vinculado ao cliente Magento #%d — evitando duplicidade.',
                $duplicateId
            );
            $this->syncLogger->log(
                ProspectEvent::CUSTOMER_VALIDATION_PENDING,
                $result['message'],
                $customerId,
                null,
                $cnpj,
                null,
                'error'
            );
            return $result;
        }

        $this->ensureCustomerIdMap($customerId, $cnpj);
        $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
        $result['sectra_chave'] = $sectraChave;

        $this->syncLogger->log(
            ProspectEvent::CUSTOMER_CREATED_MAGENTO,
            sprintf('Cliente B2B #%d mapeado como prospect (CHAVE Sectra %d).', $customerId, $sectraChave),
            $customerId,
            null,
            $cnpj,
            $sectraChave
        );

        if ($this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP);
            $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP;
            $result['message'] = 'Cliente validado no ERP — checkout liberado.';
            $result['success'] = true;

            $this->syncLogger->log(
                ProspectEvent::CUSTOMER_VALIDATOR_ACCEPTED,
                'Cliente confirmado em oc_customer_b2b_confirmed.',
                $customerId,
                null,
                $cnpj,
                $sectraChave,
                'success'
            );

            return $result;
        }

        if ($this->validatorChecker->isRegisteredInErpValidator($customerId)) {
            $result['message'] = 'Cliente no validador ERP — aguardando sincronização do bridge Magento.';
            $result['success'] = true;
            return $result;
        }

        $preRegSynced = $this->syncCustomerToPreRegistration($customerId);
        if ($preRegSynced) {
            $this->syncLogger->log(
                ProspectEvent::CUSTOMER_SENT_SECTRA,
                'Prospect registrado em oc_pre_registration (aguardando validação passiva no ERP).',
                $customerId,
                null,
                $cnpj,
                $sectraChave
            );
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION);
        } else {
            // task-23: NAO gravar 'aguardando ERP' quando o feed NAO foi escrito (o sistema mentia).
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::PROSPECT_FEED_FAILED);
            $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::PROSPECT_FEED_FAILED;
            $result['message'] = 'Falha ao registrar o prospect no feed do ERP - requer acao operacional.';
            $result['success'] = false;
            $this->syncLogger->log(
                ProspectEvent::CUSTOMER_SENT_SECTRA,
                'FALHA: prospect NAO foi escrito em oc_pre_registration (feed do ERP).',
                $customerId,
                null,
                $cnpj,
                $sectraChave,
                'error'
            );
            return $result;
        }

        $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION;
        $result['message'] = 'Cadastro B2B aguardando validação no ERP. Checkout bloqueado até confirmação.';
        $result['success'] = true;

        $this->syncLogger->log(
            ProspectEvent::CUSTOMER_VALIDATION_PENDING,
            $result['message'],
            $customerId,
            null,
            $cnpj,
            $sectraChave
        );

        return $result;
    }

    /**
     * Poll Sectra validador for customers awaiting validation.
     *
     * @return array{validated: int, still_pending: int}
     */
    public function pollPendingValidations(): array
    {
        $counts = ['validated' => 0, 'still_pending' => 0];
        $customerIds = $this->getCustomersAwaitingValidation(self::POLL_BATCH_SIZE);

        foreach ($customerIds as $customerId) {
            $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
            $registered = $this->validatorChecker->isRegisteredInErpValidator($customerId);
            $confirmed = $sectraChave !== null && $this->validatorChecker->isInB2bConfirmedTable($sectraChave);

            if ($registered && $confirmed) {
                try {
                    $customer = $this->customerRepository->getById($customerId);
                } catch (\Exception) {
                    $this->markPollAttempt($customerId, $sectraChave, 'missing_customer');
                    $counts['still_pending']++;
                    continue;
                }

                $cnpj = $this->cnpjResolver->resolveDigits($customer);
                $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP);
                $this->syncLogger->log(
                    ProspectEvent::CUSTOMER_CONFIRMED_BY_ERP_POLL,
                    'Validação confirmada localmente — oc_customer_b2b_confirmed.',
                    $customerId,
                    null,
                    $cnpj,
                    $sectraChave,
                    'success'
                );
                $this->clearPollState($customerId);
                $counts['validated']++;
                continue;
            }

            $this->markPollAttempt(
                $customerId,
                $sectraChave,
                $registered ? 'registered_pending_bridge' : 'not_registered'
            );
            $counts['still_pending']++;
        }

        if ($counts['validated'] > 0 || $counts['still_pending'] > 0) {
            $this->logger->info('[B2B-Sectra] Poll batch concluído', [
                'batch_size' => count($customerIds),
                'validated' => $counts['validated'],
                'still_pending' => $counts['still_pending'],
            ]);
        }

        return $counts;
    }

    /**
     * @return int[]
     */
    private function getCustomersAwaitingValidation(int $limit = self::POLL_BATCH_SIZE): array
    {
        $connection = $this->resourceConnection->getConnection();
        $statusAttrId = $this->resolveCustomerAttributeId('erp_customer_sync_status');

        if (!$statusAttrId) {
            return [];
        }

        $batchLimit = max(1, min(200, $limit));
        $cooldownHours = max(1, self::POLL_COOLDOWN_HOURS);
        $pollTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_sectra_poll_state');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $customerTable = $this->resourceConnection->getTableName('customer_entity');
        $customerVarcharTable = $this->resourceConnection->getTableName('customer_entity_varchar');
        $hasHeldOrderSql = "EXISTS (
            SELECT 1
            FROM {$orderTable} so
            WHERE so.customer_id = ce.entity_id
              AND so.state NOT IN ('canceled', 'closed', 'complete')
              AND (so.sectra_import_status IS NULL OR so.sectra_import_status IN (?, ?))
        )";

        return array_map(
            'intval',
            $connection->fetchCol(
                "SELECT ce.entity_id
                 FROM {$customerTable} ce
                 INNER JOIN {$customerVarcharTable} cev ON cev.entity_id = ce.entity_id
                 LEFT JOIN {$pollTable} ps ON ps.customer_id = ce.entity_id
                 WHERE cev.attribute_id = ?
                   AND cev.value IN (?, ?)
                   AND (
                       ps.last_checked_at IS NULL
                       OR ps.last_checked_at < DATE_SUB(NOW(), INTERVAL {$cooldownHours} HOUR)
                       OR {$hasHeldOrderSql}
                   )
                 ORDER BY
                   CASE WHEN {$hasHeldOrderSql} THEN 0 ELSE 1 END ASC,
                   CASE WHEN ps.last_checked_at IS NULL THEN 0 ELSE 1 END ASC,
                   ps.last_checked_at ASC,
                   ce.entity_id DESC
                 LIMIT " . $batchLimit,
                [
                    $statusAttrId,
                    ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION,
                    ErpCustomerSyncStatus::AWAITING_ERP_VALIDATION,
                    SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                    SectraImportStatus::ORDER_BLOCKED_CUSTOMER_NOT_VALIDATED,
                    SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                    SectraImportStatus::ORDER_BLOCKED_CUSTOMER_NOT_VALIDATED,
                ]
            )
        );
    }

    private function markPollAttempt(int $customerId, ?int $sectraChave, string $result): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName('grupoawamotos_b2b_sectra_poll_state'),
            [
                'customer_id' => $customerId,
                'sectra_chave' => $sectraChave,
                'last_result' => $result,
                'attempts' => 1,
                'last_checked_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
                'updated_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
            ],
            [
                'sectra_chave',
                'last_result',
                'last_checked_at',
                'updated_at',
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
            ]
        );
    }

    private function clearPollState(int $customerId): void
    {
        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName('grupoawamotos_b2b_sectra_poll_state'),
            ['customer_id = ?' => $customerId]
        );
    }

    /**
     * Resolves a customer EAV attribute_id by its attribute_code.
     *
     * attribute_id values are NOT stable across environments/reinstalls, so
     * they must never be hardcoded — resolve them by code instead.
     */
    private function resolveCustomerAttributeId(string $attributeCode): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $connection->fetchOne(
            'SELECT attribute_id FROM eav_attribute ea
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = ?
               AND et.entity_type_code = ?',
            [$attributeCode, 'customer']
        );

        return $attributeId !== false ? (int) $attributeId : null;
    }

    private function ensureCustomerIdMap(int $customerId, string $cnpjDigits): void
    {
        $connection = $this->resourceConnection->getConnection();
        $existing = $connection->fetchOne(
            'SELECT old_oc_customer_id FROM oc_customer_id_map WHERE magento_customer_id = ?',
            [$customerId]
        );

        if ($existing !== false) {
            $connection->update(
                'oc_customer_id_map',
                ['old_cnpj' => $cnpjDigits],
                ['magento_customer_id = ?' => $customerId]
            );
            return;
        }

        $connection->insert(
            'oc_customer_id_map',
            [
                'old_oc_customer_id' => $customerId + self::OC_CUSTOMER_ID_OFFSET,
                'old_email' => '',
                'old_cnpj' => $cnpjDigits,
                'magento_customer_id' => $customerId,
            ]
        );
    }

    private function syncCustomerToPreRegistration(int $customerId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $attrCnpj = $this->resolveCustomerAttributeId('b2b_cnpj');
        $attrErpCode = $this->resolveCustomerAttributeId('erp_code');

        if (!$attrCnpj || !$attrErpCode) {
            $this->logger->error(
                '[B2B-Sectra] Atributos b2b_cnpj/erp_code não encontrados em eav_attribute — ' .
                'sincronização com oc_pre_registration abortada.'
            );
            return false;
        }

        $sql = "
            INSERT INTO oc_pre_registration (
                customer_id, customer_group_id, store_id, language_id,
                firstname, lastname, email, telephone, fax,
                password, salt, cart, wishlist, newsletter, address_id,
                custom_field, ip, status, safe, token, code, date_added
            )
            SELECT
                COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id) AS customer_id,
                2 AS customer_group_id,
                ce.store_id,
                2 AS language_id,
                COALESCE(ce.firstname, '') AS firstname,
                COALESCE(ce.lastname, '') AS lastname,
                COALESCE(ce.email, '') AS email,
                COALESCE(
                    REPLACE(REPLACE(REPLACE(REPLACE(ca.telephone,'(',''),')',''),'-',''),' ',''),
                    ''
                ) AS telephone,
                '' AS fax, '' AS password, '' AS salt,
                NULL AS cart, NULL AS wishlist, 0 AS newsletter,
                COALESCE(first_addr.entity_id, 0) AS address_id,
                REPLACE(REPLACE(REPLACE(REPLACE(
                    COALESCE(
                        (SELECT value FROM customer_entity_varchar
                         WHERE entity_id = ce.entity_id AND attribute_id = :attr_cnpj LIMIT 1),
                        ce.taxvat, ''
                    ), '.',''),'/',''),'-',''),' ','') AS custom_field,
                '' AS ip, 1 AS status, 0 AS safe, '' AS token, '' AS code,
                ce.created_at AS date_added
            FROM oc_customer_id_map map
            INNER JOIN customer_entity ce ON ce.entity_id = map.magento_customer_id
            LEFT JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = ce.entity_id
                AND erp_attr.attribute_id = :attr_erp_code
                AND erp_attr.value REGEXP '^[0-9]+$'
            LEFT JOIN (
                SELECT parent_id, MIN(entity_id) AS entity_id
                FROM customer_address_entity GROUP BY parent_id
            ) first_addr ON first_addr.parent_id = ce.entity_id
            LEFT JOIN customer_address_entity ca ON ca.entity_id = first_addr.entity_id
            WHERE map.magento_customer_id = :customer_id
            ON DUPLICATE KEY UPDATE
                firstname = VALUES(firstname),
                lastname = VALUES(lastname),
                email = VALUES(email),
                telephone = VALUES(telephone),
                custom_field = VALUES(custom_field),
                address_id = VALUES(address_id)
        ";

        try {
            $stmt = $connection->query($sql, [
                'attr_cnpj' => $attrCnpj,
                'attr_erp_code' => $attrErpCode,
                'customer_id' => $customerId,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('[B2B-Sectra] Falha ao sincronizar oc_pre_registration: ' . $e->getMessage());
            return false;
        }

        $rowCount = (is_object($stmt) && method_exists($stmt, 'rowCount')) ? (int) $stmt->rowCount() : -1;
        $sectraChave = $this->validatorChecker->resolveSectraChave($customerId);
        $exists = $connection->fetchOne(
            'SELECT customer_id FROM oc_pre_registration WHERE customer_id = ?',
            [$sectraChave]
        );

        if ($exists === false) {
            // task-23: o insert afetou 0 linhas e a falha era silenciosa. NAO finge resolver a causa raiz:
            // torna a falha visivel e diagnosticavel (o motivo do 0-linha segue NAO PROVADO).
            $this->logger->error('[B2B-Sectra] oc_pre_registration NAO materializou', [
                'customer_id' => $customerId,
                'sectra_chave' => $sectraChave,
                'row_count_insert' => $rowCount,
                'tem_linha_no_map' => (bool) $connection->fetchOne(
                    'SELECT 1 FROM oc_customer_id_map WHERE magento_customer_id = ? LIMIT 1',
                    [$customerId]
                ),
                'attr_cnpj_id' => $attrCnpj,
                'attr_erp_code_id' => $attrErpCode,
            ]);
            return false;
        }

        return true;
    }

    private function findMagentoCustomerByCnpj(string $cnpjDigits, int $excludeCustomerId): ?int
    {
        $connection = $this->resourceConnection->getConnection();

        $fromMap = $connection->fetchOne(
            'SELECT magento_customer_id FROM oc_customer_id_map
             WHERE old_cnpj = ? AND magento_customer_id != ? LIMIT 1',
            [$cnpjDigits, $excludeCustomerId]
        );
        if ($fromMap !== false) {
            return (int) $fromMap;
        }

        $b2bAttrId = $connection->fetchOne(
            "SELECT attribute_id FROM eav_attribute ea
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = 'b2b_cnpj' AND et.entity_type_code = 'customer'"
        );
        if ($b2bAttrId) {
            $fromEav = $connection->fetchOne(
                'SELECT entity_id FROM customer_entity_varchar
                 WHERE attribute_id = ? AND REPLACE(REPLACE(REPLACE(value, ".", ""), "/", ""), "-", "") = ?
                   AND entity_id != ? LIMIT 1',
                [$b2bAttrId, $cnpjDigits, $excludeCustomerId]
            );
            if ($fromEav !== false) {
                return (int) $fromEav;
            }
        }

        return null;
    }

    private function setCustomerSyncStatus(CustomerInterface $customer, string $status): void
    {
        try {
            $customer->setCustomAttribute('erp_customer_sync_status', $status);
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[B2B-Sectra] Customer #%d: falha ao gravar erp_customer_sync_status — %s',
                $customer->getId(),
                $e->getMessage()
            ));
        }
    }
}
