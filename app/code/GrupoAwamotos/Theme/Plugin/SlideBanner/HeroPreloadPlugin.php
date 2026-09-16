<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\SlideBanner;

use GrupoAwamotos\Theme\Model\Html\DebugIngestStripper;
use Rokanthemes\SlideBanner\Block\Slider;

/**
 * Plugin no bloco Slider do Rokanthemes SlideBanner.
 *
 * Preloads do hero são gerenciados por awa-hero-preload.phtml / awa-head-preload.phtml
 * via GrupoAwamotos\Theme\Block\Html\HeroPreload (srcset responsivo P9).
 */
class HeroPreloadPlugin
{
    /**
     * Preloads são gerenciados por awa-head-preload.phtml / awa-hero-preload.phtml.
     */
    public function beforeToHtml(Slider $subject): ?array
    {
        return null;
    }

    public function afterToHtml(Slider $subject, string $result): string
    {
        return DebugIngestStripper::strip($result);
    }
}
