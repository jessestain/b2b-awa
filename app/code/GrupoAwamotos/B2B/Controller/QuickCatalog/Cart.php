<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\CartService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Snapshot somente leitura do carrinho real (reconciliação após resposta incerta).
 */
class Cart implements HttpGetActionInterface
{
    /**
     * Inicializa o controller com as dependências de carrinho e autorização.
     *
     * @param JsonFactory $jsonFactory
     * @param AuthorizationService $authorizationService
     * @param CartService $cartService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly AuthorizationService $authorizationService,
        private readonly CartService $cartService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Devolve o snapshot somente leitura do carrinho real em JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if (method_exists($result, 'setHeader')) {
            $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        }

        $context = $this->authorizationService->resolve();

        if (!$context['allowed']) {
            return $result->setData([
                'success' => false,
                'reason' => $context['reason'],
                'message' => $context['message'],
            ]);
        }

        try {
            $snapshot = $this->cartService->snapshot();
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Cart endpoint failed.', [
                'customer_id' => (int) $context['customer_id'],
                'exception' => $exception->getMessage(),
            ]);

            return $result->setData([
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível consultar o carrinho agora.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'cart' => [
                'items_count' => $snapshot['items_count'],
                'total_units' => $snapshot['total_units'],
                'distinct_skus' => $snapshot['distinct_skus'],
                'items' => $snapshot['items'],
                'subtotal' => $snapshot['subtotal'],
                'subtotal_value' => $snapshot['subtotal_value'],
                'shipping_unknown' => true,
            ],
        ]);
    }
}
