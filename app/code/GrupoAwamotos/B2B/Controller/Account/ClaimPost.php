<?php

/**
 * Controller para processar claim de conta (POST)
 * Busca cliente por email ou CNPJ e envia link de reset de senha
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Account;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class ClaimPost implements HttpPostActionInterface
{
    private RequestInterface $request;
    private RedirectFactory $redirectFactory;
    private FormKeyValidator $formKeyValidator;
    private ManagerInterface $messageManager;
    private AccountManagementInterface $accountManagement;
    private CustomerRepositoryInterface $customerRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private LoggerInterface $logger;

    public function __construct(
        RequestInterface $request,
        RedirectFactory $redirectFactory,
        FormKeyValidator $formKeyValidator,
        ManagerInterface $messageManager,
        AccountManagementInterface $accountManagement,
        CustomerRepositoryInterface $customerRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->redirectFactory = $redirectFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->messageManager = $messageManager;
        $this->accountManagement = $accountManagement;
        $this->customerRepository = $customerRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Formulário inválido. Tente novamente.'));
            return $redirect->setPath('b2b/account/claim');
        }

        $input = trim((string) $this->request->getParam('cnpj_or_email'));

        if (empty($input)) {
            $this->messageManager->addErrorMessage(__('Por favor, informe seu e-mail ou CNPJ.'));
            return $redirect->setPath('b2b/account/claim');
        }

        try {
            $email = $this->resolveEmail($input);

            if ($email) {
                $this->accountManagement->initiatePasswordReset(
                    $email,
                    AccountManagement::EMAIL_RESET
                );
            }
        } catch (\Throwable $e) {
            // Não expõe o erro ao usuário (evita revelar se a conta existe), mas registra
            // no log para permitir diagnóstico de falhas reais (ex.: serviço de e-mail fora).
            $this->logger->debug('[ClaimPost::execute] Falha ao iniciar reset de senha: ' . $e->getMessage());
        }

        // Mensagem genérica por segurança (não revela se conta existe)
        $this->messageManager->addSuccessMessage(
            __('Se encontramos seu cadastro, enviamos um e-mail para redefinir sua senha. Verifique sua caixa de entrada.')
        );

        return $redirect->setPath('b2b/account/login');
    }

    /**
     * Resolve input to email address
     */
    private function resolveEmail(string $input): ?string
    {
        // Se parece email, usa direto
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return $input;
        }

        // Remove formatação de CNPJ/CPF
        $digits = preg_replace('/\D/', '', $input);

        // CNPJ (14 dígitos) ou CPF (11 dígitos)
        if (strlen($digits) === 14 || strlen($digits) === 11) {
            return $this->findEmailByCnpj($digits);
        }

        return null;
    }

    /**
     * Find customer email by CNPJ attribute
     */
    private function findEmailByCnpj(string $cnpj): ?string
    {
        // Formata CNPJ para busca (pode estar salvo formatado ou não)
        $formattedCnpj = $this->formatCnpj($cnpj);

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('b2b_cnpj', $formattedCnpj)
            ->setPageSize(1)
            ->create();

        $result = $this->customerRepository->getList($searchCriteria);

        if ($result->getTotalCount() > 0) {
            $customers = $result->getItems();
            $customer = reset($customers);
            return $customer->getEmail();
        }

        // Tenta busca sem formatação
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('b2b_cnpj', $cnpj)
            ->setPageSize(1)
            ->create();

        $result = $this->customerRepository->getList($searchCriteria);

        if ($result->getTotalCount() > 0) {
            $customers = $result->getItems();
            $customer = reset($customers);
            return $customer->getEmail();
        }

        return null;
    }

    private function formatCnpj(string $cnpj): string
    {
        if (strlen($cnpj) === 14) {
            return substr($cnpj, 0, 2) . '.' .
                   substr($cnpj, 2, 3) . '.' .
                   substr($cnpj, 5, 3) . '/' .
                   substr($cnpj, 8, 4) . '-' .
                   substr($cnpj, 12, 2);
        }
        return $cnpj;
    }
}
