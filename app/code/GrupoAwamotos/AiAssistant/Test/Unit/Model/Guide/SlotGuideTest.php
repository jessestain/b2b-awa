<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Test\Unit\Model\Guide;

use GrupoAwamotos\AiAssistant\Model\Guide\SlotGuide;
use GrupoAwamotos\AiAssistant\Model\Query\ProductQueryParser;
use PHPUnit\Framework\TestCase;

class SlotGuideTest extends TestCase
{
    private SlotGuide $guide;
    private ProductQueryParser $parser;

    protected function setUp(): void
    {
        if (!class_exists(SlotGuide::class, false)) {
            require_once dirname(__DIR__, 4) . '/Model/Guide/SlotGuide.php';
        }
        if (!class_exists(ProductQueryParser::class, false)) {
            require_once dirname(__DIR__, 4) . '/Model/Query/ProductQueryParser.php';
        }
        $this->guide = new SlotGuide();
        $this->parser = new ProductQueryParser();
    }

    public function testStartAsksForPartWithoutCallingCatalog(): void
    {
        $out = $this->guide->advance($this->guide->idle(), '', []);

        $this->assertFalse($out['exit']);
        $this->assertFalse($out['ready']);
        $this->assertTrue($out['state']['active']);
        $this->assertSame(SlotGuide::STEP_PART, $out['state']['step']);
        $this->assertStringContainsString('3 passos', $out['reply']);
    }

    public function testPartChipAdvancesToBike(): void
    {
        $state = $this->guide->start();
        $intent = $this->parser->parse('Retrovisor');
        $out = $this->guide->advance($state, 'Retrovisor', $intent);

        $this->assertFalse($out['ready']);
        $this->assertSame(SlotGuide::STEP_BIKE, $out['state']['step']);
        $this->assertSame('retrovisor', $out['state']['part']);
        $this->assertStringContainsString('moto', mb_strtolower($out['reply']));
    }

    public function testSkipPartDoesNotUseNomeAsPart(): void
    {
        $state = $this->guide->start();
        $message = 'Não sei o nome da peça';
        $out = $this->guide->advance($state, $message, $this->parser->parse($message));

        $this->assertTrue($out['state']['skipped_part']);
        $this->assertSame('', $out['state']['part']);
        $this->assertSame(SlotGuide::STEP_BIKE, $out['state']['step']);
    }

    public function testFullSentenceIsReady(): void
    {
        $state = $this->guide->start();
        $message = 'retrovisor titan 150 2018';
        $out = $this->guide->advance($state, $message, $this->parser->parse($message));

        $this->assertTrue($out['ready']);
        $this->assertSame('Titan 150', $out['state']['model']);
        $this->assertSame('Honda', $out['state']['brand']);
        $this->assertSame('2018', $out['state']['year']);
        $intent = $this->guide->intentFromState($out['state'], []);
        $this->assertTrue($intent['prefers_fitment']);
        $this->assertSame('2018', $intent['year']);
    }

    public function testAnyYearBecomesEmptyForFitment(): void
    {
        $state = $this->guide->start();
        $state['part'] = 'retrovisor';
        $state['model'] = 'Titan 150';
        $state['brand'] = 'Honda';
        $state['step'] = SlotGuide::STEP_YEAR;
        $out = $this->guide->advance($state, 'Qualquer ano', $this->parser->parse('Qualquer ano'));

        $this->assertTrue($out['ready']);
        $this->assertSame('retrovisor', $out['state']['part']);
        $intent = $this->guide->intentFromState($out['state'], []);
        $this->assertSame('', $intent['year']);
    }

    public function testOrderTrackingExitsGuide(): void
    {
        $state = $this->guide->start();
        $message = 'quero rastrear meu pedido';
        $out = $this->guide->advance($state, $message, $this->parser->parse($message));

        $this->assertTrue($out['exit']);
        $this->assertFalse($out['state']['active']);
    }
}
