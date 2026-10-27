<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

/**
 * Contexto de autorização do Catálogo de Compra Rápida.
 *
 * Reutiliza SEM ALTERAR os componentes compartilhados de autorização/preço:
 * - PriceVisibilityInterface (mesma política de preço/carrinho da vitrine);
 * - Helper\Config (flags B2B);
 * - sessão de cliente do Magento.
 *
 * Fail-closed: qualquer dúvida/erro = acesso negado com motivo explícito.
 */
class AuthorizationService
{
    public const REASON_LOGIN_REQUIRED = 'login_required';
    public const REASON_NOT_APPROVED = 'not_approved';
    public const REASON_ERP_PENDING = 'erp_pending';
    public const REASON_NOT_B2B = 'not_b2b';
    public const REASON_DISABLED = 'disabled';
    public const REASON_CANNOT_ADD = 'cannot_add';
    public const REASON_ERROR = 'error';

    /**
     * Inicializa o serviço com sessão, configuração e política de preços.
     *
     * @param CustomerSession $customerSession
     * @param Config $config
     * @param PriceVisibilityInterface $priceVisibility
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly PriceVisibilityInterface $priceVisibility,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve o contexto de autorização da conta atual.
     *
     * @return array{
     *   allowed: bool,
     *   reason: string|null,
     *   message: string,
     *   customer_id: int,
     *   can_view_prices: bool,
     *   can_add: bool
     * }
     */
    public function resolve(): array
    {
        $context = [
            'allowed' => false,
            'reason' => null,
            'message' => '',
            'customer_id' => 0,
            'can_view_prices' => false,
            'can_add' => false,
        ];

        if (!$this->isLoggedIn()) {
            $context['reason'] = self::REASON_LOGIN_REQUIRED;
            $context['message'] = (string) __('Faça login na sua conta B2B para comprar.');
            return $context;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $context['customer_id'] = $customerId;

        if (!$this->config->isEnabled()) {
            $context['reason'] = self::REASON_DISABLED;
            $context['message'] = (string) __('Catálogo B2B indisponível no momento.');
            return $context;
        }

        try {
            $canViewPrices = $this->priceVisibility->canViewPrices();
            $canAdd = $this->priceVisibility->canAddToCart();
        } catch (\Throwable $exception) {
            // Fail-closed: não libera catálogo/preços se a política falhar.
            $this->logger->error('[B2B QuickCatalog] Price visibility check failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);

            $context['reason'] = self::REASON_ERROR;
            $context['message'] = (string) __(
                'Não foi possível validar sua conta agora. Tente novamente em instantes.'
            );
            return $context;
        }

        $context['can_view_prices'] = $canViewPrices;
        $context['can_add'] = $canAdd;

        if ($canViewPrices) {
            $context['allowed'] = true;
            return $context;
        }

        // Motivo explícito para a interface (mesma copy da vitrine quando existir).
        try {
            if ($this->priceVisibility->isApprovedPendingErp()) {
                $context['reason'] = self::REASON_ERP_PENDING;
            } elseif ($this->priceVisibility->isCustomerApproved()) {
                $context['reason'] = self::REASON_NOT_APPROVED;
            } else {
                $context['reason'] = self::REASON_NOT_APPROVED;
            }

            $context['message'] = (string) $this->priceVisibility->getPriceReplacementMessage();
        } catch (\Throwable $exception) {
            $context['reason'] = self::REASON_ERROR;
            $context['message'] = (string) __(
                'Não foi possível validar sua conta agora. Tente novamente em instantes.'
            );
            $this->logger->error('[B2B QuickCatalog] Approval status resolution failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $context;
    }

    /**
     * Indica se há cliente autenticado na sessão.
     *
     * @return bool
     */
    private function isLoggedIn(): bool
    {
        // Evita session_start() desnecessário em páginas FPC-safe; aqui a rota é
        // sempre autenticada, então a checagem direta é a correta.
        return $this->customerSession->isLoggedIn();
    }
}
