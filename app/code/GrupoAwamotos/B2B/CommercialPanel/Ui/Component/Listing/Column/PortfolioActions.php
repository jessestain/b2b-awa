<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class PortfolioActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }

            $customerId = (int) $item['entity_id'];
            $item[$this->getData('name')] = [
                'view360' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId]
                    ),
                    'label' => __('Ver Ficha 360°'),
                ],
                'contact' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'contatos']]
                    ),
                    'label' => __('Registrar Contato'),
                ],
                'create_task' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'tarefas']]
                    ),
                    'label' => __('Criar Tarefa'),
                ],
                'orders' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'pedidos']]
                    ),
                    'label' => __('Ver Pedidos'),
                ],
                'quotes' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'cotacoes']]
                    ),
                    'label' => __('Ver Cotações'),
                ],
            ];
        }

        return $dataSource;
    }
}
