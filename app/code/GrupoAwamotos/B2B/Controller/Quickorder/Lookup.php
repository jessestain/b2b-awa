<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Quickorder;

use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\ProductListService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Pedido Rápido — consulta de produto por SKU (preview da linha).
 *
 * Missão estabilização 2026-10-22 (P2): o formulário de SKU não apresentava
 * nenhum detalhe do produto antes da inclusão. Este endpoint devolve nome,
 * imagem, preço autorizado, disponibilidade e regras de quantidade do SKU
 * informado, reutilizando o mesmo pipeline do Catálogo de Compra Rápida
 * (AuthorizationService + ProductListService) — mesma política de preço por
 * cliente, mesmo fail-closed e mesma negação de OEM exclusivo.
 */
class Lookup implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly AuthorizationService $authorizationService,
        private readonly ProductListService $productListService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $context = $this->authorizationService->resolve();
        if (!$context['allowed']) {
            return $result->setData([
                'success' => false,
                'reason' => $context['reason'],
                'message' => $context['message'],
            ]);
        }

        $sku = trim((string) $this->request->getParam('q', ''));
        if ($sku === '') {
            return $result->setData([
                'success' => false,
                'reason' => 'empty_sku',
                'message' => (string) __('Informe o SKU do produto.'),
            ]);
        }

        try {
            $payload = $this->productListService->search(
                [
                    'q' => $sku,
                    'page' => 1,
                    'limit' => 10,
                    'view' => ProductListService::VIEW_CATALOG,
                ],
                $context
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickOrder] Lookup endpoint failed.', [
                'customer_id' => (int) $context['customer_id'],
                'exception' => $exception->getMessage(),
            ]);

            return $result->setData([
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível consultar o produto agora. Tente novamente.'),
            ]);
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $needle = mb_strtolower($sku, 'UTF-8');
        $exact = null;

        foreach ($items as $item) {
            if (mb_strtolower(trim((string) ($item['sku'] ?? '')), 'UTF-8') === $needle) {
                $exact = $item;
                break;
            }
        }

        if ($exact === null) {
            return $result->setData([
                'success' => false,
                'found' => false,
                'reason' => 'not_found',
                'message' => (string) __('SKU não encontrado ou sem preço autorizado para a sua conta.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'found' => true,
            'item' => $exact,
        ]);
    }
}
