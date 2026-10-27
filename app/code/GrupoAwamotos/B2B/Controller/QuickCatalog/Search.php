<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AccountKeyProvider;
use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\ProductListService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Busca/filtros/paginação do catálogo de compra rápida — sempre no servidor.
 * Resposta nunca cacheável (preços e catálogo por cliente).
 */
class Search implements HttpGetActionInterface
{
    /**
     * Inicializa o controller com as dependências de busca do catálogo.
     *
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param AuthorizationService $authorizationService
     * @param ProductListService $productListService
     * @param AccountKeyProvider $accountKeyProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly AuthorizationService $authorizationService,
        private readonly ProductListService $productListService,
        private readonly AccountKeyProvider $accountKeyProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Devolve a página de produtos encontrada para a busca em JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $this->noStore($result);

        $context = $this->authorizationService->resolve();

        if (!$context['allowed']) {
            return $result->setData([
                'success' => false,
                'reason' => $context['reason'],
                'message' => $context['message'],
            ]);
        }

        try {
            $payload = $this->productListService->search(
                [
                    'q' => (string) $this->request->getParam('q', ''),
                    'cat' => $this->request->getParam('cat'),
                    'page' => (int) $this->request->getParam('page', 1),
                    'limit' => (int) $this->request->getParam('limit', 24),
                    'sort' => (string) $this->request->getParam('sort', 'relevance'),
                    'view' => (string) $this->request->getParam('view', ProductListService::VIEW_CATALOG),
                ],
                $context
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Search endpoint failed.', [
                'customer_id' => (int) $context['customer_id'],
                'exception' => $exception->getMessage(),
            ]);
            $payload = [
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível carregar os produtos agora.'),
            ];
        }

        $payload['account_key'] = $this->accountKeyProvider->getAccountKey((int) $context['customer_id']);

        return $result->setData($payload);
    }

    /**
     * Aplica cabeçalho de resposta não cacheável ao resultado.
     *
     * @param Json $result
     */
    private function noStore(Json $result): void
    {
        if (method_exists($result, 'setHeader')) {
            $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        }
    }
}
