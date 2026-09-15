<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;
use GrupoAwamotos\B2B\Model\CustomerCnpjResolver;
use GrupoAwamotos\B2B\Model\Sectra\ProspectPipeline;
use GrupoAwamotos\ERPIntegration\Helper\Data as ErpHelper;
use GrupoAwamotos\ERPIntegration\Model\B2BClientRegistration;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Post-approval ERP handler — commercial approval is sufficient; ERP links via order pull.
 *
 * Opportunistically links customers already present in FN_FORNECEDORES (e.g. legacy #8926)
 * without requiring ERP registration for new approved customers.
 */
class ApprovedCustomerErpSync
{
    public const ACTION_NOT_APPLICABLE_PULL_ORDER = 'not_applicable_pull_order';
    public const ACTION_LINKED_EXISTING = 'linked_existing';
    public const ACTION_LINKED_BY_CNPJ = 'linked_by_cnpj';
    public const ACTION_CREATED_ERP_PROSPECT = 'created_erp_prospect';
    /** @deprecated Legacy action — no longer returned for new approvals */
    public const ACTION_PENDING_ERP_CREATION = 'pending_erp_creation';
    public const ACTION_SKIPPED = 'skipped';

    private const PULL_ORDER_MESSAGE = 'Aguardando pedido para integração via pull Sectra.';
    private const XML_PATH_LINK_EXISTING_ERP = 'grupoawamotos_b2b/customer_approval/link_existing_erp_on_approval';
    private const XML_PATH_CREATE_ERP_PROSPECT_STUB = 'grupoawamotos_b2b/customer_approval/create_erp_prospect_stub_on_approval';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ErpIntegration $erpIntegration,
        private readonly CustomerCnpjResolver $cnpjResolver,
        private readonly B2BClientRegistration $b2bClientRegistration,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ErpHelper $erpHelper,
        private readonly SyncLogResource $syncLogResource,
        private readonly ProspectPipeline $prospectPipeline,
        private readonly LoggerInterface $logger,
        private readonly ?CreditService $creditService = null
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     customer_id: int,
     *     cnpj: string|null,
     *     cnpj_source: string|null,
     *     erp_code: int|null,
     *     action: string,
     *     erp_customer_sync_status: string|null,
     *     message: string,
     *     last_sync_at_updated: bool
     * }
     */
    public function syncApprovedCustomer(int $customerId, ?int $newGroupId = null): array
    {
        $result = [
            'success' => false,
            'customer_id' => $customerId,
            'cnpj' => null,
            'cnpj_source' => null,
            'erp_code' => null,
            'action' => self::ACTION_SKIPPED,
            'erp_customer_sync_status' => null,
            'message' => '',
            'last_sync_at_updated' => false,
        ];

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Exception $e) {
            $result['message'] = 'Cliente não encontrado.';
            $this->logger->error(sprintf('[B2B-ERP-Sync] Customer #%d not found: %s', $customerId, $e->getMessage()));
            return $result;
        }

        if ($newGroupId === null) {
            $newGroupId = (int) $customer->getGroupId();
        }

        $statusAttr = $customer->getCustomAttribute('b2b_approval_status');
        if ($statusAttr === null || (string) $statusAttr->getValue() !== ApprovalStatus::STATUS_APPROVED) {
            $result['message'] = 'Cliente não está aprovado comercialmente.';
            return $result;
        }

        $resolved = $this->cnpjResolver->resolveWithSource($customer);
        if ($resolved !== null) {
            $result['cnpj'] = $resolved['digits'];
            $result['cnpj_source'] = $resolved['source'];
        }

        if ($resolved !== null && !$this->cnpjResolver->isValidCnpj($resolved['digits'])) {
            $result['message'] = 'CNPJ inválido — aprovação comercial mantida; corrija antes do primeiro pedido.';
            $this->setCustomerSyncStatus($customer, ErpCustomerSyncStatus::NOT_APPLICABLE_PULL_ORDER);
            $result['erp_customer_sync_status'] = ErpCustomerSyncStatus::NOT_APPLICABLE_PULL_ORDER;
            $result['success'] = true;
            $result['action'] = self::ACTION_NOT_APPLICABLE_PULL_ORDER;
            $this->logInfo($customerId, $result['message'], $resolved['digits']);
            return $result;
        }

        // Opportunistic link when customer already exists in ERP (optional, never required)
        if ($this->erpHelper->isEnabled() && $resolved !== null && $this->isLinkExistingErpOnApproval()) {
            $linkResult = $this->tryLinkExistingErpCustomer($customer, $customerId, $resolved['digits'], $newGroupId);
            if ($linkResult !== null) {
                return $linkResult;
            }
        }

        // Default: envia prospect ao Sectra e aguarda validação no ERP
        $pipelineResult = $this->prospectPipeline->processApprovedCustomer($customerId);
        $result['success'] = $pipelineResult['success'];
        $result['action'] = self::ACTION_NOT_APPLICABLE_PULL_ORDER;
        $result['erp_customer_sync_status'] = $pipelineResult['erp_customer_sync_status'];
        $result['message'] = $pipelineResult['message'];

        $this->logger->info(sprintf(
            '[B2B-ERP-Sync] Customer #%d: pipeline Sectra — status=%s, msg=%s',
            $customerId,
            $pipelineResult['erp_customer_sync_status'],
            $pipelineResult['message']
        ));

        return $result;
    }

    public function isApprovedCustomer(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Exception) {
            return false;
        }

        $statusAttr = $customer->getCustomAttribute('b2b_approval_status');

        return $statusAttr !== null
            && (string) $statusAttr->getValue() === ApprovalStatus::STATUS_APPROVED;
    }

    private function isLinkExistingErpOnApproval(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LINK_EXISTING_ERP, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryLinkExistingErpCustomer(
        CustomerInterface $customer,
        int $customerId,
        string $cnpjDigits,
        int $newGroupId
    ): ?array {
        $existingErpCode = $this->erpIntegration->getErpCodeForCustomer($customerId);
        $erpCustomerByCnpj = $this->erpIntegration->findErpCustomerByCnpj($cnpjDigits);
        $erpCodeFromCnpj = $erpCustomerByCnpj ? (int) ($erpCustomerByCnpj['CODIGO'] ?? 0) : null;
        $targetErpCode = $existingErpCode ?? ($erpCodeFromCnpj > 0 ? $erpCodeFromCnpj : null);
        $createdProspect = false;

        // Autonomy: create FN prospect stub when CNPJ is absent from ERP (no Sectra desktop).
        if (($targetErpCode === null || $targetErpCode <= 0) && $this->isCreateErpProspectStubOnApproval()) {
            $createdCode = $this->b2bClientRegistration->createErpProspectStub(
                $cnpjDigits,
                $this->buildErpProspectProfile($customer)
            );
            if ($createdCode !== null && $createdCode > 0) {
                $targetErpCode = $createdCode;
                $createdProspect = true;
            }
        }


        if ($targetErpCode === null || $targetErpCode <= 0) {
            return null;
        }

        if ($existingErpCode !== null && $erpCodeFromCnpj !== null && $existingErpCode !== $erpCodeFromCnpj) {
            $this->logger->error(sprintf(
                '[B2B-ERP-Sync] Customer #%d: conflito ERP #%d vs CNPJ #%d — mantendo pull-order',
                $customerId,
                $existingErpCode,
                $erpCodeFromCnpj
            ));
            return null;
        }

        $linked = $this->erpIntegration->linkCustomerToErp($customerId, $targetErpCode);
        if (!$linked) {
            $this->logger->warning(sprintf(
                '[B2B-ERP-Sync] Customer #%d: falha ao vincular ERP #%d — mantendo pull-order',
                $customerId,
                $targetErpCode
            ));
            return null;
        }

        if ($createdProspect) {
            $syncStatus = ErpCustomerSyncStatus::LINKED_BY_CNPJ;
            $action = self::ACTION_CREATED_ERP_PROSPECT;
        } elseif ($existingErpCode !== null) {
            $syncStatus = ErpCustomerSyncStatus::LINKED_EXISTING;
            $action = self::ACTION_LINKED_EXISTING;
        } else {
            $syncStatus = ErpCustomerSyncStatus::LINKED_BY_CNPJ;
            $action = self::ACTION_LINKED_BY_CNPJ;
        }

        $promotedToNative = $this->b2bClientRegistration->promoteMagentoProspectToNativeClient(
            $targetErpCode
        );
        $this->ensureErpValidatorRegistration($customer, (string) $targetErpCode, $newGroupId);
        // Cadastro de Cliente (7D4C6FBD) — not prospect-only — gates Importar Pedidos.
        $hasCadastro = $this->b2bClientRegistration->hasCadastroCliente($targetErpCode);
        $hasProspectOrigin = $this->b2bClientRegistration->isClientProspectRegistered($targetErpCode);
        $isNativeClient = $this->b2bClientRegistration->isExistingErpNativeClient($targetErpCode);
        $erpValidated = $this->b2bClientRegistration->isClientReadyForSectraOrderImport($targetErpCode);



        $erpCustomerReady = $erpValidated && $isNativeClient;
        $erpSyncStatus = $erpCustomerReady
            ? ErpCustomerSyncStatus::CUSTOMER_VALIDATED_IN_ERP
            : ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION;
        $this->setCustomerSyncStatus($customer, $erpSyncStatus);
        $this->syncCreditLimit($customer);

        if ($createdProspect && $promotedToNative && $erpValidated) {
            $message = sprintf(
                'Cliente criado no ERP #%d (autonomia Magento) e validado para Importar Pedidos.',
                $targetErpCode
            );
        } elseif ($createdProspect) {
            $message = sprintf(
                'Prospect criado no ERP #%d — promoção nativa ou Cadastro de Cliente pendente.',
                $targetErpCode
            );
        } elseif ($erpCustomerReady) {
            $message = sprintf('Cliente já existia no ERP — vínculo #%d (validado para Importar Pedidos).', $targetErpCode);
        } else {
            $message = sprintf(
                'Cliente vinculado ao ERP #%d — aguardando promoção nativa ou Cadastro no validador.',
                $targetErpCode
            );
        }
        $this->logger->info(sprintf('[B2B-ERP-Sync] Customer #%d: %s', $customerId, $message));
        $this->logSyncAttempt($customerId, 'success', $message, (string) $targetErpCode, $cnpjDigits);

        return [
            'success' => true,
            'customer_id' => $customerId,
            'cnpj' => $cnpjDigits,
            'cnpj_source' => 'b2b_cnpj',
            'erp_code' => $targetErpCode,
            'action' => $action,
            'erp_customer_sync_status' => $erpSyncStatus,
            'message' => $message,
            'last_sync_at_updated' => true,
        ];
    }

    private function isCreateErpProspectStubOnApproval(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CREATE_ERP_PROSPECT_STUB, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return array{
     *     razao: string,
     *     fantasia: string,
     *     ie: string,
     *     endereco: string,
     *     numero: string,
     *     bairro: string,
     *     cidade: string,
     *     cep: string,
     *     uf: string
     * }
     */
    private function buildErpProspectProfile(CustomerInterface $customer): array
    {
        $razao = (string) ($customer->getCustomAttribute('b2b_razao_social')?->getValue() ?? '');
        $fantasia = (string) ($customer->getCustomAttribute('b2b_nome_fantasia')?->getValue() ?? '');
        $ie = (string) ($customer->getCustomAttribute('b2b_inscricao_estadual')?->getValue() ?? 'ISENTO');
        if ($razao === '') {
            $razao = trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? ''));
        }
        if ($fantasia === '') {
            $fantasia = $razao;
        }

        $endereco = '.';
        $numero = '.';
        $bairro = '.';
        $cidade = 'ARARAQUARA';
        $cep = '';
        $uf = 'SP';

        try {
            $addresses = $customer->getAddresses() ?? [];
            $address = $addresses[0] ?? null;
            if ($address !== null) {
                $street = $address->getStreet() ?? [];
                $line1 = trim((string) ($street[0] ?? ''));
                if ($line1 !== '') {
                    $endereco = $line1;
                }
                $line2 = trim((string) ($street[1] ?? ''));
                if ($line2 !== '' && preg_match('/^\d+/', $line2) === 1) {
                    $numero = $line2;
                } elseif ($line2 !== '') {
                    $bairro = $line2;
                }
                $city = trim((string) ($address->getCity() ?? ''));
                if ($city !== '') {
                    $cidade = $city;
                }
                $cep = (string) ($address->getPostcode() ?? '');
                $region = $address->getRegion();
                $regionCode = is_object($region) ? (string) ($region->getRegionCode() ?? '') : '';
                if (strlen($regionCode) === 2) {
                    $uf = strtoupper($regionCode);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[B2B-ERP-Sync] Customer #%d: falha ao ler endereço para prospect ERP — %s',
                (int) $customer->getId(),
                $e->getMessage()
            ));
        }

        return [
            'razao' => $razao,
            'fantasia' => $fantasia,
            'ie' => $ie !== '' ? $ie : 'ISENTO',
            'endereco' => $endereco,
            'numero' => $numero,
            'bairro' => $bairro,
            'cidade' => $cidade,
            'cep' => $cep,
            'uf' => $uf,
        ];
    }

    private function setCustomerSyncStatus(CustomerInterface $customer, string $status): void
    {
        try {
            $customer->setCustomAttribute('erp_customer_sync_status', $status);
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[B2B-ERP-Sync] Customer #%d: falha ao gravar erp_customer_sync_status — %s',
                $customer->getId(),
                $e->getMessage()
            ));
        }
    }

    private function ensureErpValidatorRegistration(
        CustomerInterface $customer,
        string $erpCode,
        int $newGroupId
    ): void {
        $erpCodeInt = (int) $erpCode;
        if ($erpCodeInt <= 0) {
            return;
        }

        if ($this->b2bClientRegistration->hasCadastroCliente($erpCodeInt)) {
            return;
        }

        if ($this->b2bClientRegistration->hasWriteAccess()) {
            $this->b2bClientRegistration->registerClient($erpCodeInt);
        }
    }

    private function syncCreditLimit(CustomerInterface $customer): void
    {
        try {
            $customerId = (int) $customer->getId();
            $erpCode = $this->erpIntegration->getErpCodeForCustomer($customerId);
            if ($erpCode === null) {
                return;
            }

            $creditLimit = $this->erpIntegration->getCreditLimitFromErp((string) $erpCode);
            if ($creditLimit !== null && $creditLimit > 0) {
                if ($this->creditService !== null) {
                    $this->creditService->setLimit(
                        $customerId,
                        (float) $creditLimit,
                        null,
                        'Sincronizado do ERP durante aprovação B2B.'
                    );
                    return;
                }

                $customer->setCustomAttribute('credit_limit', $creditLimit);
                $this->customerRepository->save($customer);
            }
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[B2B-ERP-Sync] Customer #%d: falha ao sincronizar crédito — %s',
                $customer->getId(),
                $e->getMessage()
            ));
        }
    }

    private function logInfo(int $customerId, string $message, ?string $cnpj = null): void
    {
        $this->logger->info(sprintf('[B2B-ERP-Sync] Customer #%d: %s', $customerId, $message));
        $this->logSyncAttempt($customerId, 'info', $message, null, $cnpj);
    }

    private function logSyncAttempt(
        int $customerId,
        string $status,
        string $message,
        ?string $erpCode = null,
        ?string $cnpj = null
    ): void {
        $logMessage = $message;
        if ($cnpj !== null) {
            $digits = preg_replace('/\D/', '', $cnpj);
            $masked = strlen($digits) === 14
                ? substr($digits, 0, 2) . '********' . substr($digits, -4)
                : '***';
            $logMessage .= ' CNPJ: ' . $masked;
        }

        $this->syncLogResource->addLog(
            'customer',
            'export',
            $status,
            $logMessage,
            $erpCode,
            $customerId,
            $status === 'success' ? 1 : 0
        );
    }
}
