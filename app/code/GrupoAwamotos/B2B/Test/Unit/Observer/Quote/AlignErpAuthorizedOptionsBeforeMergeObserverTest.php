<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Observer\Quote;

use GrupoAwamotos\B2B\Model\Quote\ErpAuthorizedPriceApplier;
use GrupoAwamotos\B2B\Observer\Quote\AlignErpAuthorizedOptionsBeforeMergeObserver;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Observer\Quote\AlignErpAuthorizedOptionsBeforeMergeObserver
 */
class AlignErpAuthorizedOptionsBeforeMergeObserverTest extends TestCase
{
    private CheckoutSession&MockObject $checkoutSession;
    private State&MockObject $appState;
    private AlignErpAuthorizedOptionsBeforeMergeObserver $observer;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->appState = $this->createMock(State::class);
        $this->observer = new AlignErpAuthorizedOptionsBeforeMergeObserver(
            $this->checkoutSession,
            $this->appState
        );
    }

    public function testCopiesAuthorizedOptionsOntoProductBeingAdded(): void
    {
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);

        $listOption = $this->createMock(Option::class);
        $listOption->method('getValue')->willReturn('45');
        $priceOption = $this->createMock(Option::class);
        $priceOption->method('getValue')->willReturn('78.66');

        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isDeleted', 'getOptionByCode'])
            ->addMethods(['getProductId'])
            ->getMock();
        $item->method('isDeleted')->willReturn(false);
        $item->method('getProductId')->willReturn(315);
        $item->method('getOptionByCode')->willReturnMap([
            [ErpAuthorizedPriceApplier::OPTION_PRICE_LIST, $listOption],
            [ErpAuthorizedPriceApplier::OPTION_UNIT_PRICE, $priceOption],
        ]);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(4094);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(315);
        $copied = [];
        $product->expects($this->exactly(2))
            ->method('addCustomOption')
            ->willReturnCallback(static function (string $code, string $value) use (&$copied, $product): Product {
                $copied[$code] = $value;
                return $product;
            });

        $this->observer->execute($this->createObserver($product));

        $this->assertSame('45', $copied[ErpAuthorizedPriceApplier::OPTION_PRICE_LIST]);
        $this->assertSame('78.66', $copied[ErpAuthorizedPriceApplier::OPTION_UNIT_PRICE]);
    }

    public function testSkipsAdminhtmlArea(): void
    {
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $this->checkoutSession->expects($this->never())->method('getQuote');

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(315);

        $this->observer->execute($this->createObserver($product));
    }

    public function testDoesNotCopyWhenQuoteHasNoMatchingItem(): void
    {
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(1);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(315);
        $product->expects($this->never())->method('addCustomOption');

        $this->observer->execute($this->createObserver($product));
    }

    private function createObserver(Product $product): Observer
    {
        $event = new Event(['product' => $product]);
        $observer = new Observer();
        $observer->setEvent($event);

        return $observer;
    }
}
