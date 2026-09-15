<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\CommercialPanel\Plugin;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Plugin\DeniedPageDebugPlugin;
use Magento\Backend\Controller\Adminhtml\Denied\Index;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\CommercialPanel\Plugin\DeniedPageDebugPlugin
 */
class DeniedPageDebugPluginTest extends TestCase
{
    private AdminSession&MockObject $adminSession;
    private PortfolioScopeInterface&MockObject $portfolioScope;
    private AuthorizationInterface&MockObject $authorization;
    private RedirectFactory&MockObject $redirectFactory;
    private DeniedPageDebugPlugin $plugin;

    protected function setUp(): void
    {
        $this->adminSession = $this->createMock(AdminSession::class);
        $this->portfolioScope = $this->createMock(PortfolioScopeInterface::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->redirectFactory = $this->createMock(RedirectFactory::class);

        $this->plugin = new DeniedPageDebugPlugin(
            $this->adminSession,
            $this->portfolioScope,
            $this->authorization,
            $this->redirectFactory
        );
    }

    public function testFullAdminSeesDeniedPage(): void
    {
        $this->adminSession->method('isLoggedIn')->willReturn(true);
        $this->authorization->method('isAllowed')
            ->with('Magento_Backend::all')
            ->willReturn(true);
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(false);

        $proceedCalled = false;
        $result = $this->plugin->aroundExecute(
            $this->createMock(Index::class),
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'denied';
            }
        );

        $this->assertTrue($proceedCalled);
        $this->assertSame('denied', $result);
    }

    public function testCockpitOnlyUserIsRedirectedToDashboard(): void
    {
        $this->adminSession->method('isLoggedIn')->willReturn(true);
        $this->authorization->method('isAllowed')
            ->with('Magento_Backend::all')
            ->willReturn(false);
        $this->portfolioScope->method('isCockpitOnlyUser')->willReturn(true);

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())
            ->method('setPath')
            ->with('awa_commercial/commercialdashboard/index')
            ->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($redirect);

        $proceedCalled = false;
        $result = $this->plugin->aroundExecute(
            $this->createMock(Index::class),
            static function () use (&$proceedCalled) {
                $proceedCalled = true;
                return 'denied';
            }
        );

        $this->assertFalse($proceedCalled);
        $this->assertSame($redirect, $result);
    }
}
