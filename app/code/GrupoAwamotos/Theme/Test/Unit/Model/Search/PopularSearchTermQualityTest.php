<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Model\Search;

use GrupoAwamotos\Theme\Model\Search\PopularSearchTermQuality;
use PHPUnit\Framework\TestCase;

class PopularSearchTermQualityTest extends TestCase
{
    private PopularSearchTermQuality $quality;

    protected function setUp(): void
    {
        $this->quality = new PopularSearchTermQuality();
    }

    /**
     * @dataProvider displayableProvider
     */
    public function testIsDisplayable(string $label, ?int $numResults, bool $expected): void
    {
        self::assertSame($expected, $this->quality->isDisplayable($label, $numResults));
    }

    /**
     * @return array<string, array{0: string, 1: ?int, 2: bool}>
     */
    public static function displayableProvider(): array
    {
        return [
            'product name' => ['retrovisor', 151, true],
            'model with number' => ['cg 160', 90, true],
            'phrase' => ['29 litros baú', 1, true],
            'sku digits' => ['502', 3, false],
            'sku padded' => ['0037', 1, false],
            'sku plus code' => ['617 DR', 1, false],
            'audit junk' => ['zzzinexistentewaudit20260712', 0, false],
            'zero results' => ['capacete', 0, false],
            'single letter' => ['a', 539, false],
            'empty' => ['', 10, false],
        ];
    }
}
