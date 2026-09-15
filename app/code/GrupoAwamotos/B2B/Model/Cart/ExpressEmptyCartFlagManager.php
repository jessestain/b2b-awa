<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Cart;

use GrupoAwamotos\B2B\ViewModel\Cart\EmptyCartContext;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

class ExpressEmptyCartFlagManager
{
    private bool $cookieQueued = false;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PhpCookieManager $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Marca que o comprador tentou checkout expresso sem itens no carrinho.
     */
    public function markWhenQuoteEmpty(): bool
    {
        try {
            if ($this->checkoutSession->getQuote()->hasItems()) {
                return false;
            }
        } catch (\Exception) {
            return false;
        }

        $this->checkoutSession->setData(EmptyCartContext::SESSION_KEY_EXPRESS_REDIRECT, true);
        $this->setCookie();

        return true;
    }

    private function setCookie(): void
    {
        if ($this->cookieQueued) {
            return;
        }

        try {
            if ($this->cookieManager->getCookie(EmptyCartContext::COOKIE_KEY_EXPRESS_REDIRECT) === '1') {
                $this->cookieQueued = true;

                return;
            }

            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $metadata->setDuration(300);
            $metadata->setPath('/');
            $metadata->setHttpOnly(false);
            $metadata->setSecure(true);
            $metadata->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(
                EmptyCartContext::COOKIE_KEY_EXPRESS_REDIRECT,
                '1',
                $metadata
            );
            $this->cookieQueued = true;
        } catch (\Exception $e) {
            $this->logger->debug(
                '[ExpressEmptyCartFlagManager::setCookie] Cookie opcional falhou, '
                . 'sessão checkout é o fallback: ' . $e->getMessage()
            );
        }
    }
}
