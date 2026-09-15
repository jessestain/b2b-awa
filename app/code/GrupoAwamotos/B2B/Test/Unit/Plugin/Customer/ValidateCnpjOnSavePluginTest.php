<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Plugin\Customer;

use GrupoAwamotos\B2B\Helper\CnpjValidator;
use GrupoAwamotos\B2B\Plugin\Customer\ValidateCnpjOnSavePlugin;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\ResourceModel\Customer\Collection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\AttributeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Plugin\Customer\ValidateCnpjOnSavePlugin
 */
class ValidateCnpjOnSavePluginTest extends TestCase
{
    private CnpjValidator&MockObject $cnpjValidator;
    private CustomerCollectionFactory&MockObject $collectionFactory;
    private ValidateCnpjOnSavePlugin $plugin;

    protected function setUp(): void
    {
        $this->cnpjValidator = $this->createMock(CnpjValidator::class);
        $this->collectionFactory = $this->createMock(CustomerCollectionFactory::class);
        $this->plugin = new ValidateCnpjOnSavePlugin(
            $this->cnpjValidator,
            $this->collectionFactory
        );
    }

    public function testBeforeSaveCopiesCnpjToEmptyTaxvat(): void
    {
        $this->cnpjValidator->method('validateLocal')->willReturn(true);
        $this->cnpjValidator->method('format')->willReturn('60.127.061/0001-46');
        $this->stubEmptyCollection();

        $cnpjAttr = $this->createMock(AttributeInterface::class);
        $cnpjAttr->method('getValue')->willReturn('60127061000146');

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnCallback(
            static fn (string $code): ?AttributeInterface => $code === 'b2b_cnpj' ? $cnpjAttr : null
        );
        $customer->method('getTaxvat')->willReturn('');
        $customer->method('getId')->willReturn(null);
        $customer->expects($this->once())->method('setCustomAttribute')->with('b2b_cnpj', '60.127.061/0001-46');
        $customer->expects($this->once())->method('setTaxvat')->with('60.127.061/0001-46');

        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $result = $this->plugin->beforeSave($repository, $customer);

        $this->assertSame($customer, $result[0]);
    }

    public function testBeforeSaveSkipsWhenNoCnpjAndNoB2bContext(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);
        $customer->expects($this->never())->method('setTaxvat');

        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $result = $this->plugin->beforeSave($repository, $customer);

        $this->assertSame($customer, $result[0]);
    }

    private function stubEmptyCollection(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collectionFactory->method('create')->willReturn($collection);
    }
}
