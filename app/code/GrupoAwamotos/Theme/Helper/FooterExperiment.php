<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Helper;

use GrupoAwamotos\Theme\Model\FooterExperimentDecider;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Store\Model\ScopeInterface;

class FooterExperiment extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'grupoawamotos_theme/footer_experiment/enabled';
    private const XML_PATH_ROLLOUT_PERCENTAGE = 'grupoawamotos_theme/footer_experiment/rollout_percentage';
    private const XML_PATH_VARIANT_SEED = 'grupoawamotos_theme/footer_experiment/variant_seed';

    /** @var array<string, bool> */
    private array $enabledCache = [];

    /** @var array<string, int> */
    private array $rolloutCache = [];

    /** @var array<string, string> */
    private array $variantSeedCache = [];

    /** @var array<string, array<string, int|string|bool>> */
    private array $payloadCache = [];

    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly SessionManagerInterface $sessionManager,
        private readonly FooterExperimentDecider $decider,
        private readonly PhpCookieManager $cookieManager
    ) {
        parent::__construct($context);
    }

    public function isEnabled(?int $storeId = null): bool
    {
        $storeKey = $this->resolveStoreKey($storeId);
        if (isset($this->enabledCache[$storeKey])) {
            return $this->enabledCache[$storeKey];
        }

        $this->enabledCache[$storeKey] = $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $this->enabledCache[$storeKey];
    }

    public function getRolloutPercentage(?int $storeId = null): int
    {
        $storeKey = $this->resolveStoreKey($storeId);
        if (isset($this->rolloutCache[$storeKey])) {
            return $this->rolloutCache[$storeKey];
        }

        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_ROLLOUT_PERCENTAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $this->rolloutCache[$storeKey] = $this->decider->normalizeRolloutPercentage($value);

        return $this->rolloutCache[$storeKey];
    }

    public function getVariantCode(?int $storeId = null): string
    {
        return $this->decider->getDefaultVariantCode();
    }

    public function getVariantSeed(?int $storeId = null): string
    {
        $storeKey = $this->resolveStoreKey($storeId);
        if (isset($this->variantSeedCache[$storeKey])) {
            return $this->variantSeedCache[$storeKey];
        }

        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_VARIANT_SEED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $this->variantSeedCache[$storeKey] = $this->decider->normalizeVariantSeed($value);

        return $this->variantSeedCache[$storeKey];
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function getPayload(?int $storeId = null): array
    {
        $storeKey = $this->resolveStoreKey($storeId);
        if (isset($this->payloadCache[$storeKey])) {
            return $this->payloadCache[$storeKey];
        }

        $this->payloadCache[$storeKey] = $this->decider->decide(
            $this->resolveVisitorSeed(),
            $this->getVariantSeed($storeId),
            $this->isEnabled($storeId),
            $this->getRolloutPercentage($storeId),
            $this->getVariantCode($storeId)
        );

        return $this->payloadCache[$storeKey];
    }

    private function resolveStoreKey(?int $storeId): string
    {
        return $storeId !== null ? (string) $storeId : 'default';
    }

    private function resolveVisitorSeed(): string
    {
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return 'guest:anonymous';
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId > 0) {
            return 'customer:' . $customerId;
        }

        $sessionId = trim((string) $this->sessionManager->getSessionId());
        if ($sessionId !== '') {
            return 'session:' . $sessionId;
        }

        return 'guest:anonymous';
    }
}
