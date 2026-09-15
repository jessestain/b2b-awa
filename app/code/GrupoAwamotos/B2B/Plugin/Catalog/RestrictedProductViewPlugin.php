<?php

/**
 * B2B Restricted Product View Plugin - Block access to restricted products
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Catalog;

use Magento\Catalog\Controller\Product\View;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;

class RestrictedProductViewPlugin
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var B2BHelper
     */
    private $b2bHelper;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var B2BConfig
     */
    private $b2bConfig;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PhpCookieManager
     */
    private $cookieManager;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CustomerSession $customerSession,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager,
        B2BHelper $b2bHelper,
        RequestInterface $request,
        B2BConfig $b2bConfig,
        UrlInterface $urlBuilder,
        LoggerInterface $logger,
        PhpCookieManager $cookieManager
    ) {
        $this->productRepository = $productRepository;
        $this->customerSession = $customerSession;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->b2bHelper = $b2bHelper;
        $this->cookieManager = $cookieManager;
        $this->request = $request;
        $this->b2bConfig = $b2bConfig;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * Around execute - block access to restricted products
     *
     * @param View $subject
     * @param callable $proceed
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function aroundExecute(View $subject, callable $proceed)
    {
        if (!$this->b2bHelper->isEnabled()) {
            return $proceed();
        }

        $isLoggedIn = $this->cookieManager->getCookie(session_name()) !== null
            && $this->customerSession->isLoggedIn();

        // B2B SEO-friendly: permite que visitantes vejam specs/fotos do produto.
        // Preços ficam ocultos pelo HidePricePlugin, cart bloqueado pelo BlockCartAddPlugin,
        // e o LoginToCart block exibe CTA de login/cadastro no lugar do botão de compra.
        // Apenas produtos marcados como b2b_exclusive continuam restritos (tratados abaixo).
        if (!$isLoggedIn) {
            // Para guests, apenas verificar se o produto é B2B exclusive
            $productId = (int) $this->request->getParam('id');
            if ($productId) {
                try {
                    $product = $this->productRepository->getById($productId);
                    $isB2BExclusive = (bool) $product->getData('b2b_exclusive');
                    if ($isB2BExclusive) {
                        $productUrl = $this->urlBuilder->getUrl('catalog/product/view', ['id' => $productId]);
                        $referer = base64_encode($productUrl);
                        $this->messageManager->addNoticeMessage(
                            __('Este produto é exclusivo para clientes B2B. Faça login ou cadastre sua empresa.')
                        );
                        $redirect = $this->redirectFactory->create();
                        return $redirect->setPath('b2b/account/login', ['referer' => $referer]);
                    }
                } catch (\Exception $e) {
                    $this->logger->debug(
                        '[RestrictedProductViewPlugin::aroundExecute] Produto não encontrado: ' . $e->getMessage()
                    );
                }
            }
            return $proceed();
        }

        $productId = (int) $this->request->getParam('id');
        if (!$productId) {
            return $proceed();
        }

        try {
            $product = $this->productRepository->getById($productId);
        } catch (\Exception $e) {
            return $proceed();
        }

        $isB2BExclusive = (bool) $product->getData('b2b_exclusive');
        $allowedGroups = $product->getData('b2b_customer_groups');

        if (!$isB2BExclusive && empty($allowedGroups)) {
            return $proceed();
        }
        $customerGroupId = $isLoggedIn ? (int) $this->customerSession->getCustomerGroupId() : 0;
        $isB2BCustomer = in_array($customerGroupId, $this->b2bHelper->getB2BGroupIds());

        // Check B2B exclusive
        if ($isB2BExclusive && !$isB2BCustomer) {
            $this->messageManager->addNoticeMessage(
                __('Este produto é exclusivo para clientes B2B. Faça login ou cadastre sua empresa.')
            );

            $redirect = $this->redirectFactory->create();
            return $redirect->setPath('b2b/register');
        }

        // Check group restrictions
        if (!empty($allowedGroups) && $isB2BCustomer) {
            if (!$this->isGroupAllowed($allowedGroups, $customerGroupId)) {
                $this->messageManager->addNoticeMessage(
                    __('Este produto não está disponível para o seu grupo de clientes.')
                );

                $redirect = $this->redirectFactory->create();
                return $redirect->setPath('/');
            }
        }

        return $proceed();
    }

    /**
     * Check if customer group is allowed
     *
     * @param string|array $allowedGroups
     * @param int $customerGroupId
     * @return bool
     */
    private function isGroupAllowed($allowedGroups, int $customerGroupId): bool
    {
        if (is_string($allowedGroups)) {
            $allowedGroups = explode(',', $allowedGroups);
        }

        if (!is_array($allowedGroups)) {
            return true;
        }

        $customerGroupName = $this->b2bHelper->getB2BGroupName($customerGroupId);
        if ($customerGroupName === 'Cliente') {
            $customerGroupName = '';
        }

        foreach ($allowedGroups as $group) {
            $group = trim($group);
            if ($group === 'Todos os Grupos B2B' || $group === $customerGroupName) {
                return true;
            }
        }

        return false;
    }
}
