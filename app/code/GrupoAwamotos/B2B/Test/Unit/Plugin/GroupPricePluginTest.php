<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Plugin;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Plugin\GroupPricePlugin;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Http\Context as HttpContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Plugin\GroupPricePlugin
 */
class GroupPricePluginTest extends TestCase
{
    private Config&MockObject $config;
    private CustomerSession&MockObject $customerSession;
    private CustomerPriceProvider&MockObject $customerPriceProvider;
    private HttpContext&MockObject $httpContext;
    private GroupPricePlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->customerPriceProvider = $this->createMock(CustomerPriceProvider::class);
        $this->httpContext = $this->createMock(HttpContext::class);

        $this->plugin = new GroupPricePlugin(
            $this->config,
            $this->customerSession,
            $this->createMock(CustomerRepositoryInterface::class),
            $this->customerPriceProvider,
            $this->createMock(SyncLogResource::class),
            $this->httpContext
        );
    }

    public function testUsesHttpContextPriceListWhenSessionIsDepersonalized(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->httpContext->method('getValue')->willReturnMap([
            [CustomerContext::CONTEXT_AUTH, true],
            ['b2b_approval_status', 'approved'],
            ['erp_price_list', '45:3'],
        ]);
        $this->customerSession->method('getCustomerId')->willReturn(0);
        $this->customerPriceProvider->expects($this->never())->method('getCustomerPrice');
        $this->customerPriceProvider->expects($this->once())
            ->method('getAuthorizedListPrice')
            ->with(45, '3022')
            ->willReturn(78.66);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(315);
        $product->method('getSku')->willReturn('3022');
        $product->expects($this->exactly(4))->method('setData');

        $this->assertSame(78.66, $this->plugin->afterGetPrice($product, 90.69));
    }

    public function testLeavesIndexedPriceWhenListTokenIsDefaultOrMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->httpContext->method('getValue')->willReturnMap([
            [CustomerContext::CONTEXT_AUTH, true],
            ['b2b_approval_status', 'approved'],
            ['erp_price_list', 'logged_in'],
        ]);
        $this->customerSession->method('getCustomerId')->willReturn(0);
        $this->customerPriceProvider->expects($this->never())->method('getAuthorizedListPrice');

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(315);
        $product->method('getSku')->willReturn('3022');

        $this->assertSame(90.69, $this->plugin->afterGetPrice($product, 90.69));
    }
}
