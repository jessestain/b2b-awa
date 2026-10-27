<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Shoppinglist;

use GrupoAwamotos\B2B\Model\ShoppingListService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Psr\Log\LoggerInterface;

class AddToCart implements HttpPostActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly RequestInterface $request,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly MessageManagerInterface $messageManager,
        private readonly ShoppingListService $shoppingListService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        if (!$this->customerSession->isLoggedIn()) {
            return $redirect->setPath('customer/account/login');
        }
        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Requisição inválida.'));
            return $redirect->setPath('b2b/shoppinglist/index');
        }
        $listId = (int) $this->request->getParam('id');
        if (!$listId) {
            $this->messageManager->addErrorMessage(__('Lista não especificada.'));
            return $redirect->setPath('b2b/shoppinglist/index');
        }
        try {
            $result = $this->shoppingListService->addToCart($listId);

            // O service devolve 'added' como INTEIRO (a contagem), nao como lista. O
            // `count($result['added'] ?? [])` anterior so cobria chave ausente/null e
            // estourava TypeError no PHP 8 ("count(): Argument #1 ... int given").
            // Medido no exception.log: 3 ocorrencias em 03/10/2026 (listas 16 e 17),
            // derrubando a lista de compras inteira para o cliente.
            $added = (int) ($result['added'] ?? 0);

            // E a chave de falhas do service e 'failed', nao 'errors'. Com o nome errado,
            // os erros por item eram DESCARTADOS em silencio e o cliente nunca sabia qual
            // produto falhou.
            $errors = $result['failed'] ?? [];
            if ($added > 0) {
                $this->messageManager->addSuccessMessage(__('%1 produto(s) adicionado(s) ao carrinho.', $added));
            }
            foreach ($errors as $err) {
                $msg = is_array($err)
                    ? trim(($err['sku'] ?? '') . ': ' . ($err['error'] ?? ''), ': ')
                    : (string) $err;
                if ($msg !== '') {
                    $this->messageManager->addWarningMessage($msg);
                }
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error(
                '[B2B ShoppingList AddToCart] Falha ao adicionar lista no carrinho.',
                ['list_id' => $listId, 'exception' => $exception]
            );
            $this->messageManager->addErrorMessage(__('Não foi possível adicionar os itens no carrinho agora. Tente novamente.'));
        }
        return $redirect->setPath('checkout/cart');
    }
}
