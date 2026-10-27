<?php

/**
 * Controller para processar cadastro B2B
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Register;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Mail\Template\TransportBuilder;
use GrupoAwamotos\B2B\Helper\CnpjValidator;
use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Model\Cnpj\RequestRateLimiter;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;
use GrupoAwamotos\B2B\Model\Registration\AttributionResolver;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Captcha\Helper\Data as CaptchaHelper;
use Magento\Captcha\Observer\CaptchaStringResolver;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Save implements HttpPostActionInterface
{
    private const SESSION_FORM_DATA_KEY = 'b2b_register_form_data';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var RedirectFactory
     */
    private $resultRedirectFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerInterfaceFactory
     */
    private $customerFactory;

    /**
     * @var CustomerFactory
     */
    private $customerModelFactory;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Random
     */
    private $random;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var CnpjValidator
     */
    private $cnpjValidator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var RegionInterfaceFactory
     */
    private $regionFactory;

    /**
     * @var RegionCollectionFactory
     */
    private $regionCollectionFactory;

    /**
     * @var FormKeyValidator
     */
    private $formKeyValidator;

    /**
     * @var EventManagerInterface
     */
    private $eventManager;

    /**
     * @var B2BConfig
     */
    private B2BConfig $b2bConfig;

    /**
     * @var RequestRateLimiter
     */
    private RequestRateLimiter $rateLimiter;

    /**
     * @var RemoteAddress
     */
    private RemoteAddress $remoteAddress;

    /**
     * @var CustomerCollectionFactory
     */
    private CustomerCollectionFactory $customerCollectionFactory;

    /**
     * @var CaptchaHelper
     */
    private CaptchaHelper $captchaHelper;

    /**
     * @var CaptchaStringResolver
     */
    private CaptchaStringResolver $captchaStringResolver;

    public function __construct(
        RequestInterface $request,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager,
        CustomerRepositoryInterface $customerRepository,
        CustomerInterfaceFactory $customerFactory,
        CustomerFactory $customerModelFactory,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        EncryptorInterface $encryptor,
        Random $random,
        TransportBuilder $transportBuilder,
        CnpjValidator $cnpjValidator,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        AddressRepositoryInterface $addressRepository,
        AddressInterfaceFactory $addressFactory,
        RegionInterfaceFactory $regionFactory,
        RegionCollectionFactory $regionCollectionFactory,
        FormKeyValidator $formKeyValidator,
        EventManagerInterface $eventManager,
        B2BConfig $b2bConfig,
        RequestRateLimiter $rateLimiter,
        RemoteAddress $remoteAddress,
        CustomerCollectionFactory $customerCollectionFactory,
        CaptchaHelper $captchaHelper,
        CaptchaStringResolver $captchaStringResolver
    ) {
        $this->request = $request;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->customerModelFactory = $customerModelFactory;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->encryptor = $encryptor;
        $this->random = $random;
        $this->transportBuilder = $transportBuilder;
        $this->cnpjValidator = $cnpjValidator;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->addressRepository = $addressRepository;
        $this->addressFactory = $addressFactory;
        $this->regionFactory = $regionFactory;
        $this->regionCollectionFactory = $regionCollectionFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->eventManager = $eventManager;
        $this->b2bConfig = $b2bConfig;
        $this->rateLimiter = $rateLimiter;
        $this->remoteAddress = $remoteAddress;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->captchaHelper = $captchaHelper;
        $this->captchaStringResolver = $captchaStringResolver;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->logger->warning('[B2B] CSRF attempt on registration form', [
                'ip' => $this->request->getServer('REMOTE_ADDR'),
                'user_agent' => $this->request->getServer('HTTP_USER_AGENT')
            ]);
            $this->messageManager->addErrorMessage(__('Formulário inválido. Tente novamente.'));
            return $resultRedirect->setPath('*/*/');
        }

        // Honeypot: bots fill this invisible field, legitimate users don't
        $honeypot = (string) $this->request->getParam('b2b_website', '');
        if ($honeypot !== '') {
            $this->logger->warning('[B2B] Honeypot triggered on registration', [
                'ip' => $this->request->getServer('REMOTE_ADDR')
            ]);
            // Silent fail: fake success to avoid exposing the check
            $this->messageManager->addSuccessMessage(
                __('Cadastro realizado com sucesso! Seu acesso B2B será analisado e você receberá um e-mail em breve.')
            );
            return $resultRedirect->setPath('b2b/account/dashboard');
        }

        // Rate limiting: 5 registration attempts per hour per IP
        $clientIp = (string) (
            $this->remoteAddress->getRemoteAddress()
            ?: $this->request->getServer('REMOTE_ADDR')
            ?: 'unknown'
        );
        $rateLimit = $this->rateLimiter->consume('b2b_register_' . $clientIp);
        if (!$rateLimit['allowed']) {
            $retryAfter = (int) ($rateLimit['retry_after'] ?? 3600);
            $this->logger->warning('[B2B] Rate limit reached on registration form', ['ip' => $clientIp]);
            $this->messageManager->addErrorMessage(__(
                'Muitas tentativas de cadastro em pouco tempo. Aguarde %1 minutos e tente novamente.',
                (int) ceil($retryAfter / 60)
            ));
            return $resultRedirect->setPath('*/*/');
        }

        // Captcha: barreira contra bots. O formulario B2B e CUSTOM, entao o observer
        // nativo (CheckUserCreateObserver) NAO alcanca este controller — sem validar
        // aqui, o captcha renderizado seria puramente decorativo.
        $captchaWord = (string) $this->captchaStringResolver->resolve($this->request, 'user_create');
        if (!$this->validateCaptcha($captchaWord)) {
            $this->logger->warning('[B2B] Captcha invalido/ausente no cadastro', [
                'ip' => $clientIp,
                'user_agent' => $this->request->getServer('HTTP_USER_AGENT')
            ]);
            $this->messageManager->addErrorMessage(
                __('A verificacao de seguranca esta incorreta. Tente novamente.')
            );
            return $resultRedirect->setPath('*/*/');
        }

        try {
            // Validar dados
            $data = $this->validateData();
            if (!$data) {
                return $resultRedirect->setPath('*/*/');
            }

            if ($this->isConsumedRegistration($data)) {
                return $resultRedirect->setPath('b2b/register/success');
            }

            if (!$this->isIdempotencyTokenValid()) {
                $this->messageManager->addErrorMessage(
                    __('Sessão do formulário expirada. Recarregue a página e envie o cadastro novamente.')
                );
                return $this->redirectWithPersistedForm($resultRedirect);
            }

            // Validar CNPJ
            if (!$this->cnpjValidator->validateLocal($data['cnpj'])) {
                $this->messageManager->addErrorMessage(__('CNPJ inválido. Por favor, verifique e tente novamente.'));
                return $this->redirectWithPersistedForm($resultRedirect);
            }

            // Enrich with API data (best-effort — does not block registration)
            $apiData = null;
            try {
                $apiResult = $this->cnpjValidator->validateApi($data['cnpj']);
                if ($apiResult !== null && !empty($apiResult['valid'])) {
                    $apiData = $apiResult;
                    // Fill empty form fields with API data
                    if (empty($data['razao_social']) && !empty($apiResult['razao_social'])) {
                        $data['razao_social'] = $apiResult['razao_social'];
                    }
                    if (empty($data['nome_fantasia']) && !empty($apiResult['nome_fantasia'])) {
                        $data['nome_fantasia'] = $apiResult['nome_fantasia'];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('B2B Registration: API enrichment failed — ' . $e->getMessage());
            }

            // Verificar se email já existe
            try {
                $existingCustomer = $this->customerRepository->get($data['email']);
                if ($existingCustomer->getId()) {
                    $this->messageManager->addErrorMessage(__('Já existe uma conta com este e-mail. Por favor, faça login ou use outro e-mail.'));
                    return $this->redirectWithPersistedForm($resultRedirect);
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning('[Save::execute] Exceção ignorada: ' . $e->getMessage());
                // Email não existe, pode continuar
            }

            // Verificar se CNPJ já está cadastrado
            $formattedCnpj = $this->cnpjValidator->format($data['cnpj']);
            $cnpjCollection = $this->customerCollectionFactory->create();
            $cnpjCollection->addAttributeToFilter(
                [
                    ['attribute' => 'b2b_cnpj', 'eq' => $formattedCnpj],
                    ['attribute' => 'b2b_cnpj', 'eq' => $data['cnpj']]
                ]
            );
            $cnpjCollection->setPageSize(1);
            if ($cnpjCollection->getFirstItem()->getId()) {
                $this->messageManager->addErrorMessage(
                    __('Este CNPJ já está vinculado a uma conta existente. Faça login ou use a opção de vinculação de conta.')
                );
                return $this->redirectWithPersistedForm($resultRedirect);
            }

            $this->clearPersistedRegisterFormData();

            // Criar cliente
            $customer = $this->customerFactory->create();
            $customer->setEmail($data['email']);
            $customer->setFirstname($data['firstname']);
            $customer->setLastname($data['lastname']);
            $customer->setGroupId($this->b2bConfig->getPendingGroupId() ?: B2BHelper::GROUP_B2B_PENDENTE);
            $customer->setStoreId($this->storeManager->getStore()->getId());
            $customer->setWebsiteId($this->storeManager->getStore()->getWebsiteId());

            // Atributos B2B
            $customer->setCustomAttribute('b2b_cnpj', $this->cnpjValidator->format($data['cnpj']));
            $customer->setCustomAttribute('b2b_razao_social', $data['razao_social']);
            $customer->setCustomAttribute('b2b_nome_fantasia', $data['nome_fantasia'] ?? '');
            $customer->setCustomAttribute('b2b_inscricao_estadual', $data['inscricao_estadual'] ?? '');
            $customer->setCustomAttribute('b2b_approval_status', 'pending');
            $customer->setCustomAttribute('b2b_person_type', 'pj');
            $customer->setCustomAttribute('b2b_phone', $data['phone'] ?? '');
            $customer->setCustomAttribute(
                'erp_customer_sync_status',
                ErpCustomerSyncStatus::PROSPECT_MAGENTO
            );
            $this->assignRegistrationOriginAttributes($customer, $data);

            // Salvar cliente
            $savedCustomer = $this->customerRepository->save($customer);

            // Definir senha
            $customerModel = $this->customerModelFactory->create()->load($savedCustomer->getId());
            $customerModel->setPassword($data['password']);
            $customerModel->save();

            // Salvar endereço comercial como padrão de cobrança/entrega
            $this->saveCustomerDefaultAddress($savedCustomer, $data);

            // Enviar email de confirmação ao cliente
            $this->sendConfirmationEmail($savedCustomer, $data);

            $protocol = $this->buildRegistrationProtocol((int) $savedCustomer->getId());
            $this->customerSession->setData('b2b_register_protocol', $protocol);
            $this->markRegistrationConsumed($data, $protocol);

            // Evento técnico para tracking de aquisição B2B (consumido por módulos de analytics)
            $this->eventManager->dispatch('grupoawamotos_b2b_registration_submitted', [
                'customer' => $savedCustomer,
                'registration_context' => [
                    'lead_type' => 'b2b_cnpj',
                    'person_type' => 'pj',
                    'approval_status' => 'pending',
                    'customer_group_id' => (int) $savedCustomer->getGroupId(),
                    'cnpj_validated' => true,
                    'register_channel' => 'b2b_register_form',
                    'protocol' => $protocol,
                    'whatsapp_consent' => !empty($data['whatsapp_consent']),
                    'privacy_consent' => true,
                ]
            ]);

            // Nota: notificação ao admin é feita pelo CustomerRegisterObserver/CustomerRegistrationNotification
            // para evitar notificações duplicadas

            $this->messageManager->addSuccessMessage(
                __('Cadastro realizado com sucesso! Seu acesso B2B será analisado e você receberá um e-mail em breve.')
            );

            // Login automático
            $this->customerSession->setCustomerDataAsLoggedIn($savedCustomer);

            return $resultRedirect->setPath('b2b/register/success');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectWithPersistedForm($resultRedirect);
        } catch (\Throwable $e) {
            $this->logger->error('B2B Registration Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Ocorreu um erro ao processar seu cadastro. Por favor, tente novamente.')
            );
            return $this->redirectWithPersistedForm($resultRedirect);
        }
    }

    /**
     * @param \Magento\Framework\Controller\Result\Redirect $resultRedirect
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectWithPersistedForm($resultRedirect)
    {
        $this->persistRegisterFormData();

        return $resultRedirect->setPath('*/*/');
    }

    private function persistRegisterFormData(): void
    {
        $cepDigits = preg_replace('/\D/', '', (string) $this->request->getParam('cep', ''));
        $cepFormatted = $cepDigits;
        if (strlen($cepDigits) === 8) {
            $cepFormatted = substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5);
        }

        $this->customerSession->setData(self::SESSION_FORM_DATA_KEY, [
            'cnpj' => (string) $this->request->getParam('cnpj', ''),
            'razao_social' => trim((string) $this->request->getParam('razao_social', '')),
            'nome_fantasia' => trim((string) $this->request->getParam('nome_fantasia', '')),
            'inscricao_estadual' => trim((string) $this->request->getParam('inscricao_estadual', '')),
            'phone' => trim((string) $this->request->getParam('phone', '')),
            'cep' => $cepFormatted,
            'logradouro' => trim((string) $this->request->getParam('logradouro', '')),
            'numero' => trim((string) $this->request->getParam('numero', '')),
            'complemento' => trim((string) $this->request->getParam('complemento', '')),
            'bairro' => trim((string) $this->request->getParam('bairro', '')),
            'municipio' => trim((string) $this->request->getParam('municipio', '')),
            'uf' => strtoupper(trim((string) $this->request->getParam('uf', ''))),
            'firstname' => trim((string) $this->request->getParam('firstname', '')),
            'lastname' => trim((string) $this->request->getParam('lastname', '')),
            'email' => trim((string) $this->request->getParam('email', '')),
            'terms' => (string) (int) $this->request->getParam('terms', 0),
            'privacy' => (string) (int) $this->request->getParam('privacy', 0),
            'whatsapp_consent' => (string) (int) $this->request->getParam('whatsapp_consent', 0),
            'ie_isento' => (string) (int) $this->request->getParam('ie_isento', 0),
        ]);
    }

    private function clearPersistedRegisterFormData(): void
    {
        $this->customerSession->unsetData(self::SESSION_FORM_DATA_KEY);
    }

    /**
     * Validate form data
     *
     * @return array|false
     */
    private function validateData()
    {
        $firstname = trim($this->request->getParam('firstname', ''));
        $lastname = trim($this->request->getParam('lastname', ''));
        $email = trim($this->request->getParam('email', ''));
        $password = $this->request->getParam('password', '');
        $passwordConfirm = $this->request->getParam('password_confirmation', '');
        $cnpj = preg_replace('/\D/', '', $this->request->getParam('cnpj', ''));
        $razaoSocial = trim($this->request->getParam('razao_social', ''));
        $nomeFantasia = trim($this->request->getParam('nome_fantasia', ''));
        $inscricaoEstadual = trim($this->request->getParam('inscricao_estadual', ''));
        $phone = trim($this->request->getParam('phone', ''));
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $cep = preg_replace('/\D/', '', (string) $this->request->getParam('cep', ''));
        $logradouro = trim((string) $this->request->getParam('logradouro', ''));
        $numero = trim((string) $this->request->getParam('numero', ''));
        $complemento = trim((string) $this->request->getParam('complemento', ''));
        $bairro = trim((string) $this->request->getParam('bairro', ''));
        $municipio = trim((string) $this->request->getParam('municipio', ''));
        $uf = strtoupper(trim((string) $this->request->getParam('uf', '')));
        $termsAccepted = (int) $this->request->getParam('terms', 0);
        $privacyAccepted = (int) $this->request->getParam('privacy', 0);
        $whatsappConsent = (int) $this->request->getParam('whatsapp_consent', 0) === 1;
        $ieIsento = (int) $this->request->getParam('ie_isento', 0) === 1;

        $errors = [];

        if (empty($firstname)) {
            $errors[] = __('Nome é obrigatório.');
        }

        if (empty($lastname)) {
            $errors[] = __('Sobrenome é obrigatório.');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('E-mail inválido.');
        }

        if (empty($password) || strlen($password) < 8) {
            $errors[] = __('A senha deve ter pelo menos 8 caracteres.');
        } elseif (!$this->isPasswordComplex($password)) {
            $errors[] = __('A senha deve conter pelo menos 3 das seguintes classes: letras minúsculas, letras maiúsculas, números e caracteres especiais.');
        }

        if ($password !== $passwordConfirm) {
            $errors[] = __('As senhas não conferem.');
        }

        if (empty($cnpj) || strlen($cnpj) !== 14) {
            $errors[] = __('CNPJ é obrigatório e deve ter 14 dígitos.');
        }

        if (empty($razaoSocial)) {
            $errors[] = __('Razão Social é obrigatória.');
        }

        if (empty($phoneDigits) || strlen($phoneDigits) < 10) {
            $errors[] = __('Telefone comercial é obrigatório e deve ser válido.');
        }

        if (empty($cep) || strlen($cep) !== 8) {
            $errors[] = __('CEP é obrigatório e deve conter 8 dígitos.');
        }

        if ($logradouro === '') {
            $errors[] = __('Logradouro é obrigatório.');
        }

        if ($numero === '') {
            $errors[] = __('Número é obrigatório.');
        }

        if ($bairro === '') {
            $errors[] = __('Bairro é obrigatório.');
        }

        if ($municipio === '') {
            $errors[] = __('Cidade é obrigatória.');
        }

        $validUfs = [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA',
            'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN',
            'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
        ];
        if ($uf === '' || !in_array($uf, $validUfs, true)) {
            $errors[] = __('UF inválida. Informe a sigla de um estado brasileiro (ex: SP, RJ, MG).');
        }

        if ($termsAccepted !== 1) {
            $errors[] = __('Você deve aceitar os Termos de Uso.');
        }

        if ($privacyAccepted !== 1) {
            $errors[] = __('Você deve aceitar a Política de Privacidade.');
        }

        if (!$ieIsento && $inscricaoEstadual === '') {
            $errors[] = __('Informe a inscrição estadual ou marque a opção de isento.');
        }

        if ($ieIsento) {
            $inscricaoEstadual = 'ISENTO';
        }

        if (!empty($errors)) {
            $this->persistRegisterFormData();
            foreach ($errors as $error) {
                $this->messageManager->addErrorMessage($error);
            }
            return false;
        }

        return [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'password' => $password,
            'cnpj' => $cnpj,
            'razao_social' => $razaoSocial,
            'nome_fantasia' => $nomeFantasia,
            'inscricao_estadual' => $inscricaoEstadual,
            'phone' => $phone,
            'cep' => $cep,
            'logradouro' => $logradouro,
            'numero' => $numero,
            'complemento' => $complemento,
            'bairro' => $bairro,
            'municipio' => $municipio,
            'uf' => $uf,
            'whatsapp_consent' => $whatsappConsent,
        ];
    }

    /**
     * Send confirmation email to customer
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @param array $data
     * @return void
     */
    private function sendConfirmationEmail(\Magento\Customer\Api\Data\CustomerInterface $customer, array $data): void
    {
        try {
            $store = $this->storeManager->getStore();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('grupoawamotos_b2b_registration_confirmation')
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $store->getId()
                ])
                ->setTemplateVars([
                    'customer' => $customer,
                    'customer_name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                    'razao_social' => $data['razao_social'],
                    'cnpj' => $this->cnpjValidator->format($data['cnpj']),
                    'store' => $store
                ])
                ->setFromByScope('general')
                ->addTo($customer->getEmail(), $customer->getFirstname())
                ->getTransport();

            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->logger->error('B2B Registration Email Error: ' . $e->getMessage());
        }
    }

    /**
     * Salva o endereço comercial como padrão de cobrança e entrega.
     */
    private function saveCustomerDefaultAddress(CustomerInterface $customer, array $data): void
    {
        try {
            $address = $this->addressFactory->create();
            $address->setCustomerId((int) $customer->getId());
            $address->setFirstname((string) $customer->getFirstname());
            $address->setLastname((string) $customer->getLastname());
            $address->setCompany((string) $data['razao_social']);
            $address->setTelephone((string) $data['phone']);
            $address->setPostcode($this->formatPostcode((string) $data['cep']));
            $address->setCity((string) $data['municipio']);
            $address->setCountryId('BR');
            $address->setStreet($this->buildStreetLines($data));

            $regionId = $this->resolveBrazilRegionId((string) $data['uf']);
            if ($regionId !== null) {
                $address->setRegionId($regionId);
            } else {
                $region = $this->regionFactory->create();
                $region->setRegionCode((string) $data['uf']);
                $region->setRegion((string) $data['uf']);
                $address->setRegion($region);
            }

            $address->setIsDefaultBilling(true);
            $address->setIsDefaultShipping(true);

            $this->addressRepository->save($address);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    'B2B Address Save Warning (customer_id: %s): %s',
                    (string) $customer->getId(),
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * @return string[]
     */
    private function buildStreetLines(array $data): array
    {
        $line1 = trim((string) $data['logradouro']) . ', ' . trim((string) $data['numero']);
        $line2 = trim((string) ($data['complemento'] ?? ''));
        $line3 = 'Bairro: ' . trim((string) $data['bairro']);

        $lines = [$line1];
        if ($line2 !== '') {
            $lines[] = $line2;
        }
        $lines[] = $line3;

        return $lines;
    }

    private function formatPostcode(string $cep): string
    {
        $digits = preg_replace('/\D/', '', $cep);
        if (strlen($digits) !== 8) {
            return $cep;
        }

        return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
    }

    private function resolveBrazilRegionId(string $uf): ?int
    {
        $regionCode = strtoupper(trim($uf));
        if (strlen($regionCode) !== 2) {
            return null;
        }

        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter('BR');
        $collection->addRegionCodeFilter($regionCode);
        $collection->setPageSize(1);

        $region = $collection->getFirstItem();
        if (!$region || !$region->getId()) {
            return null;
        }

        return (int) $region->getId();
    }

    /**
     * Validate password complexity (at least 3 of 4 character classes)
     * Matches Magento 2 default policy
     */
    private function isPasswordComplex(string $password): bool
    {
        $classes = 0;
        if (preg_match('/[a-z]/', $password)) {
            $classes++;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $classes++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $classes++;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $classes++;
        }

        return $classes >= 3;
    }

    private function isIdempotencyTokenValid(): bool
    {
        $token = (string) $this->request->getParam('b2b_idempotency', '');
        if ($token === '') {
            return false;
        }

        $sessionToken = (string) $this->customerSession->getData('b2b_register_idempotency_token');
        if ($sessionToken !== '') {
            return hash_equals($sessionToken, $token);
        }

        // FIX F-01 (2026-10-14): quando o token não pôde ser persistido na sessão do
        // visitante (SessionFpcGuardPlugin do CatalogFix bloqueia o start da sessão de
        // cliente em GET sem cookie PHPSESSID — decisão de arquitetura para FPC/Varnish),
        // não há valor em sessão para comparar. A submissão continua exigindo o token
        // (não vazio) e já passou por form_key (CSRF), captcha, honeypot e rate limit;
        // a proteção contra duplo envio segue em isConsumedRegistration().
        // Ver Block\Register\Form::getIdempotencyToken(), que grava o token também em
        // $_SESSION['customer'] — quando isso funciona, o hash_equals acima é aplicado.
        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isConsumedRegistration(array $data): bool
    {
        $consumed = $this->customerSession->getData('b2b_register_idempotency_consumed');
        if (!is_array($consumed)) {
            return false;
        }

        $fingerprint = $this->registrationFingerprint($data);
        if (($consumed['fp'] ?? '') !== $fingerprint) {
            return false;
        }

        $consumedAt = (int) ($consumed['at'] ?? 0);
        return $consumedAt > 0 && (time() - $consumedAt) < 3600;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function markRegistrationConsumed(array $data, string $protocol): void
    {
        $this->customerSession->setData('b2b_register_idempotency_consumed', [
            'fp' => $this->registrationFingerprint($data),
            'protocol' => $protocol,
            'at' => time(),
        ]);
        $this->customerSession->unsetData('b2b_register_idempotency_token');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registrationFingerprint(array $data): string
    {
        $cnpj = preg_replace('/\D/', '', (string) ($data['cnpj'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        return hash('sha256', $cnpj . '|' . $email);
    }

    private function buildRegistrationProtocol(int $customerId): string
    {
        return sprintf('AWA-%s-%05d', gmdate('Ymd'), $customerId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assignRegistrationOriginAttributes(
        \Magento\Customer\Api\Data\CustomerInterface $customer,
        array $data
    ): void {
        $host = trim((string) $this->request->getServer('HTTP_HOST', ''));
        if ($host !== '') {
            $customer->setCustomAttribute('b2b_origin_host', substr($host, 0, 255));
        }

        $landing = trim((string) $this->customerSession->getData('b2b_entry_url'));
        if ($landing === '') {
            $landing = trim((string) $this->request->getServer('HTTP_REFERER', ''));
        }
        if ($landing !== '') {
            $customer->setCustomAttribute('b2b_registration_landing', substr($landing, 0, 255));
        }

        $this->assignMarketingAttribution($customer, $data, $landing);
        $this->assignReceitaSituacao($customer, $data);

        if (!empty($data['whatsapp_consent'])) {
            $notesAttr = $customer->getCustomAttribute('b2b_admin_notes');
            $existingNotes = $notesAttr ? trim((string) $notesAttr->getValue()) : '';
            $note = trim($existingNotes . "\n" . 'whatsapp_consent=1');
            $customer->setCustomAttribute('b2b_admin_notes', $note);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assignMarketingAttribution(
        CustomerInterface $customer,
        array $data,
        string $landing
    ): void {
        $resolver = new AttributionResolver();
        $stored = $this->customerSession->getData('b2b_attribution');
        $stored = is_array($stored) ? $stored : [];
        $incoming = $resolver->fromParams(array_merge(
            ['registration_landing' => $landing],
            $this->request->getParams(),
            $data
        ))->toRequestParams();
        $merged = $resolver->mergeFirstTouch($stored, $incoming);
        $attribution = $resolver->fromParams($merged);

        foreach ($attribution->toCustomerAttributes() as $code => $value) {
            $customer->setCustomAttribute($code, $value);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assignReceitaSituacao(CustomerInterface $customer, array $data): void
    {
        $cnpj = $this->cnpjValidator->clean((string) ($data['cnpj'] ?? ''));
        if ($cnpj === '') {
            return;
        }

        $api = $this->cnpjValidator->validateApi($cnpj);
        if (!is_array($api)) {
            return;
        }

        $situacao = trim((string) ($api['situacao'] ?? ''));
        if ($situacao === '' && isset($api['data']) && is_array($api['data'])) {
            $situacao = trim((string) ($api['data']['situacao'] ?? ''));
        }
        if ($situacao === '') {
            return;
        }

        $customer->setCustomAttribute('b2b_receita_situacao', mb_substr($situacao, 0, 255));
        $customer->setCustomAttribute('b2b_receita_validated', '1');
    }

    /**
     * Valida o captcha do Magento para o formulario user_create.
     * Usa a engine oficial (mesma imagem/validade/expiração do captcha nativo).
     */
    private function validateCaptcha(string $word): bool
    {
        try {
            $captcha = $this->captchaHelper->getCaptcha('user_create');
            if (!$captcha->isRequired()) {
                return true; // captcha nao exigido por configuracao
            }
            return (bool) $captcha->isCorrect($word);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B] Erro ao validar captcha: ' . $e->getMessage());
            return false;
        }
    }
}
