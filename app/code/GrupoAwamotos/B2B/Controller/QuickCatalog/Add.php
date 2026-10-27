<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AddService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Psr\Log\LoggerInterface;

/**
 * Adição individual/em lote ao carrinho.
 *
 * - POST + form_key (proteção CSRF preservada).
 * - request_id obrigatório: idempotência server-side por cliente.
 * - Resultado por item; sucesso parcial não é mascarado.
 */
class Add implements HttpPostActionInterface
{
    /**
     * Inicializa o controller com as dependências de validação e adição.
     *
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param FormKeyValidator $formKeyValidator
     * @param AddService $addService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly AddService $addService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Processa a adição ao carrinho e devolve o resultado por item em JSON.
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
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ]);
        }

        $rawItems = $this->request->getParam('items', []);
        if (is_string($rawItems)) {
            $decoded = json_decode($rawItems, true);
            $rawItems = is_array($decoded) ? $decoded : [];
        }

        $requestId = (string) $this->request->getParam('request_id', '');

        try {
            $payload = $this->addService->add(is_array($rawItems) ? $rawItems : [], $requestId);
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Add endpoint failed.', [
                'exception' => $exception->getMessage(),
            ]);
            $payload = [
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível adicionar os produtos agora.'),
                'added' => [],
                'errors' => [],
                'processed' => 0,
            ];
        }

        return $result->setData($payload);
    }
}
