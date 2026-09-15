<?php

/**
 * B2B Carrier Service
 * Manages B2B-specific shipping carrier functionality
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Model\ResourceModel\Carrier\CollectionFactory as CarrierCollectionFactory;
use GrupoAwamotos\B2B\Model\CarrierFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\Carrier as CarrierResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Psr\Log\LoggerInterface;

class CarrierService
{
    /**
     * @var CarrierCollectionFactory
     */
    private $carrierCollectionFactory;

    /**
     * @var CarrierFactory
     */
    private $carrierFactory;

    /**
     * @var CarrierResource
     */
    private $carrierResource;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var B2BHelper
     */
    private $b2bHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CarrierCollectionFactory $carrierCollectionFactory
     * @param CarrierFactory $carrierFactory
     * @param CarrierResource $carrierResource
     * @param CustomerSession $customerSession
     * @param B2BHelper $b2bHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        CarrierCollectionFactory $carrierCollectionFactory,
        CarrierFactory $carrierFactory,
        CarrierResource $carrierResource,
        CustomerSession $customerSession,
        B2BHelper $b2bHelper,
        LoggerInterface $logger
    ) {
        $this->carrierCollectionFactory = $carrierCollectionFactory;
        $this->carrierFactory = $carrierFactory;
        $this->carrierResource = $carrierResource;
        $this->customerSession = $customerSession;
        $this->b2bHelper = $b2bHelper;
        $this->logger = $logger;
    }

    /**
     * Check if current customer is B2B
     *
     * @return bool
     */
    public function isB2BCustomer(): bool
    {
        return $this->b2bHelper->isB2BCustomer();
    }

    /**
     * Get available B2B carriers
     *
     * @return ResourceModel\Carrier\Collection
     */
    public function getAvailableCarriers(): ResourceModel\Carrier\Collection
    {
        return $this->carrierCollectionFactory->create()
            ->addActiveFilter()
            ->addSortOrder();
    }

    /**
     * Get carrier by ID
     *
     * @param int $carrierId
     * @return Carrier
     * @throws NoSuchEntityException
     */
    public function getCarrier(int $carrierId): Carrier
    {
        $carrier = $this->carrierFactory->create();
        $this->carrierResource->load($carrier, $carrierId);

        if (!$carrier->getId()) {
            throw new NoSuchEntityException(__('Transportadora não encontrada.'));
        }

        return $carrier;
    }

    /**
     * Get carrier by code
     *
     * @param string $code
     * @return Carrier
     * @throws NoSuchEntityException
     */
    public function getCarrierByCode(string $code): Carrier
    {
        $carrier = $this->carrierFactory->create();
        $this->carrierResource->load($carrier, $code, 'code');

        if (!$carrier->getId()) {
            throw new NoSuchEntityException(__('Transportadora não encontrada.'));
        }

        return $carrier;
    }

    /**
     * Create new carrier
     *
     * @param array $data
     * @return Carrier
     * @throws LocalizedException
     */
    public function createCarrier(array $data): Carrier
    {
        if (empty($data['code']) || empty($data['name'])) {
            throw new LocalizedException(__('Código e nome são obrigatórios.'));
        }

        // Check if code already exists
        try {
            $this->getCarrierByCode($data['code']);
            throw new LocalizedException(__('Já existe uma transportadora com este código.'));
        // Not finding the carrier is the expected/desired outcome here (means the code is
        // free to use), not a failure — logging it would be noise on every successful create.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (NoSuchEntityException $e) {
        }

        $carrier = $this->carrierFactory->create();
        $carrier->setData([
            'code' => $data['code'],
            'name' => $data['name'],
            'logo' => $data['logo'] ?? null,
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
            'description' => $data['description'] ?? null,
            'delivery_time' => $data['delivery_time'] ?? null,
            'is_active' => $data['is_active'] ?? 1,
            'sort_order' => $data['sort_order'] ?? 0
        ]);

        $this->carrierResource->save($carrier);

        return $carrier;
    }

    /**
     * Update carrier
     *
     * @param int $carrierId
     * @param array $data
     * @return Carrier
     * @throws NoSuchEntityException
     */
    public function updateCarrier(int $carrierId, array $data): Carrier
    {
        $carrier = $this->getCarrier($carrierId);

        $allowedFields = ['name', 'logo', 'phone', 'website', 'description', 'delivery_time', 'is_active', 'sort_order'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $carrier->setData($field, $data[$field]);
            }
        }

        $this->carrierResource->save($carrier);

        return $carrier;
    }

    /**
     * Delete carrier
     *
     * @param int $carrierId
     * @return bool
     * @throws NoSuchEntityException
     */
    public function deleteCarrier(int $carrierId): bool
    {
        $carrier = $this->getCarrier($carrierId);
        $this->carrierResource->delete($carrier);
        return true;
    }

    /**
     * Get carriers as options for select
     *
     * @return array
     */
    public function getCarrierOptions(): array
    {
        $options = [];
        $carriers = $this->getAvailableCarriers();

        foreach ($carriers as $carrier) {
            $options[] = [
                'value' => $carrier->getCode(),
                'label' => $carrier->getName()
            ];
        }

        return $options;
    }

    /**
     * Seed default Brazilian carriers
     *
     * @return array Created carriers
     */
    public function seedDefaultCarriers(): array
    {
        $defaultCarriers = [
            [
                'code' => 'correios_sedex',
                'name' => 'Correios - SEDEX',
                'description' => 'Entrega expressa nacional',
                'delivery_time' => '1-3 dias úteis',
                'is_active' => 1,
                'sort_order' => 10
            ],
            [
                'code' => 'correios_pac',
                'name' => 'Correios - PAC',
                'description' => 'Entrega econômica nacional',
                'delivery_time' => '5-10 dias úteis',
                'is_active' => 1,
                'sort_order' => 20
            ],
            [
                'code' => 'jadlog',
                'name' => 'Jadlog',
                'description' => 'Transportadora para todo Brasil',
                'delivery_time' => '3-7 dias úteis',
                'phone' => '0800 725 3564',
                'website' => 'https://www.jadlog.com.br',
                'is_active' => 1,
                'sort_order' => 30
            ],
            [
                'code' => 'tnt',
                'name' => 'TNT / FedEx',
                'description' => 'Transportadora internacional',
                'delivery_time' => '2-5 dias úteis',
                'website' => 'https://www.fedex.com/pt-br',
                'is_active' => 1,
                'sort_order' => 40
            ],
            [
                'code' => 'total_express',
                'name' => 'Total Express',
                'description' => 'Entrega expressa e-commerce',
                'delivery_time' => '2-4 dias úteis',
                'website' => 'https://www.totalexpress.com.br',
                'is_active' => 1,
                'sort_order' => 50
            ],
            [
                'code' => 'loggi',
                'name' => 'Loggi',
                'description' => 'Entrega urbana rápida',
                'delivery_time' => 'Mesmo dia ou 1 dia útil',
                'website' => 'https://www.loggi.com',
                'is_active' => 1,
                'sort_order' => 60
            ],
            [
                'code' => 'braspress',
                'name' => 'Braspress',
                'description' => 'Cargas fracionadas',
                'delivery_time' => '3-8 dias úteis',
                'phone' => '0800 770 8282',
                'website' => 'https://www.braspress.com.br',
                'is_active' => 1,
                'sort_order' => 70
            ],
            [
                'code' => 'retira_loja',
                'name' => 'Retirar na Loja',
                'description' => 'Retire seu pedido em nossa loja',
                'delivery_time' => 'Disponível em 24h',
                'is_active' => 1,
                'sort_order' => 100
            ]
        ];

        $created = [];
        foreach ($defaultCarriers as $data) {
            try {
                $carrier = $this->createCarrier($data);
                $created[] = $carrier;
            } catch (\Exception $e) {
                $this->logger->debug(
                    '[CarrierService::seedDefaultCarriers] Transportadora já existe, pulando: '
                    . $e->getMessage()
                );
            }
        }

        return $created;
    }
}
