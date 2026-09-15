<?php

/**
 * Plugin to block Rokanthemes OnePageCheckout for non-approved customers
 * and enforce minimum order amount.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Helper\LoginRefererParams;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use Rokanthemes\OnePageCheckout\Controller\Index\Index;

class BlockExpressCheckoutPlugin
{
    /**
     * @var PriceVisibilityInterface
     */
    private $priceVisibility;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var LoginRefererParams
     */
    private $loginRefererParams;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        PriceVisibilityInterface $priceVisibility,
        Config $config,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        LoginRefererParams $loginRefererParams,
        LoggerInterface $logger
    ) {
        $this->priceVisibility = $priceVisibility;
        $this->config = $config;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->loginRefererParams = $loginRefererParams;
        $this->logger = $logger;
    }

    /**
     * Around execute - block express checkout if not approved or below minimum
     *
     * @param Index $subject
     * @param callable $proceed
     * @return \Magento\Framework\Controller\Result\Redirect|mixed
     */
    public function aroundExecute(Index $subject, callable $proceed)
    {
        if (!$this->config->isEnabled()) {
            return $proceed();
        }

        // No modo mixed, visitantes podem fazer checkout normalmente
        if (!$this->customerSession->isLoggedIn() && !$this->config->isStrictB2B()) {
            return $proceed();
        }

        // Verificar se cliente está aprovado
        if (!$this->priceVisibility->canAddToCart()) {
            $redirect = $this->redirectFactory->create();

            if (!$this->customerSession->isLoggedIn()) {
                $this->messageManager->addNoticeMessage(
                    __('Faça login no portal B2B para finalizar sua compra.')
                );
                return $redirect->setPath('b2b/account/login', $this->loginRefererParams->toLoginRedirectParams());
            }

            // Cliente logado mas bloqueado: diferenciar aprovado sem ERP de pendente
            if ($this->priceVisibility->isApprovedPendingErp()) {
                $this->messageManager->addWarningMessage(
                    trim(strip_tags($this->priceVisibility->getPriceReplacementMessage()))
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __('Sua conta está pendente de aprovação. Você não pode finalizar compras até que sua conta seja aprovada.')
                );
            }

            return $redirect->setPath('b2b/account/dashboard');
        }

        // Verificar valor mínimo do pedido
        if ($this->config->isMinQtyEnabled()) {
            $minAmount = $this->config->getMinOrderAmount();
            if ($minAmount > 0) {
                try {
                    $quote = $this->checkoutSession->getQuote();
                    $subtotal = (float) $quote->getBaseSubtotal();

                    if ($subtotal < $minAmount) {
                        $this->messageManager->addWarningMessage(
                            __($this->config->getMinOrderMessage())
                        );
                        $redirect = $this->redirectFactory->create();
                        return $redirect->setPath('checkout/cart');
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        '[BlockExpressCheckoutPlugin::aroundExecute] Exceção ignorada: ' . $e->getMessage()
                    );
                }
            }
        }

        return $proceed();
    }
}
