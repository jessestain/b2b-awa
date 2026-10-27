<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AccountKeyProvider;
use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\BuyAgainService;
use GrupoAwamotos\B2B\Model\QuickCatalog\RequestInputNormalizer;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * "Comprar novamente" — pedidos reais do cliente, preços e disponibilidade ATUAIS.
 * Resposta nunca cacheável (dados por cliente). GET read-only, sem form-key.
 */
class BuyAgain implements HttpGetActionInterface
{
    /**
     * Inicializa o controller com as dependências de comprar novamente.
     *
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param AuthorizationService $authorizationService
     * @param BuyAgainService $buyAgainService
     * @param AccountKeyProvider $accountKeyProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly AuthorizationService $authorizationService,
        private readonly BuyAgainService $buyAgainService,
        private readonly AccountKeyProvider $accountKeyProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Devolve os produtos comprados anteriormente pelo cliente em JSON.
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

        $page = (int) $this->request->getParam('page', 1);
        $limit = (int) $this->request->getParam('limit', RequestInputNormalizer::DEFAULT_PAGE_SIZE);

        try {
            $payload = $this->buyAgainService->getBuyAgain($context, $page, $limit);
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Buy again endpoint failed.', [
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
