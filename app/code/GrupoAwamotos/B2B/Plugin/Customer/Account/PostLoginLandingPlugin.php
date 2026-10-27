<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Customer\Account;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Laminas\Uri\UriFactory;
use Magento\Customer\Controller\Account\LoginPost;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Destino padrão pós-login para cliente B2B APROVADO e apto a comprar:
 * "Comprar produtos" (/b2b/quickcatalog/).
 *
 * Implementação conferida contra o Magento 2.4.8-p5 real:
 * - `Magento\Customer\Controller\Account\LoginPost` retorna um Result Redirect
 *   (não string) e NÃO expõe a URL de destino para leitura;
 * - por isso o `beforeAuthUrl` é capturado ANTES da execução do login (o core
 *   muta a sessão durante a execução) e o destino é classificado por ele:
 *   login/dashboard/base => login "comum" (substitui por quickcatalog);
 *   carrinho/checkout/PDP/referer => retorno legítimo (intocado).
 *
 * Falha em qualquer checagem = resultado original do login (nunca quebra login).
 */
class PostLoginLandingPlugin
{
    /**
     * Destinos que representam "login comum" (sem retorno legítimo pendente).
     * SOMENTE as telas de login: qualquer outra página solicitada antes do login
     * (carrinho, checkout, PDP, catálogo, dashboard, conta) é retorno legítimo e
     * NÃO é substituída — requisito explícito de não quebrar retornos.
     */
    private const PLAIN_LOGIN_TARGETS = [
        'customer/account/login',
        'customer/account/loginpost',
        'b2b/account/login',
    ];

    /**
     * Inicializa o plugin com as dependências de sessão e configuração.
     *
     * @param CustomerSession $customerSession
     * @param B2BHelper $b2bHelper
     * @param PriceVisibilityInterface $priceVisibility
     * @param ScopeConfigInterface $scopeConfig
     * @param AppState $appState
     * @param UrlInterface $urlBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly B2BHelper $b2bHelper,
        private readonly PriceVisibilityInterface $priceVisibility,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppState $appState,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Redireciona o pós-login para o catálogo quando não há retorno legítimo.
     *
     * @param LoginPost $subject
     * @param callable $proceed
     * @return ResultInterface
     */
    public function aroundExecute(LoginPost $subject, callable $proceed): ResultInterface
    {
        $beforeAuthUrl = '';

        try {
            $beforeAuthUrl = (string) ($this->customerSession->getBeforeAuthUrl() ?? '');
        } catch (\Throwable $exception) {
            // sem beforeAuthUrl confiável: segue o fluxo normal, sem substituir
            $this->logger->warning('[B2B QuickCatalog] Could not read beforeAuthUrl before login.', [
                'exception' => $exception->getMessage(),
            ]);
            return $proceed();
        }

        $result = $proceed();

        try {
            if (!$this->shouldLandOnQuickCatalog()) {
                return $result;
            }

            if (!$result instanceof RedirectResult) {
                return $result;
            }

            if (!$this->isPlainLoginTarget($beforeAuthUrl)) {
                return $result;
            }

            $result->setUrl($this->urlBuilder->getUrl('b2b/quickcatalog'));
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Post-login landing check failed; keeping default redirect.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Indica se o cliente logado deve cair no catálogo de compra rápida.
     *
     * @return bool
     */
    private function shouldLandOnQuickCatalog(): bool
    {
        if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
            return false;
        }

        if (!$this->scopeConfig->isSetFlag('grupoawamotos_b2b/general/enabled', ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        if (!$this->customerSession->isLoggedIn() || !$this->b2bHelper->isB2BCustomer()) {
            return false;
        }

        // canAddToCart() = aprovado com tabela de preços disponível; cobre também
        // pendente, tabela em definição e bloqueios (regras atuais preservadas).
        return $this->priceVisibility->canAddToCart();
    }

    /**
     * Login "comum": sem retorno legítimo pendente.
     *
     * Qualquer outra URL (carrinho, checkout, PDP, busca, referer) NÃO é substituída.
     *
     * @param string $beforeAuthUrl
     * @return bool
     */
    private function isPlainLoginTarget(string $beforeAuthUrl): bool
    {
        $targetPath = trim((string) UriFactory::factory($beforeAuthUrl)->getPath(), '/');
        $basePath = trim((string) UriFactory::factory((string) $this->urlBuilder->getBaseUrl())->getPath(), '/');

        if ($basePath !== '' && $targetPath === $basePath) {
            $targetPath = '';
        } elseif ($basePath !== '' && str_starts_with($targetPath, $basePath . '/')) {
            $targetPath = substr($targetPath, strlen($basePath) + 1);
        }

        if ($targetPath === '') {
            return true;
        }

        return in_array(mb_strtolower($targetPath), self::PLAIN_LOGIN_TARGETS, true);
    }
}
