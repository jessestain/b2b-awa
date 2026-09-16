<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Framework\View\Page\Config;

use Magento\Framework\View\Page\Config\Renderer;

/**
 * Remove ou converte em async CSS render-blocking do head.
 *
 * 1. Google Fonts Rubik -> async (herdado do tema pai ayo_default).
 * 2. CSS de interacao (calendar, uppy, chosen, fancybox, quickview, loader, brand)
 *    -> removidos (conteudo consolidado em awa-super-global.css via CSS gate).
 * 3. styles-l.css duplicata -> remove a instancia sem media (tema pai ayo_default)
 *    e mantem so a de min-width:768px (awa-head-preload). Casa .css e .min.css.
 * 4. CSS de polish / FAB (visual-fixes, ai-assistant) -> print/onload para o LCP.
 *
 * O mecanismo <remove> do layout XML nao remove assets declarados com <link src>
 * por modulos de terceiros (Rokanthemes). Este plugin opera diretamente no HTML
 * renderizado, garantindo a remocao independente do tipo de declaracao.
 */
class HeadAssetRendererPlugin
{
    private const RUBIK_URL_PATTERN = 'fonts.googleapis.com/css2?family=Rubik';

    /**
     * Sufixos de arquivo a remover completamente do head (conteudo em awa-super-global.css).
     * Apenas CSS declarados como bloqueantes (media="all") sao removidos.
     * Versoes com media="print" ou data-awa-gate sao mantidas (async/gated).
     *
     * @var string[]
     */
    private const BLOCKING_CSS_TO_REMOVE = [
        'mage/calendar.css',
        'jquery/uppy/dist/uppy-custom.css',
        'Rokanthemes_QuickView/css/rokan_quickview.css',
        'Rokanthemes_RokanBase/css/chosen.css',
        'Rokanthemes_RokanBase/css/jquery.fancybox.css',
        'Rokanthemes_Themeoption/css/loader.css',
        'Rokanthemes_Brand/css/styles.css',
        /* styles-l.css / .min.css: nao listar aqui. removeBlockingCss apagaria tambem
           a copia correta com media="screen and (min-width: 768px)". Ver removeStylesLDuplicate(). */
    ];

    /**
     * CSS abaixo da dobra ou de polish tardio — media=print + onload (não bloqueia LCP).
     * design-system permanece bloqueante (SSOT / tokens de 1º paint).
     *
     * @var string[]
     */
    private const BLOCKING_CSS_TO_ASYNC = [
        'GrupoAwamotos_AiAssistant/css/ai-assistant',
        'css/awa-visual-fixes-2026-06-29-final',
    ];

    /**
     * Remove CSS render-blocking redundantes e converte Rubik para async.
     *
     * @param Renderer $subject
     * @param string   $result
     * @return string
     */
    public function afterRenderHeadAssets(Renderer $subject, string $result): string
    {
        $result = $this->removeBlockingCss($result);
        $result = $this->removeStylesLDuplicate($result);
        $result = $this->convertRubikToAsync($result);
        $result = $this->convertBlockingCssToAsync($result);
        return $result;
    }

    /**
     * Remove CSS blocking cujo conteudo ja esta no awa-super-global.css (CSS gate).
     * So remove tags com media="all" (ou sem media) -- mantém async (media="print").
     *
     * @param string $html
     * @return string
     */
    private function removeBlockingCss(string $html): string
    {
        foreach (self::BLOCKING_CSS_TO_REMOVE as $cssPath) {
            $pattern = '/<link\s[^>]*href=["\'][^"\']*'
                . preg_quote($cssPath, '/')
                . '[^"\']*["\'][^>]*>/i';

            $html = preg_replace_callback($pattern, static function (array $m): string {
                $tag = $m[0];
                if (strpos($tag, 'media="print"') !== false
                    || strpos($tag, "media='print'") !== false
                    || strpos($tag, 'data-awa-gate') !== false
                    || strpos($tag, 'onload') !== false
                ) {
                    return $tag;
                }
                return '';
            }, $html) ?? $html;
        }

        return $html;
    }

    /**
     * Remove styles-l ativo sem min-width:768px (pai ayo_default: media=all).
     * Nao toca fallbacks dentro de <noscript>. Casa .css e .min.css.
     *
     * @param string $html
     * @return string
     */
    private function removeStylesLDuplicate(string $html): string
    {
        $htmlWithoutNoscript = preg_replace('/<noscript>.*?<\/noscript>/is', '', $html) ?? $html;
        $pattern = '/<link\s[^>]*href=["\'][^"\']*\/css\/styles-l(?:\.min)?\.css[^"\']*["\'][^>]*>/i';
        $count = preg_match_all($pattern, $htmlWithoutNoscript, $matches);
        if ($count === false || $count === 0) {
            return $html;
        }

        $keptDesktopStylesheet = false;
        $keptDesktopPreload = false;

        foreach (array_reverse($matches[0]) as $tag) {
            if (str_contains($tag, 'media="print"')
                || str_contains($tag, "media='print'")
                || str_contains($tag, 'onload')
                || str_contains($tag, 'data-awa-gate')
            ) {
                continue;
            }

            $isDesktopMedia = (bool) preg_match('/min-width:\s*768px/i', $tag);
            $isPreload = (bool) preg_match('/\brel=["\']preload["\']/i', $tag);

            if ($isDesktopMedia && $isPreload && !$keptDesktopPreload) {
                $keptDesktopPreload = true;
                continue;
            }

            if ($isDesktopMedia && !$isPreload && !$keptDesktopStylesheet) {
                $keptDesktopStylesheet = true;
                continue;
            }

            $html = $this->replaceStylesLTagOutsideNoscript($html, $tag);
        }

        return $html;
    }

    /**
     * str_replace global apagaria o fallback identico dentro de <noscript>.
     */
    private function replaceStylesLTagOutsideNoscript(string $html, string $tag): string
    {
        $parts = preg_split('/(<noscript>.*?<\/noscript>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        foreach ($parts as $i => $part) {
            if (str_starts_with(strtolower($part), '<noscript')) {
                continue;
            }
            $parts[$i] = str_replace($tag, '', $part);
        }

        return implode('', $parts);
    }

    /**
     * Converte o link do Google Fonts Rubik de bloqueante para assincrono.
     *
     * @param string $html
     * @return string
     */
    private function convertRubikToAsync(string $html): string
    {
        if (strpos($html, self::RUBIK_URL_PATTERN) === false) {
            return $html;
        }

        $html = preg_replace_callback(
            '/<link\s[^>]*' . preg_quote(self::RUBIK_URL_PATTERN, '/') . '[^>]*>/i',
            static function (array $matches): string {
                $original = $matches[0];

                if (strpos($original, 'media="print"') !== false
                    || strpos($original, "media='print'") !== false
                ) {
                    return $original;
                }

                if (!preg_match('/href=["\']([^"\']+)["\']/', $original, $hrefMatch)) {
                    return $original;
                }

                $href = htmlspecialchars($hrefMatch[1], ENT_QUOTES, 'UTF-8');

                return '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin/>'
                    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>'
                    . '<link rel="stylesheet" type="text/css"'
                    . ' media="print" onload="this.media=\'all\'"'
                    . ' href="' . $href . '"/>'
                    . '<noscript><link rel="stylesheet" type="text/css"'
                    . ' href="' . $href . '"/></noscript>';
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Converte CSS render-blocking listado em print/onload + noscript.
     *
     * @param string $html
     * @return string
     */
    private function convertBlockingCssToAsync(string $html): string
    {
        foreach (self::BLOCKING_CSS_TO_ASYNC as $fragment) {
            if (!str_contains($html, $fragment)) {
                continue;
            }

            $pattern = '/<link\s[^>]*href=["\'][^"\']*'
                . preg_quote($fragment, '/')
                . '[^"\']*["\'][^>]*>/i';

            $html = preg_replace_callback($pattern, static function (array $matches): string {
                $original = $matches[0];

                if (str_contains($original, 'media="print"')
                    || str_contains($original, "media='print'")
                    || str_contains($original, 'onload')
                ) {
                    return $original;
                }

                if (!preg_match('/href=["\']([^"\']+)["\']/', $original, $hrefMatch)) {
                    return $original;
                }

                $href = htmlspecialchars($hrefMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<link rel="stylesheet" type="text/css"'
                    . ' media="print" onload="this.media=\'all\'"'
                    . ' href="' . $href . '" data-awa-async="1"/>'
                    . '<noscript><link rel="stylesheet" type="text/css" media="all" href="'
                    . $href . '"/></noscript>';
            }, $html) ?? $html;
        }

        return $html;
    }
}
