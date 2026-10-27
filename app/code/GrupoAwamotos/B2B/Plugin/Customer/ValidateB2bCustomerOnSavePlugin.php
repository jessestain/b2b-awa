<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Customer;

use GrupoAwamotos\B2B\Model\Registration\B2bRegistrationGuard;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Enforces B2B registration completeness on new customer saves only.
 * Existing legacy customers are never blocked or overwritten.
 *
 * C2 (19/10/2026): gate de area — valida apenas em frontend, webapi_rest, graphql e adminhtml.
 * Saves de cron (sync Sectra/ERP, bridge, reindexacoes) seguem sem validacao para nao quebrar
 * integracoes que alimentam dados vindos do ERP.
 */
class ValidateB2bCustomerOnSavePlugin
{
    /** @var list<string> areas onde a completude do cadastro e exigida */
    private const ENFORCED_AREAS = [
        Area::AREA_FRONTEND,
        Area::AREA_WEBAPI_REST,
        Area::AREA_GRAPHQL,
        Area::AREA_ADMINHTML,
    ];

    public function __construct(
        private readonly B2bRegistrationGuard $registrationGuard,
        private readonly State $appState
    ) {
    }

    /**
     * @throws InputException
     */
    public function beforeSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer,
        $passwordHash = null
    ): array {
        if (!$this->isEnforcedArea()) {
            return [$customer, $passwordHash];
        }

        if (!$this->registrationGuard->isB2bCustomer($customer)) {
            return [$customer, $passwordHash];
        }

        if (!$this->registrationGuard->isNewCustomer($customer)) {
            return [$customer, $passwordHash];
        }

        $this->registrationGuard->applyNewRegistrationDefaults($customer);
        $this->registrationGuard->validateNewRegistration($customer);

        return [$customer, $passwordHash];
    }

    private function isEnforcedArea(): bool
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            // Sem area definida (ex.: contexto de cron/CLI) — nao validar.
            return false;
        }

        return in_array($area, self::ENFORCED_AREAS, true);
    }
}
