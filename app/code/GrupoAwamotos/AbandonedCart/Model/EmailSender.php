<?php

declare(strict_types=1);

namespace GrupoAwamotos\AbandonedCart\Model;

use GrupoAwamotos\AbandonedCart\Api\EmailSenderInterface;
use GrupoAwamotos\AbandonedCart\Api\Data\AbandonedCartInterface;
use GrupoAwamotos\AbandonedCart\Api\CouponGeneratorInterface;
use GrupoAwamotos\AbandonedCart\Helper\Data as Helper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\App\State as AppState;
use Psr\Log\LoggerInterface;

class EmailSender implements EmailSenderInterface
{
    private Helper $helper;
    private TransportBuilder $transportBuilder;
    private StateInterface $inlineTranslation;
    private StoreManagerInterface $storeManager;
    private CartRepositoryInterface $cartRepository;
    private ProductRepositoryInterface $productRepository;
    private CouponGeneratorInterface $couponGenerator;
    private PricingHelper $pricingHelper;
    private ImageHelper $imageHelper;
    private AppState $appState;
    private LoggerInterface $logger;

    public function __construct(
        Helper $helper,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        CouponGeneratorInterface $couponGenerator,
        PricingHelper $pricingHelper,
        LoggerInterface $logger,
        ImageHelper $imageHelper,
        AppState $appState
    ) {
        $this->helper = $helper;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->couponGenerator = $couponGenerator;
        $this->pricingHelper = $pricingHelper;
        $this->imageHelper = $imageHelper;
        $this->appState = $appState;
        $this->logger = $logger;
    }

    public function sendEmail(AbandonedCartInterface $abandonedCart, int $emailNumber): bool
    {
        $storeId = $abandonedCart->getStoreId();

        if (!$this->helper->isEnabled($storeId)) {
            return false;
        }

        if (!$this->helper->isEmailEnabled($emailNumber, $storeId)) {
            return false;
        }

        try {
            $this->ensureFrontendAreaCode();
            $this->inlineTranslation->suspend();

            $store = $this->storeManager->getStore($storeId);
            $templateId = $this->helper->getEmailTemplate($emailNumber, $storeId);

            if (empty($templateId) || $templateId === 'abandoned_cart_email_' . $emailNumber . '_template') {
                $templateId = 'abandoned_cart_email_' . $emailNumber . '_template';
            }

            // Preparar dados do carrinho
            $cartItems = $this->getCartItems($abandonedCart->getQuoteId());
            $cartUrl = $store->getUrl('checkout/cart');

            // Gerar ou reutilizar cupom
            $couponCode = null;
            $couponDiscount = null;
            if ($this->helper->isCouponEnabled($emailNumber, $storeId)) {
                $discount = $this->helper->getCouponDiscount($emailNumber, $storeId);
                $type     = $this->helper->getCouponType($emailNumber, $storeId);

                // Reutilizar cupom gerado em email anterior (evita cupons duplicados)
                $existingCode = $abandonedCart->getCouponCode();
                if ($existingCode !== null && $existingCode !== '') {
                    $couponCode = $existingCode;
                } else {
                    $couponCode = $this->couponGenerator->generate(
                        $discount,
                        $type,
                        $abandonedCart->getQuoteId(),
                        $abandonedCart->getCustomerEmail()
                    );
                }

                $couponDiscount = $type === 'percent' ? "{$discount}%" : "R\$ {$discount}";
            }

            $templateVars = [
                'customer_name' => $abandonedCart->getCustomerName() ?: 'Cliente',
                'customer_email' => $abandonedCart->getCustomerEmail(),
                'cart_value' => $this->pricingHelper->currency($abandonedCart->getCartValue(), true, false),
                'items_count' => $abandonedCart->getItemsCount(),
                'cart_items' => $cartItems,
                'cart_items_html' => $this->buildCartItemsHtml($cartItems),
                'cart_url' => $cartUrl,
                'coupon_code' => $couponCode,
                'coupon_discount' => $couponDiscount,
                'store' => $store,
                'email_number' => $emailNumber,
            ];

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope('general', $storeId)
                ->addTo($abandonedCart->getCustomerEmail(), $abandonedCart->getCustomerName())
                ->getTransport();

            $transport->sendMessage();

            $this->inlineTranslation->resume();

            // Persistir o código do cupom na entidade para rastreamento e reutilização
            if ($couponCode !== null) {
                $abandonedCart->setCouponCode($couponCode);
            }

            $this->logger->info(sprintf(
                '[AbandonedCart] Email %d sent: quote_id=%d, email=%s, coupon=%s',
                $emailNumber,
                $abandonedCart->getQuoteId(),
                $abandonedCart->getCustomerEmail(),
                $couponCode ?? 'none'
            ));

            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error(sprintf(
                '[AbandonedCart] Email %d failed: quote_id=%d, error=%s',
                $emailNumber,
                $abandonedCart->getQuoteId(),
                $e->getMessage()
            ));
            return false;
        }
    }

    public function sendTestEmail(string $email, int $emailNumber): bool
    {
        try {
            $this->ensureFrontendAreaCode();
            $this->inlineTranslation->suspend();

            $store = $this->storeManager->getStore();
            $templateId = 'abandoned_cart_email_' . $emailNumber . '_template';

            $templateVars = [
                'customer_name' => 'Cliente Teste',
                'customer_email' => $email,
                'cart_value' => 'R$ 299,90',
                'items_count' => 3,
                'cart_items' => [
                    [
                        'name' => 'Capacete Shark S700',
                        'qty' => 1,
                        'price' => 'R$ 890,00',
                        'image' => '',
                    ],
                    [
                        'name' => 'Luva X11 Fit X',
                        'qty' => 2,
                        'price' => 'R$ 89,90',
                        'image' => '',
                    ],
                ],
                'cart_url' => $store->getUrl('checkout/cart'),
                'coupon_code' => 'TESTE10OFF',
                'coupon_discount' => '10%',
                'store' => $store,
                'email_number' => $emailNumber,
            ];

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $store->getId(),
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope('general', $store->getId())
                ->addTo($email, 'Teste')
                ->getTransport();

            $transport->sendMessage();

            $this->inlineTranslation->resume();
            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error('[AbandonedCart] Test email failed: ' . $e->getMessage());
            return false;
        }
    }

    private function getCartItems(int $quoteId): array
    {
        $items = [];

        try {
            $quote = $this->cartRepository->get($quoteId);

            foreach ($quote->getAllVisibleItems() as $item) {
                $product = $item->getProduct();
                $items[] = [
                    'name' => $item->getName(),
                    'qty' => (int) $item->getQty(),
                    'price' => $this->pricingHelper->currency($item->getRowTotal(), true, false),
                    'image' => $this->getProductImageUrl($product),
                    'sku' => $item->getSku(),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning('[AbandonedCart] Could not load cart items: ' . $e->getMessage());
        }

        return $items;
    }

    private function getProductImageUrl($product): string
    {
        try {
            $imageFile = $product->getImage();
            if (!$imageFile || $imageFile === '/no_selection') {
                return '';
            }
            return $this->imageHelper->init($product, 'product_thumbnail_image')
                ->setImageFile($imageFile)
                ->resize(80, 80)
                ->getUrl();
        } catch (\Exception $e) {
            return '';
        }
    }

    private function buildCartItemsHtml(array $cartItems): string
    {
        if (empty($cartItems)) {
            return '';
        }
        $rows = '';
        foreach ($cartItems as $item) {
            $name = htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sku  = htmlspecialchars((string)($item['sku'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $qty  = (int)($item['qty'] ?? 1);
            $price = htmlspecialchars((string)($item['price'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $img  = htmlspecialchars((string)($item['image'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $imgHtml = $img
                ? '<img src="' . $img . '" alt="' . $name . '" width="64" height="64"'
                  . ' style="border-radius:6px;object-fit:cover;display:block;" />'
                : '<div style="width:64px;height:64px;background:#f3f4f6;border-radius:6px;"></div>';

            $rows .= '<tr style="border-bottom:1px solid #f3f4f6;">'
                . '<td style="padding:10px 8px;width:80px;vertical-align:top;">' . $imgHtml . '</td>'
                . '<td style="padding:10px 8px;vertical-align:top;">'
                . '<p style="margin:0 0 2px;font-size:13px;font-weight:600;color:#111827;">' . $name . '</p>'
                . '<p style="margin:0;font-size:12px;color:#6b7280;">SKU: ' . $sku . ' &nbsp;|&nbsp; Qtd: ' . $qty . '</p>'
                . '</td>'
                . '<td style="padding:10px 8px;text-align:right;vertical-align:top;white-space:nowrap;">'
                . '<span style="font-size:14px;font-weight:700;color:#b73337;">' . $price . '</span>'
                . '</td>'
                . '</tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;">'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    private function ensureFrontendAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Exception $e) {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        }
    }
}
