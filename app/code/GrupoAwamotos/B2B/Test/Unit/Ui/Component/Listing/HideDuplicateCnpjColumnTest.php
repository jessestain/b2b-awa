<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Ui\Component\Listing;

use GrupoAwamotos\B2B\Ui\Component\Listing\HideDuplicateCnpjColumn;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Ui\Component\Listing\HideDuplicateCnpjColumn
 */
class HideDuplicateCnpjColumnTest extends TestCase
{
    public function testInColumnConfigForcesHidden(): void
    {
        $out = HideDuplicateCnpjColumn::inColumnConfig(['visible' => true, 'label' => 'CNPJ']);
        $this->assertFalse($out['visible']);
        $this->assertSame('CNPJ', $out['label']);
    }

    public function testInBookmarkConfigHidesCurrentAndDefaultViews(): void
    {
        $config = [
            'current' => [
                'columns' => [
                    'taxvat' => ['visible' => true, 'sorting' => false],
                    'b2b_cnpj' => ['visible' => true, 'sorting' => false],
                ],
            ],
            'views' => [
                'default' => [
                    'data' => [
                        'columns' => [
                            'b2b_cnpj' => ['visible' => true],
                        ],
                    ],
                ],
            ],
        ];

        $out = HideDuplicateCnpjColumn::inBookmarkConfig($config);

        $this->assertTrue($out['current']['columns']['taxvat']['visible']);
        $this->assertFalse($out['current']['columns']['b2b_cnpj']['visible']);
        $this->assertFalse($out['views']['default']['data']['columns']['b2b_cnpj']['visible']);
        $this->assertFalse(HideDuplicateCnpjColumn::isCnpjVisible($out));
        $this->assertTrue(HideDuplicateCnpjColumn::isCnpjVisible($config));
    }
}
