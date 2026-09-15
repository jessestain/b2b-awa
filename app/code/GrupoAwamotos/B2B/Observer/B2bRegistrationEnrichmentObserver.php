<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Registration\AttributionData;
use GrupoAwamotos\B2B\Model\Registration\AttributionResolver;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Assigns vendedor responsável and logs attribution after B2B registration.
 */
class B2bRegistrationEnrichmentObserver implements ObserverInterface
{
    public function __construct(
        private readonly AttributionResolver $attributionResolver,
        private readonly AttendantManager $attendantManager,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getCustomer();
        if (!$customer instanceof CustomerInterface || !$customer->getId()) {
            return;
        }

        if (!$this->isB2bRegistration($customer)) {
            return;
        }

        $customerId = (int) $customer->getId();
        $attribution = $this->attributionResolver->fromRequest($this->request);

        try {
            $this->assignResponsibleSeller($customerId, $attribution);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Register] Falha ao atribuir vendedor; cadastro preservado', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);
            // #region agent log
            $this->agentDebugLog('P0', 'assign-seller-error', [
                'customerId' => $customerId,
                'error' => $e->getMessage(),
            ]);
            // #endregion
        }

        $this->logger->info('[B2B Register] Cadastro enriquecido', [
            'customer_id' => $customerId,
            'campaign' => $attribution->campaignName,
            'utm_source' => $attribution->utmSource,
            'utm_medium' => $attribution->utmMedium,
            'attendant_id' => $attribution->attendantId,
            'erp_seller_code' => $attribution->erpSellerCode,
        ]);
    }

    private function isB2bRegistration(CustomerInterface $customer): bool
    {
        $cnpj = $customer->getCustomAttribute('b2b_cnpj');

        return $cnpj !== null && trim((string) $cnpj->getValue()) !== '';
    }

    private function assignResponsibleSeller(int $customerId, AttributionData $attribution): void
    {
        if ($this->attendantManager->getCustomerAttendant($customerId) !== null) {
            return;
        }

        $attendantId = $this->attendantManager->resolveAttendantIdFromReferral(
            $attribution->attendantId,
            $attribution->erpSellerCode
        );

        // #region agent log
        $this->agentDebugLog('P0', 'assign-seller', [
            'customerId' => $customerId,
            'resolvedAttendantId' => $attendantId,
            'hasReferral' => $attribution->hasAttendantReferral(),
        ]);
        // #endregion

        if ($attendantId !== null) {
            $this->attendantManager->assignCustomerToAttendant(
                $customerId,
                $attendantId,
                $attribution->hasAttendantReferral()
                    ? 'Cadastro B2B — vendedor indicado na URL/campanha'
                    : 'Cadastro B2B — distribuição automática'
            );
            return;
        }

        $this->attendantManager->autoAssignCustomer($customerId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function agentDebugLog(string $hypothesisId, string $message, array $data): void
    {
        $payload = json_encode([
            'sessionId' => '419192',
            'runId' => 'post-fix',
            'hypothesisId' => $hypothesisId,
            'location' => 'B2bRegistrationEnrichmentObserver.php',
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return;
        }
        @file_put_contents(
            '/home/jessessh/htdocs/srv1113343.hstgr.cloud/.cursor/debug-419192.log',
            $payload . "\n",
            FILE_APPEND
        );
    }
}
