<?php
declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Pricing;

use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog;
use Magento\Catalog\Pricing\Price\TierPrice as TierPriceModel;
use Magento\Customer\Model\Session;
use Magento\Framework\Pricing\Amount\AmountFactory;
use Psr\Log\LoggerInterface;

/**
 * B2B Densidade — preço da LISTA do cliente (Sectra) na tabela de quantidade.
 *
 * Resolve o código ERP do cliente pelo ENTITY MAP do sync (confiável mesmo com
 * sessão depersonalizada pelo FPC), com fallback para o atributo erp_code.
 * Injeta o preço da lista em TierPrice::getTierPriceList().
 */
class CustomerTierPrice
{
    public function __construct(
        private readonly Session $customerSession,
        private readonly CustomerPriceProvider $customerPriceProvider,
        private readonly SyncLog $syncLog,
        private readonly AmountFactory $amountFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterGetTierPriceList(TierPriceModel $subject, array $list): array
    {
        try {
            $product = $subject->getProduct();
        } catch (\Throwable $e) {
            return $list;
        }
        if (!$product || !(int) $product->getId()) {
            return $list;
        }
        $sku = (string) $product->getSku();
        if ($sku === '') {
            return $list;
        }

        // Caminho 1: sessão viva (páginas não-cacheáveis / render personal).
        if ($this->customerSession->isLoggedIn()) {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId > 0) {
                $erpCode = $this->resolveErpCode($customerId);
                if ($erpCode > 0) {
                    try {
                        $price = $this->customerPriceProvider->getCustomerPrice($erpCode, $sku);
                    } catch (\Throwable $e) {
                        $this->logger->error('[B2B Tier] falha ao obter preço da lista: ' . $e->getMessage());
                        $price = null;
                    }
                    if ($price !== null && $price > 0) {
                        $this->appendTierRow($list, $price);
                        return $list;
                    }
                }
            }
        }

        // Caminho 2 (hole-punch FPC): página cacheável renderiza com sessão
        // depersonalizada — a lista do cliente sobrevive no HttpContext
        // (CustomerPriceListContextPlugin: vary por erp_price_list).
        $listCode = $this->resolveHttpContextPriceListCode();
        if ($listCode !== null) {
            try {
                $price = $this->customerPriceProvider->getAuthorizedListPrice($listCode, $sku);
            } catch (\Throwable $e) {
                $this->logger->error('[B2B Tier] falha authorized list: ' . $e->getMessage());
                $price = null;
            }
            if ($price !== null && $price > 0) {
                $this->appendTierRow($list, $price);
            }
        }

        return $list;
    }

    /**
     * Resolve o código ERP do cliente: entity map do sync + atributo erp_code.
     */
    private function resolveErpCode(int $customerId): int
    {
        $erpCode = 0;
        try {
            $mapped = $this->syncLog->getErpCodeByMagentoId('customer', $customerId);
            $erpCode = $mapped !== null && $mapped !== '' ? (int) $mapped : 0;
        } catch (\Throwable $e) {
            $erpCode = 0;
        }
        if ($erpCode <= 0) {
            try {
                $customer = $this->customerSession->getCustomerData();
                $attr = $customer ? $customer->getCustomAttribute('erp_code') : null;
                $erpCode = $attr ? (int) $attr->getValue() : 0;
            } catch (\Throwable $e) {
                $erpCode = 0;
            }
        }
        return $erpCode;
    }

    /**
     * Parse do token erp_price_list do HttpContext ("3:1", "24:1").
     * Sem construtor novo: ObjectManager evita setup:di:compile.
     */
    private function resolveHttpContextPriceListCode(): ?int
    {
        try {
            $httpContext = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\App\Http\Context::class);
            $token = $httpContext->getValue('erp_price_list');
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($token) || $token === '' || $token === '0' || $token === 'logged_in') {
            return null;
        }
        if (!preg_match('/^(\d+)(?::|$)/', $token, $matches)) {
            return null;
        }
        $listCode = (int) $matches[1];
        return $listCode > 0 ? $listCode : null;
    }

    /**
     * Adiciona a faixa (qty >= 1) com o preço da lista do cliente.
     */
    private function appendTierRow(array &$list, float $price): void
    {
        try {
            $list[] = [
                'price_qty' => 1.0,
                'price'     => $this->amountFactory->create($price, []),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Tier] falha ao criar amount: ' . $e->getMessage());
        }
    }
}
