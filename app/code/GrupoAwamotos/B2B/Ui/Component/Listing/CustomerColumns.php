<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\Component\Listing;

use Magento\Customer\Ui\Component\Listing\Columns as MagentoCustomerColumns;

/**
 * Hide duplicate b2b_cnpj after Magento overwrites XML visibility from EAV.
 *
 * Magento\Customer\Ui\Component\Listing\Columns::prepare() → updateColumn()
 * runs after child Column::prepare() (prepareChildComponent is children-first).
 */
class CustomerColumns extends MagentoCustomerColumns
{
    /**
     * @inheritdoc
     */
    public function prepare(): void
    {
        parent::prepare();
        $column = $this->getComponent(HideDuplicateCnpjColumn::COLUMN);
        if (!$column) {
            return;
        }

        $config = (array) $column->getData('config');
        $column->setData('config', HideDuplicateCnpjColumn::inColumnConfig($config));
    }
}
