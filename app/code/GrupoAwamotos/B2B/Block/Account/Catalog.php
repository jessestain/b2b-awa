<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Account;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Customer\Model\Session;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Lista os produtos Private Label exclusivos do cliente logado.
 * Usado na página /b2b/catalog do painel da conta.
 * Somente produtos Enabled no Magento (status alinhado ao escopo ativo do Sectra).
 */
class Catalog extends Template
{
    protected $_template = 'GrupoAwamotos_B2B::account/catalog.phtml';

    private const EXCLUSIVE_TABLE = 'grupoawamotos_b2b_exclusive_product';
    private const NAME_ATTR_CODE  = 'name';
    private const STATUS_ATTR_CODE = 'status';

    /** @var array[]|null */
    private ?array $products = null;

    public function __construct(
        Context $context,
        private readonly Session $customerSession,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retorna os produtos exclusivos do cliente com SKU, nome e label.
     *
     * @return array{product_id:int,sku:string,name:string,label:string|null,url:string}[]
     */
    public function getExclusiveProducts(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        if ($customerId <= 0) {
            return $this->products = [];
        }

        $connection     = $this->resourceConnection->getConnection();
        $exclusiveTable = $this->resourceConnection->getTableName(self::EXCLUSIVE_TABLE);
        $productTable   = $this->resourceConnection->getTableName('catalog_product_entity');
        $varcharTable   = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $intTable       = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $eavTable       = $this->resourceConnection->getTableName('eav_attribute');

        $nameAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($eavTable, ['attribute_id'])
                ->where('attribute_code = ?', self::NAME_ATTR_CODE)
                ->where('entity_type_id = ?', 4)
        );

        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($eavTable, ['attribute_id'])
                ->where('attribute_code = ?', self::STATUS_ATTR_CODE)
                ->where('entity_type_id = ?', 4)
        );

        if ($statusAttrId <= 0) {
            return $this->products = [];
        }

        $select = $connection->select()
            ->from(['ep' => $exclusiveTable], ['product_id', 'label'])
            ->join(['cpe' => $productTable], 'cpe.entity_id = ep.product_id', ['sku'])
            ->join(
                ['cps' => $intTable],
                sprintf(
                    'cps.entity_id = cpe.entity_id AND cps.attribute_id = %d AND cps.store_id = 0',
                    $statusAttrId
                ),
                []
            )
            ->joinLeft(
                ['cpv' => $varcharTable],
                sprintf(
                    'cpv.entity_id = cpe.entity_id AND cpv.attribute_id = %d AND cpv.store_id = 0',
                    $nameAttrId
                ),
                ['name' => 'value']
            )
            ->where('ep.customer_id = ?', $customerId)
            ->where('cps.value = ?', Status::STATUS_ENABLED)
            ->order('cpe.sku ASC');

        $rows = $connection->fetchAll($select);

        $this->products = array_map(function (array $row): array {
            return [
                'product_id' => (int) $row['product_id'],
                'sku'        => $row['sku'],
                'name'       => $row['name'] ?? $row['sku'],
                'label'      => $row['label'],
                'url'        => $this->getUrl('catalog/product/view', ['id' => $row['product_id']]),
            ];
        }, $rows);

        return $this->products;
    }

    public function hasExclusiveProducts(): bool
    {
        return !empty($this->getExclusiveProducts());
    }
}
