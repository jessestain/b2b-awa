<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Registration;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;
use GrupoAwamotos\B2B\Model\Sectra\ValidatorChecker;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class RegistrationBackfillService
{
    private const LOG_FILE = '/var/log/b2b_registration_backfill.log';

    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerFactory $customerFactory,
        private readonly CustomerResource $customerResource,
        private readonly CustomerRegistry $customerRegistry,
        private readonly AttendantManager $attendantManager,
        private readonly ValidatorChecker $validatorChecker,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     analyzed: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     no_cnpj: int,
     *     no_origin: int,
     *     no_attendant: int,
     *     no_phone: int,
     *     no_razao_social: int,
     *     at_risk_validated: int,
     *     fields_changed: array<string, int>,
     *     details: list<string>,
     *     errors: list<string>,
     *     snapshot: array<string, int>
     * }
     */
    public function execute(
        bool $apply = false,
        bool $force = false,
        ?int $limit = null,
        ?int $customerId = null,
        ?int $fromId = null,
        ?int $toId = null
    ): array {
        $report = [
            'analyzed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'no_cnpj' => 0,
            'no_origin' => 0,
            'no_attendant' => 0,
            'no_phone' => 0,
            'no_razao_social' => 0,
            'at_risk_validated' => 0,
            'fields_changed' => [],
            'details' => [],
            'errors' => [],
            'snapshot' => [],
        ];

        $collection = $this->buildPendingCollection($customerId, $fromId, $toId, $limit);

        foreach ($collection as $customer) {
            $report['analyzed']++;
            $customerId = (int) $customer->getId();
            $email = (string) $customer->getEmail();
            $changes = $this->buildChanges($customer, $force);

            if (trim((string) $customer->getData('b2b_phone')) === '') {
                $report['no_phone']++;
            }
            if (trim((string) $customer->getData('b2b_razao_social')) === '') {
                $report['no_razao_social']++;
            }

            if ($this->validatorChecker->isCustomerValidatedInSectra($customerId)
                && (trim((string) $customer->getData('b2b_phone')) === ''
                    || trim((string) $customer->getData('b2b_razao_social')) === '')
            ) {
                $report['at_risk_validated']++;
            }

            if (trim((string) $customer->getData('b2b_cnpj')) === '' && !isset($changes['b2b_cnpj'])) {
                $report['no_cnpj']++;
            }

            $effectiveOrigin = trim((string) ($changes['b2b_origin_host'] ?? $customer->getData('b2b_origin_host')));
            $effectiveCampaign = trim((string) ($changes['b2b_registration_campaign'] ?? $customer->getData('b2b_registration_campaign')));
            $effectiveUtmSource = trim((string) $customer->getData('b2b_utm_source'));
            if ($effectiveOrigin === '' && $effectiveCampaign === '' && $effectiveUtmSource === '') {
                $report['no_origin']++;
            }

            $attendantAssigned = $this->ensureAttendant($customerId, $apply);
            if (!$attendantAssigned && $this->attendantManager->getCustomerAttendant($customerId) === null) {
                $report['no_attendant']++;
            }

            if ($changes === []) {
                $report['skipped']++;
                continue;
            }

            foreach (array_keys($changes) as $field) {
                $report['fields_changed'][$field] = ($report['fields_changed'][$field] ?? 0) + 1;
            }

            $detail = sprintf(
                'customer #%d (%s): %s',
                $customerId,
                $email,
                implode(', ', array_keys($changes))
            );
            $report['details'][] = $detail;
            $this->log($apply ? 'UPDATE' : 'DRY-RUN', $detail);

            if (!$apply) {
                foreach ($changes as $code => $newValue) {
                    $oldValue = trim((string) $customer->getData($code));
                    $this->logAttributeChange($customerId, $email, $code, $oldValue, $newValue, false, 'dry-run');
                }
                $report['updated']++;
                continue;
            }

            try {
                $this->persistCustomerAttributes($customerId, $email, $customer, $changes);
                $report['updated']++;
            } catch (\Throwable $e) {
                $report['failed']++;
                $report['skipped']++;
                $error = sprintf('customer #%d (%s): %s', $customerId, $email, $e->getMessage());
                $report['errors'][] = $error;
                $this->log('ERROR', $error);
                $this->logger->error('[B2B Backfill] ' . $error, ['exception' => $e]);
            }
        }

        $report['snapshot'] = $this->collectGlobalSnapshot();

        return $report;
    }

    /**
     * @param \Magento\Customer\Model\Customer $sourceCustomer
     * @param array<string, string> $changes
     * @throws LocalizedException
     */
    private function persistCustomerAttributes(int $customerId, string $email, $sourceCustomer, array $changes): void
    {
        $customerModel = $this->customerFactory->create();
        $this->customerResource->load($customerModel, $customerId);
        if (!$customerModel->getId()) {
            throw new LocalizedException(__('Cliente #%1 não encontrado para gravação.', $customerId));
        }

        foreach ($changes as $code => $newValue) {
            $oldValue = trim((string) $sourceCustomer->getData($code));
            if ($oldValue === '') {
                $oldValue = trim((string) $this->readAttributeFromDb($customerId, $code));
            }

            $customerModel->setData($code, $newValue);
            $this->customerResource->saveAttribute($customerModel, $code);
            $this->customerRegistry->remove($customerId);

            $persistedDb = trim((string) $this->readAttributeFromDb($customerId, $code));
            $reloaded = $this->customerRepository->getById($customerId);
            $attr = $reloaded->getCustomAttribute($code);
            $persistedRepo = $attr !== null ? trim((string) $attr->getValue()) : '';

            $ok = $persistedDb === $newValue;
            $this->logAttributeChange($customerId, $email, $code, $oldValue, $newValue, $ok, $persistedDb);

            if (!$ok) {
                throw new LocalizedException(__(
                    'Falha ao persistir %1 para customer #%2. Esperado "%3", DB="%4", repository="%5".',
                    $code,
                    $customerId,
                    $newValue,
                    $persistedDb,
                    $persistedRepo
                ));
            }

            if ($persistedRepo !== $newValue) {
                $this->log(
                    'WARN',
                    sprintf(
                        'customer #%d (%s) | attr=%s persistiu no DB mas repository retornou "%s" (cache limpo; DB é fonte de verdade)',
                        $customerId,
                        $email,
                        $code,
                        $persistedRepo === '' ? 'NULL' : $persistedRepo
                    )
                );
            }
        }
    }

    private function readAttributeFromDb(int $customerId, string $attributeCode): string
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->resolveAttributeId($attributeCode);
        if ($attributeId <= 0) {
            return '';
        }

        $value = $connection->fetchOne(
            'SELECT value FROM customer_entity_varchar WHERE entity_id = ? AND attribute_id = ?',
            [$customerId, $attributeId]
        );

        return $value !== false ? (string) $value : '';
    }

    private function logAttributeChange(
        int $customerId,
        string $email,
        string $attribute,
        string $oldValue,
        string $newValue,
        bool $persisted,
        string $persistedValue
    ): void {
        $line = sprintf(
            'customer #%d (%s) | attr=%s | old=%s | new=%s | persisted=%s | db_after_reload=%s',
            $customerId,
            $email,
            $attribute,
            $oldValue === '' ? 'NULL' : $oldValue,
            $newValue,
            $persisted ? 'yes' : 'no',
            $persistedValue === '' ? 'NULL' : $persistedValue
        );
        $this->log($persisted ? 'PERSISTED' : 'PERSIST-FAIL', $line);
    }

    /**
     * @return \Magento\Customer\Model\ResourceModel\Customer\Collection
     */
    private function buildPendingCollection(
        ?int $customerId,
        ?int $fromId,
        ?int $toId,
        ?int $limit
    ) {
        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect([
            'email',
            'taxvat',
            'b2b_cnpj',
            'b2b_phone',
            'b2b_razao_social',
            'b2b_approval_status',
            'erp_customer_sync_status',
            'b2b_registration_campaign',
            'b2b_utm_source',
            'b2b_utm_medium',
            'b2b_utm_campaign',
            'b2b_origin_host',
            'b2b_registration_landing',
            'b2b_receita_situacao',
            'b2b_receita_validated',
        ]);
        $collection->addAttributeToFilter('b2b_cnpj', ['notnull' => true]);
        $collection->addAttributeToFilter('b2b_cnpj', ['neq' => '']);

        $explicitTarget = ($customerId !== null && $customerId > 0)
            || ($fromId !== null && $fromId > 0)
            || ($toId !== null && $toId > 0);

        if ($customerId !== null && $customerId > 0) {
            $collection->addFieldToFilter('entity_id', $customerId);
        } else {
            if (!$explicitTarget) {
                $this->applyPendingOnlyFilter($collection);
            }

            if ($fromId !== null && $fromId > 0) {
                $collection->addFieldToFilter('entity_id', ['gteq' => $fromId]);
            }
            if ($toId !== null && $toId > 0) {
                $collection->addFieldToFilter('entity_id', ['lteq' => $toId]);
            }
        }

        $collection->setOrder('entity_id', 'ASC');

        if ($limit !== null && $limit > 0) {
            $collection->setPageSize($limit);
            $collection->setCurPage(1);
        }

        return $collection;
    }

    /**
     * Restrict the default CLI batch to commercial queue customers.
     *
     * Previously this matched any CNPJ customer with empty ERP/origin/campaign,
     * including already approved accounts. Auto-assign and attribution markers
     * must not run against that population.
     *
     * @param \Magento\Customer\Model\ResourceModel\Customer\Collection $collection
     */
    private function applyPendingOnlyFilter($collection): void
    {
        $collection->addAttributeToFilter(
            'b2b_approval_status',
            ['in' => [ApprovalStatus::STATUS_PENDING, ApprovalStatus::STATUS_DATA_REVIEW]]
        );
    }

    /**
     * @param \Magento\Customer\Model\Customer $customer
     * @return array<string, string>
     */
    private function buildChanges($customer, bool $force): array
    {
        $changes = [];
        $customerId = (int) $customer->getId();

        $cnpj = trim((string) $customer->getData('b2b_cnpj'));
        if ($cnpj === '') {
            $taxvat = preg_replace('/\D/', '', (string) $customer->getTaxvat());
            if (strlen($taxvat) === 14) {
                $changes['b2b_cnpj'] = $this->formatCnpj($taxvat);
            }
        }

        $landing = trim((string) $customer->getData('b2b_registration_landing'));
        $originHost = trim((string) $customer->getData('b2b_origin_host'));
        if ($originHost === '') {
            $originHost = trim($this->readAttributeFromDb($customerId, 'b2b_origin_host'));
        }
        $utmSource = trim((string) $customer->getData('b2b_utm_source'));
        $utmMedium = trim((string) $customer->getData('b2b_utm_medium'));
        $utmCampaign = trim((string) $customer->getData('b2b_utm_campaign'));
        $campaign = trim((string) $customer->getData('b2b_registration_campaign'));
        if ($campaign === '') {
            $campaign = trim($this->readAttributeFromDb($customerId, 'b2b_registration_campaign'));
        }

        if ($landing !== '' && ($originHost === '' || $force)) {
            $host = parse_url($landing, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $changes['b2b_origin_host'] = $host;
            }
        }

        $hasHistoricalOrigin = $landing !== ''
            || $utmSource !== ''
            || $utmMedium !== ''
            || $utmCampaign !== ''
            || $campaign !== ''
            || $originHost !== '';

        if (!$hasHistoricalOrigin) {
            if ($originHost === '' && !isset($changes['b2b_origin_host'])) {
                $changes['b2b_origin_host'] = 'legacy_unknown';
            } elseif ($force && !isset($changes['b2b_origin_host'])) {
                $changes['b2b_origin_host'] = 'legacy_unknown';
            }

            if ($campaign === '' && !isset($changes['b2b_registration_campaign'])) {
                $changes['b2b_registration_campaign'] = 'legacy';
            } elseif ($force && !isset($changes['b2b_registration_campaign'])) {
                $changes['b2b_registration_campaign'] = 'legacy';
            }
        }

        $erpStatus = trim((string) $customer->getData('erp_customer_sync_status'));
        if ($erpStatus === '') {
            $erpStatus = trim($this->readAttributeFromDb($customerId, 'erp_customer_sync_status'));
        }
        if ($erpStatus === '' || $force) {
            if ($this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
                $changes['erp_customer_sync_status'] = ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP;
            } elseif ((string) $customer->getData('b2b_approval_status') === ApprovalStatus::STATUS_APPROVED) {
                $changes['erp_customer_sync_status'] = ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION;
            } elseif ($erpStatus === '' || $force) {
                $changes['erp_customer_sync_status'] = ErpCustomerSyncStatus::PROSPECT_MAGENTO;
            }
        }

        if (!$force) {
            foreach (array_keys($changes) as $field) {
                $current = trim((string) $customer->getData($field));
                if ($current === '') {
                    $current = trim($this->readAttributeFromDb($customerId, $field));
                }
                if ($current !== '') {
                    unset($changes[$field]);
                }
            }
        }

        return $changes;
    }

    /**
     * @return array<string, int>
     */
    public function collectGlobalSnapshot(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $cnpjAttrId = $this->resolveAttributeId('b2b_cnpj');
        $originAttrId = $this->resolveAttributeId('b2b_origin_host');
        $erpAttrId = $this->resolveAttributeId('erp_customer_sync_status');
        $phoneAttrId = $this->resolveAttributeId('b2b_phone');
        $razaoAttrId = $this->resolveAttributeId('b2b_razao_social');

        $baseFrom = "
            FROM customer_entity ce
            INNER JOIN customer_entity_varchar cnpj
                ON cnpj.entity_id = ce.entity_id
                AND cnpj.attribute_id = {$cnpjAttrId}
                AND cnpj.value != ''
        ";

        return [
            'pending_backfill' => (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
                 LEFT JOIN customer_entity_varchar erp ON erp.entity_id = ce.entity_id AND erp.attribute_id = {$erpAttrId}
                 LEFT JOIN customer_entity_varchar origin ON origin.entity_id = ce.entity_id AND origin.attribute_id = {$originAttrId}
                 LEFT JOIN customer_entity_varchar camp ON camp.entity_id = ce.entity_id AND camp.attribute_id = "
                . $this->resolveAttributeId('b2b_registration_campaign') . "
                 WHERE (erp.value IS NULL OR erp.value = '')
                    OR (origin.value IS NULL OR origin.value = '')
                    OR (camp.value IS NULL OR camp.value = '')"
            ),
            'legacy_unknown_hosts' => (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM customer_entity_varchar
                 WHERE attribute_id = {$originAttrId} AND value = 'legacy_unknown'"
            ),
            'no_attendant' => (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
                 LEFT JOIN grupoawamotos_b2b_customer_attendant ca ON ca.customer_id = ce.entity_id
                 WHERE ca.customer_id IS NULL"
            ),
            'no_phone' => (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
                 LEFT JOIN customer_entity_varchar phone ON phone.entity_id = ce.entity_id AND phone.attribute_id = {$phoneAttrId}
                 WHERE phone.value IS NULL OR phone.value = ''"
            ),
            'no_razao_social' => (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
                 LEFT JOIN customer_entity_varchar razao ON razao.entity_id = ce.entity_id AND razao.attribute_id = {$razaoAttrId}
                 WHERE razao.value IS NULL OR razao.value = ''"
            ),
            'erp_status_null' => (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
                 LEFT JOIN customer_entity_varchar erp ON erp.entity_id = ce.entity_id AND erp.attribute_id = {$erpAttrId}
                 WHERE erp.value IS NULL OR erp.value = ''"
            ),
        ];
    }

    private function ensureAttendant(int $customerId, bool $apply): bool
    {
        if ($this->attendantManager->getCustomerAttendant($customerId) !== null) {
            return true;
        }

        if (!$apply) {
            return false;
        }

        return $this->attendantManager->autoAssignCustomer($customerId) !== null;
    }

    private function formatCnpj(string $digits): string
    {
        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2)
        );
    }

    private function resolveAttributeId(string $code): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            "SELECT ea.attribute_id FROM eav_attribute ea
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = ? AND et.entity_type_code = 'customer'",
            [$code]
        );
    }

    private function log(string $level, string $message): void
    {
        $line = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), $level, $message);
        $this->logger->info('[B2B Backfill] ' . $message);

        $path = BP . self::LOG_FILE;
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
