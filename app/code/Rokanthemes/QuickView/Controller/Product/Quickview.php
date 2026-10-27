<?php
/**
 *
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Rokanthemes\QuickView\Controller\Product;

use Rokanthemes\QuickView\Controller\Product as ProductController;
use Magento\Framework\Controller\ResultFactory;

class Quickview extends ProductController
{
    public function execute()
    {
        $pr = $this->initProduct();

        // GUARDA: initProduct() devolve FALSE quando o id do produto nao existe (link velho,
        // crawler, id arbitrario). Sem isto, a linha abaixo chama getTypeId() em false e o
        // Magento devolve 500 + ~8 KB de exception.log POR REQUISICAO.
        // Medido em 04/10/2026: /quickview/product/quickview/id/999999999/ -> 500, e cada
        // tentativa soma ~8 KB ao var/log/exception.log.
        if (!$pr || !$pr->getId()) {
            /** @var \Magento\Framework\Controller\Result\Forward $forward */
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
            return $forward->forward('noroute');
        }

        /** @var \Magento\Framework\View\Result\Layout $resultLayout */
        $resultLayout = $this->resultFactory->create(ResultFactory::TYPE_LAYOUT);
        $resultLayout->addHandle('catalog_product_view_type_' . $pr->getTypeId());
        return $resultLayout;
    }
}
