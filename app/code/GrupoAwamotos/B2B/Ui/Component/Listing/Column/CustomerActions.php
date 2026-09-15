<?php

/**
 * Customer Actions Column for Admin Grid
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class CustomerActions extends Column
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                $name = $this->getData('name');
                if (isset($item['entity_id'])) {
                    $item[$name]['approve'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'grupoawamotos_b2b/customer/approve',
                            ['customer_id' => $item['entity_id']]
                        ),
                        'label' => __('Aprovar'),
                        'confirm' => [
                            'title' => __('Aprovar Cliente'),
                            'message' => __('Tem certeza que deseja aprovar este cliente?'),
                        ],
                        'post' => true,
                    ];
                    $item[$name]['reject'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'grupoawamotos_b2b/customer/reject',
                            ['customer_id' => $item['entity_id']]
                        ),
                        'label' => __('Rejeitar'),
                        'confirm' => [
                            'title' => __('Rejeitar Cliente'),
                            'message' => __('Tem certeza que deseja rejeitar este cliente?'),
                        ],
                        'post' => true,
                    ];
                    $item[$name]['review'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'grupoawamotos_b2b/customer/requestReview',
                            ['customer_id' => $item['entity_id']]
                        ),
                        'label' => __('Pedir dados'),
                        'confirm' => [
                            'title' => __('Pedir dados ao cliente'),
                            'message' => __('Enviar o cadastro para revisão e pedir informações adicionais?'),
                        ],
                        'post' => true,
                    ];
                    $item[$name]['view'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'customer/index/edit',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Ficha Magento'),
                    ];
                    $item[$name]['commercial'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'awa_commercial/commercialcustomer/view',
                            ['customer_id' => $item['entity_id']]
                        ),
                        'label' => __('Ficha comercial'),
                    ];
                }
            }
        }

        return $dataSource;
    }
}
