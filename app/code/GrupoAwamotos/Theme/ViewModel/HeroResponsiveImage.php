<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * URLs srcset/sizes para o hero slider (LCP homepage).
 */
class HeroResponsiveImage implements ArgumentInterface
{
    /** @var int[] */
    private const WIDTHS = [480, 768, 1200, 1920];

    private StoreManagerInterface $storeManager;
    private Filesystem $filesystem;

    public function __construct(
        StoreManagerInterface $storeManager,
        Filesystem $filesystem
    ) {
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
    }

    /**
     * @return int[]
     */
    public function getWidths(): array
    {
        return self::WIDTHS;
    }

    public function getVariantRelativePath(string $slideImagePath, int $width): string
    {
        $normalized = ltrim($slideImagePath, '/');

        return $normalized . '-' . $width . 'w.webp';
    }

    public function getFullWebpRelativePath(string $slideImagePath): string
    {
        return ltrim($slideImagePath, '/') . '.webp';
    }

    public function getOriginalUrl(string $slideImagePath): string
    {
        return $this->getMediaUrl(ltrim($slideImagePath, '/'));
    }

    public function getMediaUrl(string $relativePath): string
    {
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(
            \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
        );

        return rtrim($mediaUrl, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * @return array<int, string> width => absolute URL (variant or fallback full webp)
     */
    public function resolveVariantUrls(string $slideImagePath): array
    {
        if ($slideImagePath === '') {
            return [];
        }

        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $fullWebp = $this->getFullWebpRelativePath($slideImagePath);
        $hasFullWebp = $mediaDir->isExist($fullWebp);
        $urls = [];

        foreach (self::WIDTHS as $width) {
            $variant = $this->getVariantRelativePath($slideImagePath, $width);
            if ($mediaDir->isExist($variant)) {
                $urls[$width] = $this->getMediaUrl($variant);
                continue;
            }

            if ($hasFullWebp) {
                $urls[$width] = $this->getMediaUrl($fullWebp);
            }
        }

        return $urls;
    }

    public function buildSrcset(string $slideImagePath): string
    {
        $parts = [];
        foreach ($this->resolveVariantUrls($slideImagePath) as $width => $url) {
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }

    public function getPreloadUrl(string $slideImagePath, bool $mobile): string
    {
        $urls = $this->resolveVariantUrls($slideImagePath);
        if ($urls === []) {
            return $this->getOriginalUrl($slideImagePath);
        }

        // r44: desktop tipico (~1350px) usa 1200w (23KB) em vez de 1920w (49KB).
        return $mobile
            ? ($urls[768] ?? $urls[480] ?? reset($urls))
            : ($urls[1200] ?? $urls[1920] ?? end($urls));
    }

    public function getSizesAttribute(bool $mobileSlider): string
    {
        // r44: tipico desktop PSI (~1350) → 1200px; sem 100vw (evita 1340→1920).
        // PERF/LCP: o sizes do desktop era '(min-width: 1600px) 1920px, 1200px' — sem clausula
        // para mobile. Medido: em viewport de 390px o browser resolvia 1200px e baixava o
        // -1200w.webp, quando existem 480w/768w. Ambos agora sao AUTO-SUFICIENTES (cobrem
        // mobile), entao mesmo se o <img> do mobile receber o sizes de desktop ele acerta 100vw.
        return $mobileSlider
            ? '(max-width: 1599px) 100vw, 1200px'
            : '(max-width: 767px) 100vw, (max-width: 1599px) 1200px, 1920px';
    }

    /**
     * Monta <picture> responsivo para o primeiro slide (LCP).
     */
    public function buildLcpPictureHtml(
        string $slideImagePath,
        string $alt,
        bool $isLcpCandidate,
        bool $mobileSlider
    ): string {
        if ($slideImagePath === '') {
            return '';
        }

        $urls = $this->resolveVariantUrls($slideImagePath);
        $sizes = $this->getSizesAttribute($mobileSlider);
        $defaultSrc = $urls === []
            ? $this->getOriginalUrl($slideImagePath)
            : ($mobileSlider
                ? ($urls[768] ?? $urls[480] ?? reset($urls))
                : ($urls[1200] ?? $urls[1920] ?? end($urls)));

        // r44: só o candidato LCP é eager; demais slides lazy (evita concorrência ~100KB).
        $loading = $isLcpCandidate ? 'eager' : 'lazy';
        // LCP: fetchpriority=high só no candidato (1 img). Preload no head reforça com media query.
        $priority = $isLcpCandidate ? ' fetchpriority="high"' : '';
        $width = $mobileSlider ? 768 : 1200;
        $height = $mobileSlider ? 400 : 294;
        // r44c: sync no LCP reduz render-delay (img pronta ~400ms mas LCP ~2.8s em lab).
        // Demais slides: async para não competir na main thread.
        $decoding = $isLcpCandidate ? 'sync' : 'async';

        $altEsc = htmlspecialchars(strip_tags($alt), ENT_QUOTES, 'UTF-8');

        
        $sources = '';
        if ($urls !== []) {
            if ($mobileSlider) {
                // Mobile: só até 768w (evita eager hidden-xs baixar 1920 no desktop).
                $mobParts = [];
                foreach ([480, 768] as $w) {
                    if (isset($urls[$w])) {
                        $mobParts[] = $urls[$w] . ' ' . $w . 'w';
                    }
                }
                if ($mobParts === [] && isset($urls[1200])) {
                    $mobParts[] = $urls[1200] . ' 1200w';
                }
                if ($mobParts !== []) {
                    $sources .= '<source type="image/webp" srcset="'
                        . htmlspecialchars(implode(', ', $mobParts), ENT_QUOTES, 'UTF-8')
                        . '" sizes="' . $sizes . '">';
                }
            } else {
                // Desktop: 1920 só em viewports largos; tipico PSI (~1350) fica no 1200w.
                if (isset($urls[1920])) {
                    $sources .= '<source type="image/webp" media="(min-width: 1600px)" srcset="'
                        . htmlspecialchars($urls[1920] . ' 1920w', ENT_QUOTES, 'UTF-8')
                        . '" sizes="1920px">';
                }
                $deskParts = [];
                foreach ([480, 768, 1200] as $w) {
                    if (isset($urls[$w])) {
                        $deskParts[] = $urls[$w] . ' ' . $w . 'w';
                    }
                }
                if ($deskParts !== []) {
                    $sources .= '<source type="image/webp" srcset="'
                        . htmlspecialchars(implode(', ', $deskParts), ENT_QUOTES, 'UTF-8')
                        . '" sizes="' . $sizes . '">';
                }
            }
        }

        return '<picture>'
            . $sources
            . '<img src="' . htmlspecialchars($defaultSrc, ENT_QUOTES, 'UTF-8') . '"'
            . ' alt="' . $altEsc . '"'
            . ' loading="' . $loading . '"'
            . ' decoding="' . $decoding . '"'
            . ' width="' . $width . '" height="' . $height . '"'
            . ' sizes="' . $sizes . '"'
            . $priority
            . ' />'
            . '</picture>';
    }
}
