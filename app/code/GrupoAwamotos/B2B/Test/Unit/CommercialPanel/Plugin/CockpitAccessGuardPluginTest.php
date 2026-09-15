<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\CommercialPanel\Plugin;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\CockpitAccessGuard;
use GrupoAwamotos\B2B\CommercialPanel\Plugin\CockpitAccessGuardPlugin;
use Magento\Backend\App\AbstractAction;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\CommercialPanel\Plugin\CockpitAccessGuardPlugin
 */
class CockpitAccessGuardPluginTest extends TestCase
{
    private PortfolioScopeInterface&MockObject $portfolioScope;
    private RedirectFactory&MockObject $redirectFactory;
    private CockpitAccessGuardPlugin $plugin;

    protected function setUp(): void
    {
        $this->portfolioScope = $this->createMock(PortfolioScopeInterface::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);

        $this->plugin = new CockpitAccessGuardPlugin(
            $this->portfolioScope,
            new CockpitAccessGuard(),
            $this->redirectFactory,
            $this->createMock(UrlInterface::class),
            $this->createMock(ManagerInterface::class)
        );
    }

    public function testAllowsCommercialRouteForCockpitOnlyUser(): void
    {
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(true);
        $request = $this->createRequest('awa_commercial', 'commercialcustomer', 'view');
        $action = $this->createMock(AbstractAction::class);

        $proceedCalled = false;
        $result = $this->plugin->aroundDispatch(
            $action,
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'ok';
            },
            $request
        );

        $this->assertTrue($proceedCalled);
        $this->assertSame('ok', $result);
    }

    public function testBlocksTechnicalRouteForCockpitOnlyUser(): void
    {
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(true);
        $request = $this->createRequest('catalog', 'product', 'index');
        $action = $this->createMock(AbstractAction::class);

        $redirect = $this->createMock(Redirect::class);
        $this->redirectFactory->method('create')->willReturn($redirect);

        $proceedCalled = false;
        $result = $this->plugin->aroundDispatch(
            $action,
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'ok';
            },
            $request
        );

        $this->assertFalse($proceedCalled);
        $this->assertSame($redirect, $result);
    }

    public function testAllowsOrderCreateForCockpitOnlyUser(): void
    {
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(true);
        $request = $this->createRequest('sales', 'order_create', 'index');
        $action = $this->createMock(AbstractAction::class);

        $proceedCalled = false;
        $result = $this->plugin->aroundDispatch(
            $action,
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'ok';
            },
            $request
        );

        $this->assertTrue($proceedCalled);
        $this->assertSame('ok', $result);
    }

    public function testTiUserIsNotBlocked(): void
    {
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(false);
        $request = $this->createRequest('catalog', 'product', 'index');
        $action = $this->createMock(AbstractAction::class);

        $proceedCalled = false;
        $result = $this->plugin->aroundDispatch(
            $action,
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'ok';
            },
            $request
        );

        $this->assertTrue($proceedCalled);
        $this->assertSame('ok', $result);
    }

    private function createRequest(string $routeName, string $controller, string $action): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getRouteName')->willReturn($routeName);
        $request->method('getControllerName')->willReturn($controller);
        $request->method('getActionName')->willReturn($action);

        return $request;
    }
}
