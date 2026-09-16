<?php

/**
 * AJAX Controller para buscar recomendações em tempo real
 * Resolve ERP code do cliente antes de filtrar recomendações
 */

declare(strict_types=1);

namespace GrupoAwamotos\ProductIntelligence\Controller\Ajax;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;

class GetRecommendations extends Action implements HttpGetActionInterface
{
    protected $resultJsonFactory;
    protected $customerSession;
    protected $customerRepository;
    protected $resource;
    protected $productRepository;
    protected $pricingHelper;
    protected $imageHelper;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        ResourceConnection $resource,
        ProductRepositoryInterface $productRepository,
        PricingHelper $pricingHelper,
        ImageHelper $imageHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->resource = $resource;
        $this->productRepository = $productRepository;
        $this->pricingHelper = $pricingHelper;
        $this->imageHelper = $imageHelper;
        $this->logger = $logger;
    }

    /**
     * Execute AJAX request
     *
     * GET /rexisml/ajax/getrecommendations
     * Params:
     *   - classificacao (optional) — filter by tipo_recomendacao
     *   - limit (optional, default: 4)
     *   - minScore (optional, default: 0.15)
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData([
                'success' => false,
                'message' => 'Cliente não está logado',
                'recommendations' => []
            ]);
        }

        try {
            $customerId = $this->customerSession->getCustomerId();
            $erpCode = $this->resolveErpCode($customerId);

            $classificacao = $this->getRequest()->getParam('classificacao');
            $limit = (int)$this->getRequest()->getParam('limit', 4);
            $minScore = (float)$this->getRequest()->getParam('minScore', 0.15);

            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('rexis_dataset_recomendacao');

            $select = $connection->select()
                ->from($table)
                ->where('identificador_cliente = ?', $erpCode)
                ->where('pred >= ?', $minScore)
                ->order('pred DESC')
                ->limit($limit);

            if ($classificacao) {
                $select->where('tipo_recomendacao = ?', $classificacao);
            }

            $rows = $connection->fetchAll($select);

            $recommendations = [];
            foreach ($rows as $row) {
                try {
                    $product = $this->productRepository->get($row['identificador_produto']);

                    if (!$product->isSaleable()) {
                        continue;
                    }

                    $recommendations[] = [
                        'product_id' => $product->getId(),
                        'sku' => $product->getSku(),
                        'name' => $product->getName(),
                        'url' => $product->getProductUrl(),
                        'image' => $this->imageHelper->init($product, 'product_page_image_small')->getUrl(),
                        'price' => $this->pricingHelper->currency($product->getFinalPrice(), true, false),
                        'price_value' => $product->getFinalPrice(),
                        'score' => round((float)$row['pred'] * 100, 1),
                        'tipo' => $row['tipo_recomendacao'],
                        'classificacao' => $row['classificacao_produto'],
                        'predicted_value' => $row['previsao_gasto_round_up'],
                        'recencia' => $row['recencia']
                    ];
                } catch (\Exception $e) {
                    continue;
                }
            }

            return $result->setData([
                'success' => true,
                'recommendations' => $recommendations,
                'total' => count($recommendations)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence] GetRecommendations error: ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'message' => 'Erro ao carregar recomendações. Tente novamente.',
                'recommendations' => []
            ]);
        }
    }

    /**
     * Resolve Magento customer ID → ERP code
     */
    private function resolveErpCode(int $customerId): string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $erpAttr = $customer->getCustomAttribute('erp_code');
            if ($erpAttr && $erpAttr->getValue()) {
                return (string) $erpAttr->getValue();
            }

            $connection = $this->resource->getConnection();
            $mapTable = $this->resource->getTableName('grupoawamotos_erp_entity_map');
            $erpCode = $connection->fetchOne(
                $connection->select()
                    ->from($mapTable, 'erp_code')
                    ->where('entity_type = ?', 'customer')
                    ->where('magento_entity_id = ?', $customerId)
            );
            if ($erpCode) {
                return (string) $erpCode;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[GetRecommendations::resolveErpCode] Exceção ignorada: ' . $e->getMessage());
            // Fallback
        }

        return (string) $customerId;
    }
}
