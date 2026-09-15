<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class AbandonedCartActions extends Column
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

            $entityId = (int) $item['entity_id'];
            $customerId = (int) ($item['customer_id'] ?? 0);
            $item[$this->getData('name')] = [
                'view360' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId]
                    ),
                    'label' => __('Ficha 360°'),
                ],
                'contact' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'contatos']]
                    ),
                    'label' => __('Registrar contato'),
                ],
                'create_task' => [
                    'href' => $this->urlBuilder->getUrl('awa_commercial/commercialabandonedcart/createTask'),
                    'label' => __('Criar tarefa'),
                    'post' => true,
                    'params' => ['entity_id' => $entityId],
                ],
                'treat' => [
                    'href' => $this->urlBuilder->getUrl('awa_commercial/commercialabandonedcart/treat'),
                    'label' => __('Marcar tratado'),
                    'post' => true,
                    'params' => ['entity_id' => $entityId],
                ],
            ];
        }

        return $dataSource;
    }
}
