<?php

/**
 * Plugin to hide final price box for non-logged users
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use Magento\Catalog\Pricing\Render\FinalPriceBox;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HideFinalPricePlugin
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
     * Around toHtml - replace price with message if not allowed
     *
     * @param FinalPriceBox $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundToHtml(FinalPriceBox $subject, callable $proceed)
    {
        try {
            if (!$this->priceVisibility->canViewPrices()) {
                $replacementMessage = $this->priceVisibility->getPriceReplacementMessage();

                // Sem wrapper .price-label: evita texto duplicado no a11y e CTA falso via CSS ::after.
                return '<div class="b2b-login-to-see-price price-box" data-awa-b2b-price-gate="1">'
                    . $replacementMessage
                    . '</div>';
            }

            return $proceed();
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B HideFinalPricePlugin] Falha ao aplicar regra de preço final.', [
                'exception' => $exception->getMessage(),
            ]);

            // Fail-closed: erro de visibilidade não pode vazar o HTML do preço.
            return $this->safeReplacementHtml();
        }
    }

    private function safeReplacementHtml(): string
    {
        try {
            $replacementMessage = $this->priceVisibility->getPriceReplacementMessage();
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B HideFinalPricePlugin] Falha ao montar mensagem de substituição.', [
                'exception' => $exception->getMessage(),
            ]);
            $replacementMessage = 'Entre ou cadastre-se para ver os preços.';
        }

        return '<div class="b2b-login-to-see-price price-box" data-awa-b2b-price-gate="1">'
            . $replacementMessage
            . '</div>';
    }
}
