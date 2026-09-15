<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use Magento\Checkout\Controller\Cart\Index;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

/**
 * Redireciona carrinho vazio para a página de sucesso quando a sessão de checkout ainda é válida.
 */
class RedirectCartToSuccessPlugin
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly SuccessValidator $successValidator,
        private readonly RedirectFactory $redirectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Index $subject
     * @param callable $proceed
     * @return Redirect|mixed
     */
    public function aroundExecute(Index $subject, callable $proceed)
    {
        try {
            if (!$this->checkoutSession->getQuote()->hasItems() && $this->successValidator->isValid()) {
                /** @var Redirect $redirect */
                $redirect = $this->redirectFactory->create();

                return $redirect->setPath('checkout/onepage/success');
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                '[RedirectCartToSuccessPlugin::aroundExecute] Sessão/quote indisponível: ' . $e->getMessage()
            );
        }

        return $proceed();
    }
}
