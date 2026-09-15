<?php

/**
 * Observer: classify new B2B customers by CNAE code on registration
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use GrupoAwamotos\B2B\Helper\CnpjValidator;
use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\CnaeClassifier;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class CnaeClassificationObserver implements ObserverInterface
{
    /**
     * Guard against re-entrancy when this observer calls customerRepository::save().
     */
    private static bool $isProcessing = false;

    private Config $config;
    private CnaeClassifier $cnaeClassifier;
    private CustomerRepositoryInterface $customerRepository;
    private CnpjValidator $cnpjValidator;
    private CustomerApprovalInterface $customerApproval;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        CnaeClassifier $cnaeClassifier,
        CustomerRepositoryInterface $customerRepository,
        CnpjValidator $cnpjValidator,
        CustomerApprovalInterface $customerApproval,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->cnaeClassifier = $cnaeClassifier;
        $this->customerRepository = $customerRepository;
        $this->cnpjValidator = $cnpjValidator;
        $this->customerApproval = $customerApproval;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        if (self::$isProcessing) {
            return;
        }

        if (!$this->config->isCnaeProfilingEnabled()) {
            return;
        }

        try {
            // customer_save_after_data_object exposes customer_data_object (not "customer")
            $customerFromEvent = $observer->getEvent()->getCustomerDataObject();
            if (!$customerFromEvent instanceof CustomerInterface || !$customerFromEvent->getId()) {
                return;
            }

            $customerId = (int) $customerFromEvent->getId();

            // Guard: save() below re-dispatches this event — skip when already classified
            $existingCnae = trim((string) ($this->getCustomerAttributeValue($customerFromEvent, 'b2b_cnae_code') ?? ''));
            if ($existingCnae !== '') {
                return;
            }

            // Fresh load for complete custom attributes
            $customerData = $this->customerRepository->getById($customerId);
            $existingCnae = trim((string) ($this->getCustomerAttributeValue($customerData, 'b2b_cnae_code') ?? ''));
            if ($existingCnae !== '') {
                return;
            }

            $cnpj = $this->getCustomerAttributeValue($customerData, 'b2b_cnpj');
            if ($cnpj === null || $cnpj === '') {
                return;
            }

            $cnpjDigits = preg_replace('/\D/', '', $cnpj);
            if ($cnpjDigits === null || $cnpjDigits === '') {
                return;
            }

            // Prefer cache from registration CNPJ validation; may hit BrasilAPI on rate-limit
            $apiData = $this->cnpjValidator->validateApi($cnpjDigits);

            if ($apiData === null || empty($apiData['data']) || !is_array($apiData['data'])) {
                $this->logger->info(sprintf(
                    'B2B CNAE: No API data available for customer #%d (CNPJ: %s)',
                    $customerId,
                    $cnpjDigits
                ));
                return;
            }

            if (isset($apiData['valid']) && $apiData['valid'] === false) {
                $this->logger->info(sprintf(
                    'B2B CNAE: API marked CNPJ invalid for customer #%d (CNPJ: %s)',
                    $customerId,
                    $cnpjDigits
                ));
                return;
            }

            $rawData = $apiData['data'];
            $cnaeCode = $this->cnaeClassifier->extractCnaeCode($rawData);
            $cnaeDescription = $this->cnaeClassifier->extractCnaeDescription($rawData);

            if ($cnaeCode === '') {
                $this->logger->info(sprintf(
                    'B2B CNAE: No CNAE code found for customer #%d (CNPJ: %s)',
                    $customerId,
                    $cnpjDigits
                ));
                return;
            }

            $profile = $this->cnaeClassifier->classify($cnaeCode);

            self::$isProcessing = true;
            try {
                $customerData->setCustomAttribute('b2b_cnae_code', $cnaeCode);
                $customerData->setCustomAttribute('b2b_cnae_description', $cnaeDescription);
                $customerData->setCustomAttribute('b2b_cnae_profile', $profile);
                $this->customerRepository->save($customerData);
            } finally {
                self::$isProcessing = false;
            }

            $this->logger->info(sprintf(
                'B2B CNAE: Customer #%d classified as "%s" (CNAE: %s - %s)',
                $customerId,
                $this->cnaeClassifier->getProfileLabel($profile),
                $cnaeCode,
                $cnaeDescription
            ));

            if ($profile === CnaeClassifier::PROFILE_DIRECT
                && $this->cnaeClassifier->isAutoApproveDirectEnabled()
            ) {
                $this->customerApproval->approveCustomer(
                    $customerId,
                    null,
                    sprintf('Auto-aprovado por CNAE: %s (%s)', $cnaeCode, $cnaeDescription)
                );

                $this->logger->info(sprintf(
                    'B2B CNAE: Customer #%d auto-approved (direct profile, CNAE: %s)',
                    $customerId,
                    $cnaeCode
                ));
            }
        } catch (\Exception $e) {
            self::$isProcessing = false;
            $this->logger->error(
                'B2B CnaeClassificationObserver error: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    private function getCustomerAttributeValue(CustomerInterface $customer, string $attributeCode): ?string
    {
        $attribute = $customer->getCustomAttribute($attributeCode);
        return $attribute ? (string) $attribute->getValue() : null;
    }
}
