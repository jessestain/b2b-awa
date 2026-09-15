<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class TaskActions extends Column
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
            if (!isset($item['task_id'])) {
                continue;
            }

            $taskId = (int) $item['task_id'];
            $customerId = (int) ($item['customer_id'] ?? 0);
            $actions = [
                'view360' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId]
                    ),
                    'label' => __('Abrir ficha'),
                ],
                'contact' => [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialcustomer/view',
                        ['customer_id' => $customerId, '_query' => ['tab' => 'contatos']]
                    ),
                    'label' => __('Registrar contato'),
                ],
            ];

            if (($item['status'] ?? '') !== 'done') {
                $actions['complete'] = [
                    'href' => $this->urlBuilder->getUrl('awa_commercial/commercialtask/complete'),
                    'label' => __('Concluir'),
                    'post' => true,
                    'params' => ['task_id' => $taskId],
                ];
                $actions['reschedule'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'awa_commercial/commercialtask/reschedule',
                        ['task_id' => $taskId]
                    ),
                    'label' => __('Reagendar'),
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
