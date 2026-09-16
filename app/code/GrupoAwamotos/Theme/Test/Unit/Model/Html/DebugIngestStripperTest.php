<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Model\Html;

use GrupoAwamotos\Theme\Model\Html\DebugIngestStripper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\Theme\Model\Html\DebugIngestStripper
 */
class DebugIngestStripperTest extends TestCase
{
    public function testStripsAgentLogIngestScript(): void
    {
        $html = <<<'HTML'
<script>tryInit();</script>
<script>
// #region agent log
(function () { fetch('http://127.0.0.1:7687/ingest/0f6c73fe-9056-4f77-b37d-ed2a89e5d76d'); })();
// #endregion
</script>
<footer></footer>
HTML;

        $out = DebugIngestStripper::strip($html);

        $this->assertStringContainsString('tryInit();', $out);
        $this->assertStringContainsString('<footer></footer>', $out);
        $this->assertStringNotContainsString('/ingest/', $out);
        $this->assertStringNotContainsString('127.0.0.1', $out);
        $this->assertStringNotContainsString('#region agent log', $out);
    }

    public function testStripsFaviconHeroBeacon(): void
    {
        $html = <<<'HTML'
<script>tryInit();</script>
<script>
(function () { (new Image()).src = '/favicon.ico?awa_hero=1,2,3'; })();
</script>
<footer></footer>
HTML;

        $out = DebugIngestStripper::strip($html);

        $this->assertStringContainsString('tryInit();', $out);
        $this->assertStringContainsString('<footer></footer>', $out);
        $this->assertStringNotContainsString('awa_hero=', $out);
    }

    public function testStripsFaviconHeroBeaconWithLtInScript(): void
    {
        $html = <<<'HTML'
<script>tryInit();</script>
<script>
(function () {
    var fullBleed = !!(slider && Math.abs(sr.x) < 2);
    (new Image()).src = '/favicon.ico?awa_hero=' + [fullBleed ? 1 : 0].join(',');
})();
</script>
<footer></footer>
HTML;

        $out = DebugIngestStripper::strip($html);

        $this->assertStringContainsString('tryInit();', $out);
        $this->assertStringContainsString('<footer></footer>', $out);
        $this->assertStringNotContainsString('awa_hero=', $out);
    }

    public function testStripsIngestFetchWithLtInScript(): void
    {
        $html = <<<'HTML'
<script>tryInit();</script>
<script>
(function () {
    var fullBleed = !!(slider && Math.abs(sr.x) < 2);
    fetch('http://127.0.0.1:7687/ingest/0f6c73fe-9056-4f77-b37d-ed2a89e5d76d');
})();
</script>
<footer></footer>
HTML;

        $out = DebugIngestStripper::strip($html);

        $this->assertStringContainsString('tryInit();', $out);
        $this->assertStringContainsString('<footer></footer>', $out);
        $this->assertStringNotContainsString('/ingest/', $out);
        $this->assertStringNotContainsString('127.0.0.1', $out);
    }

    public function testLeavesCleanHtmlUnchanged(): void
    {
        $html = '<script>require(["awaHeroSlider"], function () {});</script>';
        $this->assertSame($html, DebugIngestStripper::strip($html));
    }
}
