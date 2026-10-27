<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\FavoriteService;
use GrupoAwamotos\B2B\Model\QuickCatalog\RequestInputNormalizer;
use GrupoAwamotos\B2B\Model\QuickCatalog\ProductAccessValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Toggle de favorito (POST, form_key obrigatório).
 *
 * Ordem de validação (fail-closed): form_key → product_id > 0 → autorização →
 * produto existe (NoSuchEntity → invalid_product) → isVisibleInCatalog (regras
 * de catálogo SEM exigência de saleable; negado → not_available) → toggle na
 * tabela de favoritos. Resposta nunca cacheável.
 */
class Favorite implements HttpPostActionInterface
{
    /**
     * Inicializa o controller com as dependências de toggle de favorito.
     *
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param FormKeyValidator $formKeyValidator
     * @param AuthorizationService $authorizationService
     * @param ProductRepositoryInterface $productRepository
     * @param ProductAccessValidator $productAccessValidator
     * @param FavoriteService $favoriteService
     * @param RequestInputNormalizer $inputNormalizer
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly AuthorizationService $authorizationService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductAccessValidator $productAccessValidator,
        private readonly FavoriteService $favoriteService,
        private readonly RequestInputNormalizer $inputNormalizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Valida a requisição e alterna o favorito do cliente em JSON.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if (method_exists($result, 'setHeader')) {
            $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        }

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setData([
                'success' => false,
                'reason' => 'invalid_form_key',
                'message' => (string) __('Sessão expirada. Recarregue a página e tente novamente.'),
            ]);
        }

        $productId = (int) $this->request->getParam('product_id', 0);
        if (!$this->inputNormalizer->isValidProductId($productId)) {
            return $result->setData([
                'success' => false,
                'reason' => 'invalid_product',
                'message' => (string) __('Produto inválido.'),
            ]);
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
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return $result->setData([
                'success' => false,
                'reason' => 'invalid_product',
                'message' => (string) __('Produto não encontrado.'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Favorite product load failed.', [
                'customer_id' => (int) $context['customer_id'],
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);

            return $result->setData([
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível validar o produto agora.'),
            ]);
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $visibility = $this->productAccessValidator->isVisibleInCatalog((string) $product->getSku(), $storeId);

        if (!$visibility['allowed']) {
            return $result->setData([
                'success' => false,
                'reason' => 'not_available',
                'message' => (string) __('Produto não disponível para sua conta.'),
            ]);
        }

        $outcome = $this->favoriteService->toggle((int) $context['customer_id'], $productId);

        if (!$outcome['success']) {
            return $result->setData([
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível atualizar o favorito.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'product_id' => $productId,
            'favorite' => $outcome['favorite'],
        ]);
    }
}
