<?php

/**
 * Plugin to hide prices for non-logged users
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HidePricePlugin
{
    private PriceVisibilityInterface $priceVisibility;
    private LoggerInterface $logger;

    public function __construct(
        PriceVisibilityInterface $priceVisibility,
        ?LoggerInterface $logger = null
    ) {
        $this->priceVisibility = $priceVisibility;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * After getProductPrice - return message instead of price if not allowed
     */
    public function afterGetProductPrice(AbstractProduct $subject, string $result): string
    {
        try {
            // Evita duplicar marcador quando outro renderer/plugin ja substituiu o preco.
            if (strpos($result, 'b2b-login-to-see-price') !== false) {
                return $result;
            }

            if (!$this->priceVisibility->canViewPrices()) {
                return '<div class="b2b-login-to-see-price">'
                    . $this->priceVisibility->getPriceReplacementMessage()
                    . '</div>';
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B HidePricePlugin] Falha ao aplicar regra de visibilidade de preço.', [
                'exception' => $exception->getMessage(),
            ]);

            // Fail-closed: erro de visibilidade não pode vazar preço no HTML.
            return $this->safeReplacementHtml();
        }
    }

    private function safeReplacementHtml(): string
    {
        try {
            return '<div class="b2b-login-to-see-price">'
                . $this->priceVisibility->getPriceReplacementMessage()
                . '</div>';
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B HidePricePlugin] Falha ao montar mensagem de substituição.', [
                'exception' => $exception->getMessage(),
            ]);

            return '<div class="b2b-login-to-see-price">Entre ou cadastre-se para ver os preços.</div>';
        }
    }
}
