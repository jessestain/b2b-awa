<?php

/**
 * Customer Approval Service
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\CnaeClassifier;
use GrupoAwamotos\B2B\Service\CustomerGroupManager;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class CustomerApproval implements CustomerApprovalInterface
{
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Config $config,
        ResourceConnection $resourceConnection,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        DateTime $dateTime,
        LoggerInterface $logger,
        EventManager $eventManager,
        CacheInterface $cache
    ) {
        $this->customerRepository = $customerRepository;
        $this->config = $config;
        $this->resourceConnection = $resourceConnection;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
        $this->cache = $cache;
    }

    /**
     * @inheritDoc
     */
    public function setCustomerPending(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $customer->setCustomAttribute('b2b_approval_status', ApprovalStatus::STATUS_PENDING);
            $this->customerRepository->save($customer);

            $this->logAction($customerId, 'registered', null, ApprovalStatus::STATUS_PENDING, null, null);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B setCustomerPending error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function approveCustomer(int $customerId, ?int $adminUserId = null, ?string $comment = null): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $oldStatus = $this->getCustomerAttributeValue($customer, 'b2b_approval_status');
            $previousGroupId = (int) $customer->getGroupId();

            $customer->setCustomAttribute('b2b_approval_status', ApprovalStatus::STATUS_APPROVED);
            $customer->setCustomAttribute('b2b_approved_at', $this->dateTime->gmtDate());

            // Atribuir grupo B2B baseado no perfil CNAE se disponível
            $targetGroup = 0;
            $cnaeProfile = $this->getCustomerAttributeValue($customer, 'b2b_cnae_profile');

            if ($cnaeProfile === CnaeClassifier::PROFILE_DIRECT) {
                $targetGroup = $this->config->getDirectProfileGroupId();
            } elseif ($cnaeProfile === CnaeClassifier::PROFILE_ADJACENT) {
                $targetGroup = $this->config->getAdjacentProfileGroupId();
            }

            // Fallback para o grupo B2B padrão se nenhum perfil específico foi encontrado ou configurado
            if ($targetGroup <= 0) {
                $targetGroup = $this->config->getDefaultB2BGroupId();
            }

            if ($targetGroup > 0 && ($customer->getGroupId() == 1 || $customer->getGroupId() == $this->config->getPendingGroupId())) {
                $customer->setGroupId($targetGroup);
            }

            $this->customerRepository->save($customer);

            $newGroupId = (int) $customer->getGroupId();
            if ($newGroupId > 0 && $newGroupId !== $previousGroupId) {
                $this->signalSessionGroupRefresh($customerId, $newGroupId);
            }

            $this->syncLegacyB2bCustomerStatus($customerId, 1, $adminUserId);

            $this->logAction($customerId, 'approved', $oldStatus, ApprovalStatus::STATUS_APPROVED, $adminUserId, $comment);

            // Dispatch event for ERP integration
            $this->eventManager->dispatch('grupoawamotos_b2b_customer_approved', [
                'customer_id' => $customerId,
                'customer' => $customer,
                'new_group_id' => $customer->getGroupId(),
                'old_status' => $oldStatus,
                'admin_user_id' => $adminUserId,
            ]);

            // Enviar email de aprovação
            if ($this->config->sendApprovalEmail()) {
                $this->sendApprovalEmail($customerId);
            }

            $this->logger->info(sprintf('B2B: Cliente #%d aprovado', $customerId));

            $this->recalibratePendingAlertCounter();

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B approveCustomer error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function rejectCustomer(int $customerId, ?int $adminUserId = null, ?string $reason = null): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $oldStatus = $this->getCustomerAttributeValue($customer, 'b2b_approval_status');

            $customer->setCustomAttribute('b2b_approval_status', ApprovalStatus::STATUS_REJECTED);
            $this->customerRepository->save($customer);

            $this->syncLegacyB2bCustomerStatus($customerId, 2, $adminUserId);

            $this->logAction($customerId, 'rejected', $oldStatus, ApprovalStatus::STATUS_REJECTED, $adminUserId, $reason);

            $this->eventManager->dispatch('grupoawamotos_b2b_customer_rejected', [
                'customer_id' => $customerId,
                'customer' => $customer,
                'old_status' => $oldStatus,
                'reason' => $reason,
                'admin_user_id' => $adminUserId,
            ]);

            // Enviar email de rejeição
            $this->sendRejectionEmail($customerId, $reason);

            $this->logger->info(sprintf('B2B: Cliente #%d rejeitado. Motivo: %s', $customerId, $reason ?? 'N/A'));

            $this->recalibratePendingAlertCounter();

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B rejectCustomer error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function suspendCustomer(int $customerId, ?int $adminUserId = null, ?string $reason = null): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $oldStatus = $this->getCustomerAttributeValue($customer, 'b2b_approval_status');

            $customer->setCustomAttribute('b2b_approval_status', ApprovalStatus::STATUS_SUSPENDED);
            $this->customerRepository->save($customer);

            $this->logAction($customerId, 'suspended', $oldStatus, ApprovalStatus::STATUS_SUSPENDED, $adminUserId, $reason);

            $this->logger->info(sprintf('B2B: Cliente #%d suspenso. Motivo: %s', $customerId, $reason ?? 'N/A'));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B suspendCustomer error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function requestDataReview(int $customerId, ?int $adminUserId = null, ?string $message = null): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $oldStatus = $this->getCustomerAttributeValue($customer, 'b2b_approval_status');

            $customer->setCustomAttribute('b2b_approval_status', ApprovalStatus::STATUS_DATA_REVIEW);
            $this->customerRepository->save($customer);

            $this->logAction(
                $customerId,
                'needs_information',
                $oldStatus,
                ApprovalStatus::STATUS_DATA_REVIEW,
                $adminUserId,
                $message
            );

            $this->eventManager->dispatch('grupoawamotos_b2b_customer_data_review_requested', [
                'customer_id' => $customerId,
                'customer' => $customer,
                'old_status' => $oldStatus,
                'message' => $message,
                'admin_user_id' => $adminUserId,
            ]);

            $this->logger->info(sprintf('B2B: Cliente #%d movido para revisão de cadastro', $customerId));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B requestDataReview error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getApprovalStatus(int $customerId): ?string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            return $this->getCustomerAttributeValue($customer, 'b2b_approval_status');
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function isApproved(int $customerId): bool
    {
        $status = $this->getApprovalStatus($customerId);

        // Fail-closed: clientes sem status explícito não devem comprar como B2B.
        if ($status === null || $status === '') {
            return false;
        }

        return $status === ApprovalStatus::STATUS_APPROVED;
    }

    /**
     * @inheritDoc
     */
    public function notifyAdminNewCustomer(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $adminEmail = $this->config->getAdminEmail();

            if (empty($adminEmail)) {
                return false;
            }

            /** @var \Magento\Store\Model\Store $store */
            $store = $this->storeManager->getStore();

            $cnpj = $this->getCustomerAttributeValue($customer, 'b2b_cnpj') ?? 'N/A';
            $razaoSocial = $this->getCustomerAttributeValue($customer, 'b2b_razao_social') ?? 'N/A';
            $phone = $this->getCustomerAttributeValue($customer, 'b2b_phone') ?? 'N/A';

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('grupoawamotos_b2b_admin_new_customer')
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_ADMINHTML,
                    'store' => $store->getId(),
                ])
                ->setTemplateVars([
                    'customer' => $customer,
                    'customer_name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                    'customer_email' => $customer->getEmail(),
                    'cnpj' => $cnpj,
                    'razao_social' => $razaoSocial,
                    'phone' => $phone,
                    'store_name' => $store->getName(),
                    'approval_url' => $store->getBaseUrl() . 'admin/customer/index',
                ])
                ->setFromByScope('general')
                ->addTo($adminEmail)
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B notifyAdminNewCustomer error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendApprovalEmail(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            /** @var \Magento\Store\Model\Store $store */
            $store = $this->storeManager->getStore($customer->getStoreId());

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('grupoawamotos_b2b_customer_approved')
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $store->getId(),
                ])
                ->setTemplateVars([
                    'customer' => $customer,
                    'customer_name' => $customer->getFirstname(),
                    'store_name' => $store->getName(),
                    'store_url' => $store->getBaseUrl(),
                    'login_url' => $store->getBaseUrl() . 'b2b/account/login',
                ])
                ->setFromByScope('general')
                ->addTo($customer->getEmail(), $customer->getFirstname() . ' ' . $customer->getLastname())
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B sendApprovalEmail error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendRejectionEmail(int $customerId, ?string $reason = null): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            /** @var \Magento\Store\Model\Store $store */
            $store = $this->storeManager->getStore($customer->getStoreId());

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('grupoawamotos_b2b_customer_rejected')
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $store->getId(),
                ])
                ->setTemplateVars([
                    'customer' => $customer,
                    'customer_name' => $customer->getFirstname(),
                    'store_name' => $store->getName(),
                    'reason' => $reason ?? 'Não foi possível aprovar seu cadastro no momento.',
                    'contact_url' => $store->getBaseUrl() . 'contact',
                ])
                ->setFromByScope('general')
                ->addTo($customer->getEmail(), $customer->getFirstname() . ' ' . $customer->getLastname())
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (\Exception $e) {
            $this->logger->error('B2B sendRejectionEmail error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log approval action
     *
     * @param int $customerId
     * @param string $action
     * @param string|null $oldStatus
     * @param string $newStatus
     * @param int|null $adminUserId
     * @param string|null $comment
     * @return void
     */
    private function logAction(
        int $customerId,
        string $action,
        ?string $oldStatus,
        string $newStatus,
        ?int $adminUserId,
        ?string $comment
    ): void {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_approval_log');

            $connection->insert($tableName, [
                'customer_id' => $customerId,
                'action' => $action,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'admin_user_id' => $adminUserId,
                'comment' => $comment,
                'created_at' => $this->dateTime->gmtDate(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('B2B logAction error: ' . $e->getMessage());
        }
    }

    /**
     * Get customer attribute value
     *
     * @param CustomerInterface $customer
     * @param string $attributeCode
     * @return string|null
     */
    private function getCustomerAttributeValue(CustomerInterface $customer, string $attributeCode): ?string
    {
        $attribute = $customer->getCustomAttribute($attributeCode);
        return $attribute ? (string) $attribute->getValue() : null;
    }

    /**
     * Sinaliza refresh da sessão frontend quando o grupo muda após aprovação admin.
     */
    private function signalSessionGroupRefresh(int $customerId, int $newGroupId): void
    {
        $this->cache->save(
            (string) $newGroupId,
            CustomerGroupManager::CACHE_KEY_PREFIX . $customerId,
            [],
            CustomerGroupManager::CACHE_LIFETIME
        );
    }

    /**
     * Mantém grupoawamotos_b2b_customer alinhado ao fluxo EAV quando existir registro legado.
     *
     * @param int $legacyStatus 0=pendente, 1=aprovado, 2=rejeitado
     */
    private function syncLegacyB2bCustomerStatus(int $customerId, int $legacyStatus, ?int $adminUserId = null): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer');
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($table, ['b2b_customer_id'])
                    ->where('customer_id = ?', $customerId)
                    ->limit(1)
            );

            if (!$row) {
                return;
            }

            $data = [
                'status' => $legacyStatus,
                'updated_at' => $this->dateTime->gmtDate(),
            ];

            if ($legacyStatus === 1) {
                $data['approved_at'] = $this->dateTime->gmtDate();
                if ($adminUserId !== null) {
                    $data['approved_by'] = $adminUserId;
                }
            }

            if ($legacyStatus === 2 && $adminUserId !== null) {
                $data['approved_by'] = $adminUserId;
                $data['approved_at'] = $this->dateTime->gmtDate();
            }

            $connection->update(
                $table,
                $data,
                ['b2b_customer_id = ?' => (int) $row['b2b_customer_id']]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'B2B syncLegacyB2bCustomerStatus error: ' . $e->getMessage(),
                ['customer_id' => $customerId]
            );
        }
    }

    /**
     * Recalibrates the B2B pending alert counter after an approval or rejection.
     *
     * The daily cron only alerts when pending count > last_sent_count.
     * After processing a customer, we update last_sent_count to the CURRENT pending count
     * so that the next cron cycle detects only truly new additions.
     */
    private function recalibratePendingAlertCounter(): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            $select = $connection->select()
                ->from(
                    ['c' => $connection->getTableName('customer_entity')],
                    ['COUNT(*) AS cnt']
                )
                ->joinInner(
                    ['v' => $connection->getTableName('customer_entity_varchar')],
                    'c.entity_id = v.entity_id',
                    []
                )
                ->joinInner(
                    ['a' => $connection->getTableName('eav_attribute')],
                    "v.attribute_id = a.attribute_id AND a.attribute_code = 'b2b_approval_status'",
                    []
                )
                ->where('v.value = ?', 'pending')
                ->where('c.created_at < ?', new \Zend_Db_Expr('DATE_SUB(NOW(), INTERVAL 48 HOUR)'));

            $pendingCount = (int) $connection->fetchOne($select);

            $connection->insertOnDuplicate(
                $connection->getTableName('core_config_data'),
                [
                    'scope'    => 'default',
                    'scope_id' => 0,
                    'path'     => 'grupoawamotos_b2b/pending_alert/last_sent_count',
                    'value'    => (string) $pendingCount,
                ],
                ['value']
            );
        } catch (\Exception $e) {
            $this->logger->warning('B2B: Failed to recalibrate pending alert counter: ' . $e->getMessage());
        }
    }
}
