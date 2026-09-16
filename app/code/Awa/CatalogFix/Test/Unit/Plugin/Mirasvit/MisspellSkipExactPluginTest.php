<?php

declare(strict_types=1);

namespace Awa\CatalogFix\Test\Unit\Plugin\Mirasvit;

use Awa\CatalogFix\Plugin\Mirasvit\MisspellSkipExactPlugin;
use Awa\CatalogFix\Model\Search\SkuQueryClassifier;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Mirasvit\Misspell\Observer\OnCatalogSearchObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MisspellSkipExactPluginTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private OnCatalogSearchObserver&MockObject $subject;
    private EventObserver&MockObject $observer;
    private SkuQueryClassifier&MockObject $skuQueryClassifier;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->subject = $this->createMock(OnCatalogSearchObserver::class);
        $this->observer = $this->createMock(EventObserver::class);
        $this->skuQueryClassifier = $this->createMock(SkuQueryClassifier::class);
    }

    private function createSut(): MisspellSkipExactPlugin
    {
        return new MisspellSkipExactPlugin($this->request, $this->skuQueryClassifier);
    }

    public function testExactStringOneSkipsMisspell(): void
    {
        $this->request->method('getParam')->with('exact')->willReturn('1');
        $called = false;

        $this->createSut()->aroundExecute(
            $this->subject,
            function () use (&$called): void {
                $called = true;
            },
            $this->observer
        );

        $this->assertFalse($called);
    }

    public function testWithoutExactProceeds(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return $key === 'q' ? 'capacete preto' : $default;
            }
        );
        $called = false;

        $this->createSut()->aroundExecute(
            $this->subject,
            function () use (&$called): void {
                $called = true;
            },
            $this->observer
        );

        $this->assertTrue($called);
    }

    public function testSkuLikeQuerySkipsMisspell(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return $key === 'q' ? '3022' : $default;
            }
        );
        $this->skuQueryClassifier->method('isExistingExactSkuQuery')
            ->with('3022')
            ->willReturn(true);
        $called = false;

        $this->createSut()->aroundExecute(
            $this->subject,
            function () use (&$called): void {
                $called = true;
            },
            $this->observer
        );

        $this->assertFalse($called);
    }

    public function testTextTokenMatchingSkuPatternDoesNotSkipMisspell(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) {
                return $key === 'q' ? 'bagageiro' : $default;
            }
        );
        $this->skuQueryClassifier->method('isExistingExactSkuQuery')
            ->with('bagageiro')
            ->willReturn(false);
        $called = false;

        $this->createSut()->aroundExecute(
            $this->subject,
            function () use (&$called): void {
                $called = true;
            },
            $this->observer
        );

        $this->assertTrue($called);
    }

    public function testExactIntOneSkipsMisspell(): void
    {
        $this->request->method('getParam')->with('exact')->willReturn(1);
        $called = false;

        $this->createSut()->aroundExecute(
            $this->subject,
            function () use (&$called): void {
                $called = true;
            },
            $this->observer
        );

        $this->assertFalse($called);
    }
}
