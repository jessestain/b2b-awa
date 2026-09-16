<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\ERPIntegration\Api\CustomerSyncInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\State as AppState;
use Psr\Log\LoggerInterface;

/**
 * Cron: Preventive auto-link of Magento customers to ERP by CPF/CNPJ.
 *
 * Finds customers that have a taxvat (CPF/CNPJ) but no erp_code attribute,
 * looks them up in Sectra's FN_FORNECEDORES (read-only) and persists the
 * mapping in entity_map + customer attribute for future order placement.
 *
 * Schedule: every 3 hours (0 * /3 * * *)
 * Batch: 200 customers per execution
 */
class ResolveCustomerErpCodes
{
    private const BATCH_SIZE = 200;

    private CustomerSyncInterface $customerSync;
    private CollectionFactory $customerCollectionFactory;
    private CustomerRepositoryInterface $customerRepository;
    private SyncLogResource $syncLogResource;
    private Helper $helper;
    private LoggerInterface $logger;
    private AppState $appState;

    public function __construct(
        CustomerSyncInterface $customerSync,
        CollectionFactory $customerCollectionFactory,
        CustomerRepositoryInterface $customerRepository,
        SyncLogResource $syncLogResource,
        Helper $helper,
        LoggerInterface $logger,
        AppState $appState
    ) {
        $this->customerSync = $customerSync;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->syncLogResource = $syncLogResource;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    public function execute(): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isCustomerSyncEnabled()) {
            return;
        }

        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception $e) {
            // Area already set
        }


        $customers = $this->getCustomersWithoutErpCode();
        $total = count($customers);

        if ($total === 0) {
            return;
        }

        $linked = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($customers as $customerData) {
            $customerId = (int) $customerData['entity_id'];
            $taxvat = (string) $customerData['taxvat'];

            try {
                $erpCustomer = $this->customerSync->getErpCustomerByTaxvat($taxvat);

                if (!$erpCustomer || empty($erpCustomer['CODIGO'])) {
                    $notFound++;
                    continue;
                }

                $erpCode = (int) $erpCustomer['CODIGO'];
                $result = $this->customerSync->linkMagentoToErp($customerId, $erpCode);

                if ($result) {
                    $linked++;
                    $this->logger->info('[ERP Cron] Auto-linked customer', [
                        'customer_id' => $customerId,
                        'erp_code' => $erpCode,
                        'razao' => $erpCustomer['RAZAO'] ?? '',
                    ]);
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->logger->warning('[ERP Cron] Auto-link failed for customer ' . $customerId, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('[ERP Cron] Customer ERP code resolution completed', [
            'total_processed' => $total,
            'linked' => $linked,
            'not_found_in_erp' => $notFound,
            'errors' => $errors,
        ]);

        $this->syncLogResource->addLog(
            'customer_auto_link',
            'sync',
            $errors > 0 ? 'partial' : 'success',
            sprintf(
                'Auto-link por CPF/CNPJ: %d processados, %d vinculados, %d não encontrados, %d erros',
                $total,
                $linked,
                $notFound,
                $errors
            )
        );
    }

    /**
     * Find customers that have taxvat filled but no erp_code attribute.
     * Uses raw collection to avoid loading full customer objects.
     *
     * @return array<int, array{entity_id: int, taxvat: string}>
     */
    private function getCustomersWithoutErpCode(): array
    {
        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['taxvat', 'erp_code']);
        $collection->addAttributeToFilter('taxvat', ['notnull' => true]);
        $collection->addAttributeToFilter('taxvat', ['neq' => '']);

        // Filter: erp_code is null or empty or zero
        $collection->addAttributeToFilter(
            [
                ['attribute' => 'erp_code', 'null' => true],
                ['attribute' => 'erp_code', 'eq' => ''],
                ['attribute' => 'erp_code', 'eq' => '0'],
            ]
        );

        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage(1);

        $result = [];
        foreach ($collection as $customer) {
            $taxvat = trim((string) $customer->getData('taxvat'));
            if (strlen($taxvat) >= 11) { // CPF=11, CNPJ=14
                $result[] = [
                    'entity_id' => (int) $customer->getId(),
                    'taxvat' => $taxvat,
                ];
            }
        }

        return $result;
    }
}
