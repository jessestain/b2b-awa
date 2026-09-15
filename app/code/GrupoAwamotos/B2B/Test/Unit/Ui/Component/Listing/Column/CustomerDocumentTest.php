<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Ui\Component\Listing\Column;

use GrupoAwamotos\B2B\Ui\Component\Listing\Column\CustomerDocument;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Ui\Component\Listing\Column\CustomerDocument
 */
class CustomerDocumentTest extends TestCase
{
    public function testResolveKeepsTaxvatWhenPresent(): void
    {
        $this->assertSame(
            '123.456.789-09',
            CustomerDocument::resolve([
                'taxvat' => '123.456.789-09',
                'b2b_cnpj' => '60.127.061/0001-46',
            ])
        );
    }

    public function testResolveFallsBackToB2bCnpjWhenTaxvatEmpty(): void
    {
        $this->assertSame(
            '60.127.061/0001-46',
            CustomerDocument::resolve([
                'taxvat' => '',
                'b2b_cnpj' => '60.127.061/0001-46',
            ])
        );
    }

    public function testResolveFallsBackWhenTaxvatNull(): void
    {
        $this->assertSame(
            '08.791.526/0001-49',
            CustomerDocument::resolve([
                'taxvat' => null,
                'b2b_cnpj' => '08.791.526/0001-49',
            ])
        );
    }

    public function testResolveReturnsEmptyWhenBothMissing(): void
    {
        $this->assertSame('', CustomerDocument::resolve([]));
    }

    public function testPrepareDataSourceFillsEmptyTaxvatColumn(): void
    {
        $column = $this->getMockBuilder(CustomerDocument::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $column->method('getData')->willReturnCallback(
            static function (string $key) {
                return $key === 'name' ? 'taxvat' : ['visible' => true];
            }
        );

        $result = $column->prepareDataSource([
            'data' => [
                'items' => [
                    ['entity_id' => 9107, 'taxvat' => null, 'b2b_cnpj' => '60.127.061/0001-46'],
                    ['entity_id' => 1, 'taxvat' => '111.222.333-44', 'b2b_cnpj' => ''],
                ],
            ],
        ]);

        $this->assertSame('60.127.061/0001-46', $result['data']['items'][0]['taxvat']);
        $this->assertSame('111.222.333-44', $result['data']['items'][1]['taxvat']);
    }
}
