<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\CommercialPanel\Model;

use GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope;
use GrupoAwamotos\B2B\Helper\CurrentAttendant;
use GrupoAwamotos\B2B\Model\ResourceModel\Attendant\Collection as AttendantCollection;
use GrupoAwamotos\B2B\Model\ResourceModel\Attendant\CollectionFactory as AttendantCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\Collection as CustomerAttendantCollection;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\CollectionFactory as CustomerAttendantCollectionFactory;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope
 */
class PortfolioScopeTest extends TestCase
{
    private AuthorizationInterface&MockObject $authorization;
    private CurrentAttendant&MockObject $currentAttendant;
    private CustomerAttendantCollectionFactory&MockObject $customerAttendantCollectionFactory;
    private AttendantCollectionFactory&MockObject $attendantCollectionFactory;

    protected function setUp(): void
    {
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->currentAttendant = $this->createMock(CurrentAttendant::class);
        $this->customerAttendantCollectionFactory = $this->createMock(CustomerAttendantCollectionFactory::class);
        $this->attendantCollectionFactory = $this->createMock(AttendantCollectionFactory::class);
    }

    public function testSellerSeesOnlyOwnPortfolio(): void
    {
        $this->authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => !in_array($resource, [
                'GrupoAwamotos_B2B::commercial_all_portfolios',
                'GrupoAwamotos_B2B::b2b',
            ], true)
        );

        $this->currentAttendant->method('getId')->willReturn(7);

        $customerCollection = $this->createMock(CustomerAttendantCollection::class);
        $customerCollection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('attendant_id', ['in' => [7]])
            ->willReturnSelf();
        $customerCollection->method('getColumnValues')->with('customer_id')->willReturn(['101', '102']);

        $this->customerAttendantCollectionFactory->method('create')->willReturn($customerCollection);

        $scope = $this->createScope();

        $this->assertSame([7], $scope->getVisibleAttendantIds());
        $this->assertSame([101, 102], $scope->getVisibleCustomerIds());
        $this->assertTrue($scope->canAccessCustomer(101));
        $this->assertFalse($scope->canAccessCustomer(999));
        $this->assertFalse($scope->canViewAllPortfolios());
        $this->assertTrue($scope->isCockpitOnlyUser());
        $this->assertFalse($scope->canBypassPortfolioScope());
    }

    public function testSupervisorSeesAllPortfolios(): void
    {
        $this->authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, [
                'GrupoAwamotos_B2B::commercial_all_portfolios',
                'GrupoAwamotos_B2B::commercial_cockpit_only',
            ], true)
        );

        $attendantCollection = $this->createMock(AttendantCollection::class);
        $attendantCollection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(static function (string $field, mixed $condition) use ($attendantCollection): AttendantCollection {
                static $call = 0;
                if ($call === 0) {
                    self::assertSame('is_active', $field);
                    self::assertSame(1, $condition);
                } elseif ($call === 1) {
                    self::assertSame('admin_user_id', $field);
                    self::assertSame(['notnull' => true], $condition);
                }
                $call++;

                return $attendantCollection;
            });
        $attendantCollection->method('getColumnValues')->with('attendant_id')->willReturn(['3', '7']);

        $this->attendantCollectionFactory->method('create')->willReturn($attendantCollection);

        $customerCollection = $this->createMock(CustomerAttendantCollection::class);
        $customerCollection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('attendant_id', ['in' => [3, 7]])
            ->willReturnSelf();
        $customerCollection->method('getColumnValues')->with('customer_id')->willReturn(['101', '205']);

        $this->customerAttendantCollectionFactory->method('create')->willReturn($customerCollection);

        $scope = $this->createScope();

        $this->assertTrue($scope->canViewAllPortfolios());
        $this->assertTrue($scope->canBypassPortfolioScope());
        $this->assertTrue($scope->canAccessCustomer(999));
        $this->assertSame([3, 7], $scope->getVisibleAttendantIds());
        $this->assertSame([101, 205], $scope->getVisibleCustomerIds());
    }

    public function testAdministratorWithAllAclsBypassesPortfolioScope(): void
    {
        $this->authorization->method('isAllowed')->willReturn(true);

        $scope = $this->createScope();

        $this->assertTrue($scope->canViewAllPortfolios());
        $this->assertFalse($scope->isCockpitOnlyUser());
        $this->assertTrue($scope->canBypassPortfolioScope());
        $this->assertTrue($scope->canAccessCustomer(9107));
    }

    public function testCockpitOnlyUserDetection(): void
    {
        $this->authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, [
                'GrupoAwamotos_B2B::commercial_cockpit_only',
                'GrupoAwamotos_B2B::commercial_dashboard',
            ], true)
        );

        $this->currentAttendant->method('getId')->willReturn(null);

        $customerCollection = $this->createMock(CustomerAttendantCollection::class);
        $customerCollection->method('addFieldToFilter')->willReturnSelf();
        $customerCollection->method('getColumnValues')->willReturn([]);
        $this->customerAttendantCollectionFactory->method('create')->willReturn($customerCollection);

        $scope = $this->createScope();

        $this->assertTrue($scope->isCockpitOnlyUser());
    }

    public function testTiBypassesPortfolioScope(): void
    {
        $this->authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $resource !== 'GrupoAwamotos_B2B::commercial_cockpit_only'
        );

        $scope = $this->createScope();

        $this->assertTrue($scope->canBypassPortfolioScope());
        $this->assertTrue($scope->canAccessCustomer(999));
        $this->assertFalse($scope->isCockpitOnlyUser());
    }

    private function createScope(): PortfolioScope
    {
        return new PortfolioScope(
            $this->authorization,
            $this->currentAttendant,
            $this->customerAttendantCollectionFactory,
            $this->attendantCollectionFactory
        );
    }
}
