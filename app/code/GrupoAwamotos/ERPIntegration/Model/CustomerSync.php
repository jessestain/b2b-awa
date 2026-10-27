<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;

use GrupoAwamotos\ERPIntegration\Api\CustomerSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use GrupoAwamotos\ERPIntegration\Model\Validator\CustomerValidator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\StoreManagerInterface;
use GrupoAwamotos\B2B\Service\CustomerGroupManager;
use Psr\Log\LoggerInterface;

class CustomerSync implements CustomerSyncInterface
{
    private const BATCH_SIZE = 100;

    private ConnectionInterface $connection;
    private Helper $helper;
    private CustomerRepositoryInterface $customerRepository;
    private CustomerInterfaceFactory $customerFactory;
    private AddressInterfaceFactory $addressFactory;
    private AddressRepositoryInterface $addressRepository;
    private RegionInterfaceFactory $regionFactory;
    private RegionFactory $regionModelFactory;
    private StoreManagerInterface $storeManager;
    private SyncLogResource $syncLogResource;
    private CustomerValidator $customerValidator;
    private EmailSanitizer $emailSanitizer;
    private LoggerInterface $logger;
    private Random $random;
    private EncryptorInterface $encryptor;
    private AppState $appState;
    private ?CustomerGroupManager $customerGroupManager;

    private array $regionCache = [];
    private array $erpMappingCache = [];

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        CustomerRepositoryInterface $customerRepository,
        CustomerInterfaceFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        RegionInterfaceFactory $regionFactory,
        RegionFactory $regionModelFactory,
        StoreManagerInterface $storeManager,
        SyncLogResource $syncLogResource,
        CustomerValidator $customerValidator,
        EmailSanitizer $emailSanitizer,
        LoggerInterface $logger,
        Random $random,
        EncryptorInterface $encryptor,
        AppState $appState,
        ?CustomerGroupManager $customerGroupManager = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->regionFactory = $regionFactory;
        $this->regionModelFactory = $regionModelFactory;
        $this->storeManager = $storeManager;
        $this->syncLogResource = $syncLogResource;
        $this->customerValidator = $customerValidator;
        $this->emailSanitizer = $emailSanitizer;
        $this->logger = $logger;
        $this->random = $random;
        $this->encryptor = $encryptor;
        $this->appState = $appState;
        $this->customerGroupManager = $customerGroupManager;
    }

    public function getErpCustomerByTaxvat(string $taxvat): ?array
    {
        $cleanTaxvat = preg_replace('/[^0-9]/', '', $taxvat);

        try {
            $sql = "SELECT f.CODIGO, f.RAZAO, f.FANTASIA, f.CGC, f.CPF,
                           f.ENDERECO, f.NUMERO, f.BAIRRO, f.CIDADE, f.CEP, f.UF,
                           f.CONDPAGTO, f.FATORPRECO, f.CKPESSOA, f.TRANSPPREF,
                           REPLACE(REPLACE(REPLACE(tp.CGC, '.', ''), '/', ''), '-', '') AS TRANSPPREF_CNPJ,
                           tp.RAZAO AS TRANSPPREF_NOME,
                           c.EMAIL, c.FONE1, c.FONECEL, c.NOME AS CONTATO_NOME
                    FROM FN_FORNECEDORES f
                    LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = f.CODIGO AND c.PRINCIPAL = 'S'
                    LEFT JOIN FN_FORNECEDORES tp ON tp.CODIGO = f.TRANSPPREF AND tp.CKTRANSPORTADOR = 'S'
                    WHERE f.CKCLIENTE = 'S'
                      AND f.ATCLIENTE = 'S'
                      AND (REPLACE(REPLACE(REPLACE(f.CGC, '.', ''), '/', ''), '-', '') = :taxvat
                           OR REPLACE(REPLACE(f.CPF, '.', ''), '-', '') = :taxvat2)";

            return $this->connection->fetchOne($sql, [
                ':taxvat' => $cleanTaxvat,
                ':taxvat2' => $cleanTaxvat,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Customer lookup error: ' . $e->getMessage());
            return null;
        }
    }

    public function getErpCustomerByCode(int $code): ?array
    {
        try {
            $sql = "SELECT f.CODIGO, f.RAZAO, f.FANTASIA, f.CGC, f.CPF,
                           f.ENDERECO, f.NUMERO, f.BAIRRO, f.CIDADE, f.CEP, f.UF,
                           f.CONDPAGTO, f.FATORPRECO, f.CKPESSOA, f.TRANSPPREF,
                           REPLACE(REPLACE(REPLACE(tp.CGC, '.', ''), '/', ''), '-', '') AS TRANSPPREF_CNPJ,
                           tp.RAZAO AS TRANSPPREF_NOME,
                           c.EMAIL, c.FONE1, c.FONECEL, c.NOME AS CONTATO_NOME
                    FROM FN_FORNECEDORES f
                    LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = f.CODIGO AND c.PRINCIPAL = 'S'
                    LEFT JOIN FN_FORNECEDORES tp ON tp.CODIGO = f.TRANSPPREF AND tp.CKTRANSPORTADOR = 'S'
                    WHERE f.CODIGO = :code AND f.CKCLIENTE = 'S' AND f.ATCLIENTE = 'S'";

            return $this->connection->fetchOne($sql, [':code' => $code]);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Customer code lookup error: ' . $e->getMessage());
            return null;
        }
    }

    public function syncAll(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 0, 'validation_failed' => 0];

        if (!$this->helper->isCustomerSyncEnabled()) {
            $this->logger->info('[ERP] Customer sync is disabled');
            return $result;
        }

        // Set area code for CLI execution
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, ignore
        }

        try {
            $totalCount = $this->getErpCustomerCount();
            $this->logger->info(sprintf('[ERP] Starting customer sync. Total in ERP: %d', $totalCount));

            $offset = 0;
            while ($offset < $totalCount) {
                $customers = $this->getErpCustomersBatch($offset, self::BATCH_SIZE);

                foreach ($customers as $row) {
                    try {
                        // Validate customer data before sync
                        $validationResult = $this->customerValidator->validate($row);

                        if (!$validationResult->isValid()) {
                            $result['validation_failed']++;
                            $this->logger->warning('[ERP] Customer validation failed', [
                                'code' => $row['CODIGO'] ?? '?',
                                'errors' => $validationResult->getErrors(),
                            ]);
                            continue;
                        }

                        // Log warnings if any
                        if ($validationResult->hasWarnings()) {
                            $this->logger->info('[ERP] Customer validation warnings', [
                                'code' => $row['CODIGO'] ?? '?',
                                'warnings' => $validationResult->getWarnings(),
                            ]);
                        }

                        $syncResult = $this->processSingleCustomer($row);

                        switch ($syncResult) {
                            case 'created':
                                $result['created']++;
                                break;
                            case 'updated':
                                $result['updated']++;
                                break;
                            case 'skipped':
                                $result['skipped']++;
                                break;
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error(sprintf(
                            '[ERP] Customer sync error for code %s: %s',
                            $row['CODIGO'] ?? 'unknown',
                            $e->getMessage()
                        ));
                    }
                }

                $offset += self::BATCH_SIZE;
                $this->logger->info(sprintf('[ERP] Customer sync progress: %d/%d', min($offset, $totalCount), $totalCount));

                // Garbage collection
                if ($offset % 500 === 0) {
                    gc_collect_cycles();
                }
            }

            $this->syncLogResource->addLog(
                'customer',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    'Criados: %d, Atualizados: %d, Ignorados: %d, Erros: %d',
                    $result['created'],
                    $result['updated'],
                    $result['skipped'],
                    $result['errors']
                ),
                null,
                null,
                $result['created'] + $result['updated']
            );
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Customer sync failed: ' . $e->getMessage());
            $this->syncLogResource->addLog('customer', 'import', 'error', $e->getMessage());
        }

        return $result;
    }

    public function createOrUpdateCustomer(array $erpData, bool $createIfNotExists = true): ?CustomerInterface
    {
        $rawEmail = (string) ($erpData['EMAIL'] ?? '');
        $email = $this->normalizeEmail($rawEmail);
        if (empty($email)) {
            $this->logger->warning('[ERP] Cannot create customer with invalid email', [
                'raw_email' => $this->emailSanitizer->summarizeRaw($rawEmail),
                'erp_code' => $erpData['CODIGO'] ?? 0,
            ]);
            return null;
        }

        // Additional email validation - reject if it looks like invalid format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('[ERP] Invalid email format detected', [
                'email' => $email,
                'erp_code' => $erpData['CODIGO'] ?? 0,
            ]);
            return null;
        }

        // PHP's filter_var accepts single-char TLDs (e.g. .b) but Magento's Laminas
        // validator rejects them, causing an Exception. Pre-validate TLD length (≥2 chars).
        if (!preg_match('/\.[a-zA-Z]{2,}$/', $email)) {
            $this->logger->warning('[ERP] Email with invalid TLD rejected', [
                'email' => $email,
                'erp_code' => $erpData['CODIGO'] ?? 0,
            ]);
            return null;
        }

        $erpCode = (int)($erpData['CODIGO'] ?? 0);
        if ($erpCode === 0) {
            $this->logger->warning('[ERP] Cannot create customer without ERP code');
            return null;
        }

        try {
            $websiteId = (int) $this->storeManager->getDefaultStoreView()->getWebsiteId();

            // Tenta encontrar cliente existente
            $existingCustomer = $this->findExistingCustomer($email, $erpCode, $websiteId);

            if ($existingCustomer) {
                return $this->updateExistingCustomer($existingCustomer, $erpData);
            }

            if (!$createIfNotExists) {
                return null;
            }

            return $this->createNewCustomer($erpData, $websiteId);
        } catch (\Magento\Framework\Validator\ValidatorException $e) {
            $this->logger->warning('[ERP] Customer validation failed: ' . $e->getMessage(), [
                'erp_code' => $erpCode,
                'email'    => $email,
            ]);
            return null;
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Email/dados inválidos vindos do ERP são problemas de qualidade de dados, não erros de sistema
            $this->logger->warning('[ERP] Customer skipped — invalid data: ' . $e->getMessage(), [
                'erp_code' => $erpCode,
                'email'    => $email,
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Create/update customer failed: ' . $e->getMessage(), [
                'erp_code' => $erpCode,
                'email'    => $email,
                'cidade'   => $erpData['CIDADE'] ?? '',
                'fone'     => $erpData['FONE1'] ?? $erpData['FONECEL'] ?? '',
            ]);
            return null;
        }
    }

    public function syncCustomerAddresses(int $customerId, int $erpCode): bool
    {
        try {
            // Busca endereços do ERP
            $addresses = $this->getErpAddresses($erpCode);

            if (empty($addresses)) {
                return true; // Sem endereços não é erro
            }

            foreach ($addresses as $addressData) {
                $this->createOrUpdateAddress($customerId, $addressData);
            }

            $this->logger->info(sprintf('[ERP] Synced %d addresses for customer %d', count($addresses), $customerId));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[ERP] Address sync failed for customer %d: %s', $customerId, $e->getMessage()));
            return false;
        }
    }

    public function linkMagentoToErp(int $customerId, int $erpCode): bool
    {
        try {
            // Verifica se cliente existe no Magento
            $customer = $this->customerRepository->getById($customerId);

            // Verifica se código ERP é válido
            $erpCustomer = $this->getErpCustomerByCode($erpCode);
            if (!$erpCustomer) {
                throw new LocalizedException(__('Código ERP %1 não encontrado', $erpCode));
            }

            // Salva mapeamento
            $this->syncLogResource->setEntityMap(
                'customer',
                (string)$erpCode,
                $customerId,
                hash('xxh128', json_encode($erpCustomer))
            );

            // Atualiza atributo custom no cliente
            $customer->setCustomAttribute('erp_code', $erpCode);
            $this->customerRepository->save($customer);

            $this->logger->info(sprintf('[ERP] Linked Magento customer %d to ERP code %d', $customerId, $erpCode));
            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('[ERP] Failed to link customer %d to ERP: %s', $customerId, $e->getMessage()));
            return false;
        }
    }

    public function getErpCodeByCustomerId(int $customerId): ?int
    {
        // Tenta cache primeiro
        if (isset($this->erpMappingCache[$customerId])) {
            return $this->erpMappingCache[$customerId];
        }

        try {
            // Busca no mapeamento
            $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);

            if (!$erpCode) {
                // Tenta pelo atributo custom
                $customer = $this->customerRepository->getById($customerId);
                $erpCodeAttr = $customer->getCustomAttribute('erp_code');
                if ($erpCodeAttr) {
                    $erpCode = (int)$erpCodeAttr->getValue();
                }
            }

            if ($erpCode) {
                $this->erpMappingCache[$customerId] = (int)$erpCode;
                return (int)$erpCode;
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug('[ERP CustomerSync] getErpCodeByCustomerId failed for #' . $customerId . ': ' . $e->getMessage());
            return null;
        }
    }

    public function syncByTaxvat(string $taxvat): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'customer_id' => null,
            'erp_code' => null,
        ];

        $erpData = $this->getErpCustomerByTaxvat($taxvat);

        if (!$erpData) {
            $result['message'] = 'Cliente não encontrado no ERP com este CPF/CNPJ';
            return $result;
        }

        $customer = $this->createOrUpdateCustomer($erpData);

        if ($customer) {
            $result['success'] = true;
            $result['message'] = 'Cliente sincronizado com sucesso';
            $result['customer_id'] = (int)$customer->getId();
            $result['erp_code'] = (int)$erpData['CODIGO'];

            // Sincroniza endereços
            $this->syncCustomerAddresses((int)$customer->getId(), (int)$erpData['CODIGO']);
        } else {
            $result['message'] = 'Falha ao sincronizar cliente';
        }

        return $result;
    }

    /**
     * Get customer credit information from ERP
     *
     * @param string $erpCode
     * @return array|null
     */
    public function getCustomerCreditFromErp(string $erpCode): ?array
    {
        try {
            $sql = "SELECT f.CODIGO, f.VLRLIMCREDITO AS LIMITE_CREDITO,
                           CAST(NULL AS DECIMAL(18, 2)) AS SALDO_DEVEDOR,
                           CAST(NULL AS INT) AS DIAS_ATRASO,
                           CAST(NULL AS VARCHAR(1)) AS BLOQUEADO,
                           CAST(NULL AS VARCHAR(255)) AS MOTIVO_BLOQUEIO,
                           f.CONDPAGTO
                    FROM FN_FORNECEDORES f
                    WHERE f.CODIGO = :code AND f.CKCLIENTE = 'S' AND f.ATCLIENTE = 'S'";

            return $this->connection->fetchOne($sql, [':code' => (int)$erpCode]);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Credit lookup error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get customer by CNPJ/CPF
     */
    public function getErpCustomerByCnpj(string $cnpj): ?array
    {
        return $this->getErpCustomerByTaxvat($cnpj);
    }

    /**
     * Sync single customer by ERP code
     */
    public function syncByCode(string $code): bool
    {
        $erpData = $this->getErpCustomerByCode((int)$code);

        if (!$erpData) {
            $this->logger->warning("[ERP] Customer not found with code: {$code}");
            return false;
        }

        $customer = $this->createOrUpdateCustomer($erpData);

        if ($customer) {
            $this->syncCustomerAddresses((int)$customer->getId(), (int)$code);
            return true;
        }

        return false;
    }

    /**
     * Get ERP customer count (public)
     */
    public function getErpCustomerCount(): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM FN_FORNECEDORES f
                LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = f.CODIGO AND c.PRINCIPAL = 'S'
                WHERE f.CKCLIENTE = 'S' AND f.ATCLIENTE = 'S'
                  AND c.EMAIL IS NOT NULL AND c.EMAIL <> ''
                  AND c.EMAIL LIKE '%@%.%'";

        $result = $this->connection->fetchOne($sql, []);
        return (int)($result['total'] ?? $result['TOTAL'] ?? 0);
    }

    /**
     * Get ERP customers with pagination (public)
     */
    public function getErpCustomers(int $limit, int $offset): array
    {
        return $this->getErpCustomersBatch($offset, $limit);
    }

    // ==================== Private Methods ====================

    private function getErpCustomersBatch(int $offset, int $limit): array
    {
        // Note: OFFSET/FETCH requires integer literals in SQL Server, not parameters
        $sql = "SELECT f.CODIGO, f.RAZAO, f.FANTASIA, f.CGC, f.CPF,
                       f.ENDERECO, f.NUMERO, f.BAIRRO, f.CIDADE, f.CEP, f.UF,
                       f.CKPESSOA, f.CONDPAGTO, f.FATORPRECO, f.TRANSPPREF,
                       REPLACE(REPLACE(REPLACE(tp.CGC, '.', ''), '/', ''), '-', '') AS TRANSPPREF_CNPJ,
                       tp.RAZAO AS TRANSPPREF_NOME,
                       c.EMAIL, c.FONE1, c.FONECEL, c.NOME AS CONTATO_NOME
                FROM FN_FORNECEDORES f
                LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = f.CODIGO AND c.PRINCIPAL = 'S'
                LEFT JOIN FN_FORNECEDORES tp ON tp.CODIGO = f.TRANSPPREF AND tp.CKTRANSPORTADOR = 'S'
                WHERE f.CKCLIENTE = 'S' AND f.ATCLIENTE = 'S'
                  AND c.EMAIL IS NOT NULL AND c.EMAIL <> ''
                  AND c.EMAIL LIKE '%@%.%'
                ORDER BY f.CODIGO
                OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";

        return $this->connection->query($sql, []);
    }

    private function processSingleCustomer(array $row): string
    {
        $email = $this->normalizeEmail($row['EMAIL'] ?? '');
        if (empty($email)) {
            return 'skipped';
        }

        $erpCode = (int)$row['CODIGO'];
        $dataHash = hash('xxh128', json_encode($row));

        // Verifica se dados mudaram desde último sync
        $existingHash = $this->syncLogResource->getEntityMapHash('customer', (string)$erpCode);
        if ($existingHash === $dataHash) {
            return 'skipped'; // Sem mudanças
        }

        $customer = $this->createOrUpdateCustomer($row);

        if ($customer) {
            // Sincroniza endereço principal
            $this->syncCustomerAddresses((int)$customer->getId(), $erpCode);

            // Atualiza hash
            $this->syncLogResource->setEntityMap('customer', (string)$erpCode, (int)$customer->getId(), $dataHash);

            return $existingHash ? 'updated' : 'created';
        }

        return 'skipped';
    }

    private function findExistingCustomer(string $email, int $erpCode, int $websiteId): ?CustomerInterface
    {
        // Primeiro tenta pelo mapeamento ERP
        $mappedId = $this->syncLogResource->getEntityMap('customer', (string)$erpCode);
        if ($mappedId) {
            try {
                return $this->customerRepository->getById($mappedId);
            } catch (NoSuchEntityException $e) {
                $this->logger->warning('[CustomerSync::findExistingCustomer] Exceção ignorada: ' . $e->getMessage());
                // Mapeamento antigo, limpar
            }
        }

        // Depois tenta pelo email
        try {
            return $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function createNewCustomer(array $erpData, int $websiteId): ?CustomerInterface
    {
        $email = $this->normalizeEmail($erpData['EMAIL']);
        $isPJ = ($erpData['CKPESSOA'] ?? 'F') === 'J';

        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->setEmail($email);
        $customer->setStoreId($this->storeManager->getDefaultStoreView()->getId());

        // Nome
        $names = $this->parseCustomerName($erpData);
        $customer->setFirstname($names['firstname']);
        $customer->setLastname($names['lastname']);

        // Grupo de cliente (B2B se for pessoa jurídica)
        if ($isPJ) {
            $approvedGroupId = $this->resolveApprovedB2bGroupId();
            if ($approvedGroupId !== null) {
                $customer->setGroupId($approvedGroupId);
            }
            // Clientes criados pelo ERP sync já são clientes aprovados — garantir que o
            // atributo b2b_approval_status reflita isso para que PriceVisibility mostre preços.
            $customer->setCustomAttribute('b2b_approval_status', 'approved');
        }

        // CPF/CNPJ
        $taxvat = $isPJ ? ($erpData['CGC'] ?? '') : ($erpData['CPF'] ?? '');
        if ($taxvat) {
            $customer->setTaxvat($this->cleanTaxvat($taxvat));
        }

        // Atributos custom
        $customer->setCustomAttribute('erp_code', (int)$erpData['CODIGO']);

        if (!empty($erpData['INSCEST'])) {
            $customer->setCustomAttribute('inscricao_estadual', $erpData['INSCEST']);
        }

        $whatsapp = $erpData['WHATSAPP'] ?? '';
        $fonecel = $erpData['FONECEL'] ?? '';
        if (!empty($fonecel) || !empty($whatsapp)) {
            $phone = $whatsapp ?: $fonecel;
            $formattedPhone = $this->formatPhone($phone);
            if ($formattedPhone !== '') {
                $customer->setCustomAttribute('celular', $formattedPhone);
            }
        }

        // Transportadora preferencial do ERP
        $carrierCode = $this->resolveCarrierCode($erpData);
        if ($carrierCode) {
            $customer->setCustomAttribute('b2b_carrier_code', $carrierCode);
        }

        // Keep fiscal/company names separate from the buyer's personal name.
        $this->applyCompanyNameAttributes($customer, $erpData);

        // Salva cliente
        $savedCustomer = $this->customerRepository->save($customer);

        // Cria endereço principal
        $this->createPrimaryAddress((int)$savedCustomer->getId(), $erpData);

        $this->logger->info(sprintf(
            '[ERP] Created customer: %s (Magento ID: %d, ERP: %d)',
            $email,
            $savedCustomer->getId(),
            $erpData['CODIGO']
        ));

        return $savedCustomer;
    }

    private function updateExistingCustomer(CustomerInterface $customer, array $erpData): CustomerInterface
    {
        $updated = $this->applyCompanyNameAttributes($customer, $erpData);

        // Atualiza telefone se mudou
        $phone = $erpData['WHATSAPP'] ?? $erpData['FONECEL'] ?? '';
        if ($phone) {
            $formattedPhone = $this->formatPhone($phone);
            if ($formattedPhone !== '') {
                $existingPhone = $customer->getCustomAttribute('celular');
                if (!$existingPhone || $existingPhone->getValue() !== $formattedPhone) {
                    $customer->setCustomAttribute('celular', $formattedPhone);
                    $updated = true;
                }
            }
        }

        // Atualiza código ERP se não tiver
        $existingErpCode = $customer->getCustomAttribute('erp_code');
        if (!$existingErpCode || !$existingErpCode->getValue()) {
            $customer->setCustomAttribute('erp_code', (int)$erpData['CODIGO']);
            $updated = true;
        }

        // Corrige clientes em grupos B2B comerciais elegíveis ao Sectra
        // que ainda tenham b2b_approval_status='pending' por terem sido criados
        // antes desta correção ou por terem chegado via import sem o atributo.
        $approvedGroups = [];
        if ($this->customerGroupManager !== null) {
            foreach (B2bGroupIds::ELIGIBLE_GROUP_CODES as $groupCode) {
                $groupId = $this->customerGroupManager->getGroupIdByName($groupCode);
                if ($groupId !== null) {
                    $approvedGroups[] = $groupId;
                }
            }
        }
        if ($approvedGroups === []) {
            $approvedGroups = [4, 5, 6, 8];
        }
        if (in_array((int)$customer->getGroupId(), $approvedGroups, true)) {
            $approvalAttr = $customer->getCustomAttribute('b2b_approval_status');
            if (!$approvalAttr || $approvalAttr->getValue() !== 'approved') {
                $customer->setCustomAttribute('b2b_approval_status', 'approved');
                $updated = true;
            }
        }

        // Cliente que chegou pelo sync do ERP EXISTE no ERP por definicao — mas o atributo
        // erp_customer_sync_status nunca era gravado neste caminho (so a aprovacao e o
        // prospect o gravavam). Resultado medido em 03/10/2026: 181 clientes COM erp_code
        // e SEM o status. Espelha o bloco acima: preenche uma vez e para de reescrever.
        $syncStatusAttr = $customer->getCustomAttribute('erp_customer_sync_status');
        if (!$syncStatusAttr || trim((string)$syncStatusAttr->getValue()) === '') {
            $customer->setCustomAttribute(
                'erp_customer_sync_status',
                ErpCustomerSyncStatus::LINKED_EXISTING
            );
            $updated = true;
        }

        // Atualiza inscrição estadual
        if (!empty($erpData['INSCEST'])) {
            $existingIE = $customer->getCustomAttribute('inscricao_estadual');
            if (!$existingIE || $existingIE->getValue() !== $erpData['INSCEST']) {
                $customer->setCustomAttribute('inscricao_estadual', $erpData['INSCEST']);
                $updated = true;
            }
        }

        // Atualiza transportadora preferencial do ERP
        $carrierCode = $this->resolveCarrierCode($erpData);
        if ($carrierCode) {
            $existingCarrier = $customer->getCustomAttribute('b2b_carrier_code');
            if (!$existingCarrier || $existingCarrier->getValue() !== $carrierCode) {
                $customer->setCustomAttribute('b2b_carrier_code', $carrierCode);
                $updated = true;
            }
        }

        if ($updated) {
            $this->sanitizeDefaultBillingAddress((int)$customer->getId());
            return $this->customerRepository->save($customer);
        }

        return $customer;
    }

    /**
     * Apply ERP company names without changing personal names or persisting the customer.
     *
     * Existing customers must have matching fiscal identification; an email-only
     * match is not sufficient to replace the legal identity of a business.
     * Blank ERP fields never erase an already populated Magento attribute.
     *
     * @param CustomerInterface $customer
     * @param array $erpData
     * @return bool Whether a company-name attribute changed
     */
    public function applyCompanyNameAttributes(CustomerInterface $customer, array $erpData): bool
    {
        if (strtoupper(trim((string) ($erpData['CKPESSOA'] ?? ''))) !== 'J') {
            return false;
        }

        $erpCnpj = $this->cleanTaxvat((string) ($erpData['CGC'] ?? ''));
        $erpCode = trim((string) ($erpData['CODIGO'] ?? ''));
        if (strlen($erpCnpj) !== 14 || !ctype_digit($erpCode) || (int) $erpCode <= 0) {
            return false;
        }

        $codeAttribute = $customer->getCustomAttribute('erp_code');
        $customerErpCode = trim((string) ($codeAttribute ? $codeAttribute->getValue() : ''));
        if ($customerErpCode !== ''
            && (!ctype_digit($customerErpCode) || (int) $customerErpCode !== (int) $erpCode)
        ) {
            return false;
        }

        $cnpjAttribute = $customer->getCustomAttribute('b2b_cnpj');
        $documents = [
            trim((string) ($cnpjAttribute ? $cnpjAttribute->getValue() : '')),
            trim((string) $customer->getTaxvat()),
        ];
        $hasMatchingDocument = false;
        foreach ($documents as $document) {
            if ($document === '') {
                continue;
            }
            if ($this->cleanTaxvat($document) !== $erpCnpj) {
                return false;
            }
            $hasMatchingDocument = true;
        }
        if ((int) $customer->getId() > 0 && !$hasMatchingDocument) {
            return false;
        }

        $updated = false;
        foreach (['b2b_razao_social' => 'RAZAO', 'b2b_nome_fantasia' => 'FANTASIA'] as $attributeCode => $erpField) {
            $value = trim((string) ($erpData[$erpField] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = mb_substr($value, 0, 255);
            $attribute = $customer->getCustomAttribute($attributeCode);
            $currentValue = (string) ($attribute ? $attribute->getValue() : '');
            if ($currentValue !== $value) {
                $customer->setCustomAttribute($attributeCode, $value);
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Sanitize city and telephone on the default billing address.
     * Prevents Magento's City/Telephone validators from rejecting an existing
     * customer re-save due to stale address data imported before sanitisation
     * was in place.
     */
    private function sanitizeDefaultBillingAddress(int $customerId): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $defaultBillingId = $customer->getDefaultBilling();
            if ($defaultBillingId === null) {
                return;
            }

            $address = $this->addressRepository->getById((int)$defaultBillingId);
            $dirty   = false;

            $currentCity = (string)$address->getCity();
            $cleanCity   = $this->sanitizeCity($currentCity);
            if ($cleanCity !== $currentCity) {
                $address->setCity($cleanCity);
                $dirty = true;
            }

            $currentPhone = (string)$address->getTelephone();
            $cleanPhone   = $this->formatPhone($currentPhone) ?: '00000000000';
            if ($cleanPhone !== $currentPhone) {
                $address->setTelephone($cleanPhone);
                $dirty = true;
            }

            if ($dirty) {
                $this->addressRepository->save($address);
                $this->logger->info(sprintf(
                    '[ERP] Sanitised default billing address for customer %d (city: "%s"→"%s", phone: "%s"→"%s")',
                    $customerId,
                    $currentCity,
                    $cleanCity,
                    $currentPhone,
                    $cleanPhone
                ));
            }
        } catch (\Exception $e) {
            // Non-critical — log and continue
            $this->logger->warning(sprintf(
                '[ERP] Could not sanitise billing address for customer %d: %s',
                $customerId,
                $e->getMessage()
            ));
        }
    }

    private function createPrimaryAddress(int $customerId, array $erpData): void
    {
        if (empty($erpData['ENDERECO']) || empty($erpData['CIDADE'])) {
            return;
        }

        try {
            $address = $this->addressFactory->create();
            $address->setCustomerId($customerId);

            $names = $this->parseCustomerName($erpData);
            $address->setFirstname($names['firstname']);
            $address->setLastname($names['lastname']);

            // Monta endereço completo
            $street = [
                trim($erpData['ENDERECO']),
                trim($erpData['NUMERO'] ?? 'S/N'),
                trim($erpData['COMPLEMENTO'] ?? ''),
                trim($erpData['BAIRRO'] ?? ''),
            ];
            $address->setStreet(array_filter($street));

            $address->setCity($this->sanitizeCity(trim($erpData['CIDADE'])));
            $address->setPostcode($this->formatCep($erpData['CEP'] ?? ''));
            $address->setCountryId('BR');

            // Region
            $regionCode = strtoupper(trim($erpData['UF'] ?? 'SP'));
            $region = $this->getRegionByCode($regionCode);
            if ($region) {
                $address->setRegionId($region->getRegionId());
                $address->setRegion($region);
            }

            // Telefone
            $phone = $erpData['FONE1'] ?? $erpData['FONECEL'] ?? '';
            $address->setTelephone($this->formatPhone($phone) ?: '00000000000');

            // Define como padrão
            $address->setIsDefaultBilling(true);
            $address->setIsDefaultShipping(true);

            $this->addressRepository->save($address);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                '[ERP] Failed to create address for customer %d: %s',
                $customerId,
                $e->getMessage()
            ));
        }
    }

    private function createOrUpdateAddress(int $customerId, array $addressData): void
    {
        // Similar à createPrimaryAddress mas para endereços adicionais
        $this->createPrimaryAddress($customerId, $addressData);
    }

    /**
     * Resolve ERP TRANSPPREF to Magento carrier code (CNPJ_xxxx format)
     */
    private function resolveCarrierCode(array $erpData): ?string
    {
        $cnpj = trim($erpData['TRANSPPREF_CNPJ'] ?? '');
        if ($cnpj === '' || $cnpj === '0') {
            return null;
        }

        return 'CNPJ_' . $cnpj;
    }

    private function getErpAddresses(int $erpCode): array
    {
        // Busca endereços de entrega do ERP
        try {
            $sql = "SELECT e.ENDERECO, e.NUMERO, e.COMPLEMENTO, e.BAIRRO, e.CIDADE, e.CEP, e.UF,
                           e.TIPO, c.FONE1
                    FROM FN_ENDERECO e
                    LEFT JOIN FN_CONTATO c ON c.FORNECEDOR = e.FORNECEDOR AND c.PRINCIPAL = 'S'
                    WHERE e.FORNECEDOR = :code AND e.ATIVO = 'S'
                    ORDER BY e.TIPO";

            return $this->connection->query($sql, [':code' => $erpCode]);
        } catch (\Exception $e) {
            // Tabela pode não existir
            return [];
        }
    }

    private function parseCustomerName(array $erpData): array
    {
        $isPJ = ($erpData['CKPESSOA'] ?? 'F') === 'J';

        // Coleta nome fantasia, razão social e nome do contato
        $fantasia = trim($erpData['FANTASIA'] ?? '');
        $razao = trim($erpData['RAZAO'] ?? '');
        $contato = trim($erpData['CONTATO_NOME'] ?? '');

        if ($isPJ) {
            $name = $fantasia ?: $razao ?: 'Cliente';
        } else {
            $name = $contato ?: $razao ?: 'Cliente';
        }

        // Sanitiza: remove chars que Magento rejeita na validação de nome
        // Magento aceita apenas letras (incluindo acentuadas), espaços, hífens, pontos e apóstrofos
        $name = $this->sanitizeNameForMagento($name);

        $parts = preg_split('/\s+/', $name, 2);

        $firstname = trim($parts[0] ?? '');
        $lastname = isset($parts[1]) ? trim($parts[1]) : '';

        // Se só tem uma palavra, tenta a razão social como fallback para o sobrenome
        if ($lastname === '' && $isPJ) {
            $fallback = ($name === $this->sanitizeNameForMagento($fantasia)
                && $razao !== '' && $razao !== $fantasia)
                ? $this->sanitizeNameForMagento($razao) : '';
            if ($fallback !== '') {
                $fallbackParts = preg_split('/\s+/', $fallback, 2);
                $lastname = trim($fallbackParts[1] ?? $fallbackParts[0] ?? '');
            }
        }

        // Garante que firstname/lastname não estejam vazios (Magento obriga ambos)
        if ($firstname === '') {
            $firstname = 'Cliente';
        }
        if ($lastname === '') {
            $lastname = $isPJ ? 'LTDA' : 'Cliente';
        }

        return [
            'firstname' => mb_substr($firstname, 0, 255),
            'lastname' => mb_substr($lastname, 0, 255),
        ];
    }

    /**
     * Sanitize name to pass Magento's built-in validation
     *
     * Magento rejects names with numbers and most special characters.
     * ERP company names often contain numbers (e.g. "EMPRESA 123 LTDA").
     * We strip invalid chars and collapse whitespace.
     */
    private function sanitizeNameForMagento(string $name): string
    {
        // Remove control characters
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        // Remove characters Magento rejects: keep letters (including accented), spaces, hyphens, dots, apostrophes
        $name = preg_replace('/[^a-zA-Z\x{00C0}-\x{024F}\s\-.\' ]/u', '', $name);
        // Collapse multiple spaces
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    private function normalizeEmail(string $email): string
    {
        return $this->emailSanitizer->normalize($email);
    }

    private function cleanTaxvat(string $taxvat): string
    {
        return preg_replace('/[^0-9]/', '', $taxvat);
    }

    /**
     * Sanitize city name to pass Magento's City validator.
     * Pattern allowed: Unicode letters, marks, spaces, hyphens, apostrophes.
     * Digits and most special chars are stripped.
     */
    private function sanitizeCity(string $city): string
    {
        // Normalize double quotes to apostrophe (e.g. D"OESTE → D'OESTE)
        $city = str_replace('"', "'", $city);
        // Strip chars not in Magento's City validator pattern: \p{L}\p{M}\s\-'
        $city = preg_replace('/[^\p{L}\p{M}\s\-\']/u', ' ', $city);
        // Collapse multiple spaces
        $city = preg_replace('/\s+/', ' ', $city ?? '');
        $city = trim($city ?? '');
        return $city !== '' ? mb_substr($city, 0, 100) : 'Cidade';
    }

    private function formatPhone(string $phone): string
    {
        // ERP sometimes stores two numbers separated by '/' — use only the first
        if (str_contains($phone, '/')) {
            $phone = trim(explode('/', $phone)[0]);
        }

        $clean = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($clean === '') {
            return '';
        }

        // Remove prefixo internacional 55
        if (strlen($clean) >= 12 && str_starts_with($clean, '55')) {
            $clean = substr($clean, 2);
        }

        // Truncate to max 11 digits to satisfy Magento's phone length validator
        if (strlen($clean) > 11) {
            $clean = substr($clean, 0, 11);
        }

        if (strlen($clean) === 11) {
            return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 5), substr($clean, 7));
        } elseif (strlen($clean) === 10) {
            return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 4), substr($clean, 6));
        }

        return $clean;
    }

    private function formatCep(string $cep): string
    {
        $clean = preg_replace('/[^0-9]/', '', $cep);

        if (strlen($clean) === 8) {
            return sprintf('%s-%s', substr($clean, 0, 5), substr($clean, 5));
        }

        return $clean ?: '00000-000';
    }

    private function getRegionByCode(string $code): ?\Magento\Customer\Api\Data\RegionInterface
    {
        if (isset($this->regionCache[$code])) {
            return $this->regionCache[$code];
        }

        try {
            $region = $this->regionModelFactory->create();
            $region->loadByCode($code, 'BR');

            if ($region->getId()) {
                $regionInterface = $this->regionFactory->create();
                $regionInterface->setRegionId($region->getId());
                $regionInterface->setRegionCode($code);
                $regionInterface->setRegion($region->getName());

                $this->regionCache[$code] = $regionInterface;
                return $regionInterface;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[CustomerSync::getRegionByCode] Exceção ignorada: ' . $e->getMessage());
            // Região não encontrada
        }

        return null;
    }

    private function resolveApprovedB2bGroupId(): ?int
    {
        if ($this->customerGroupManager === null) {
            return null;
        }

        try {
            return $this->customerGroupManager->getGroupIdByName(CustomerGroupManager::GROUP_NAME_APPROVED);
        } catch (\Throwable $e) {
            $this->logger->warning('[ERP] Could not resolve approved B2B group dynamically: ' . $e->getMessage());
            return null;
        }
    }
}
