<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model\Html;

/**
 * Remove Cursor debug ingest from Magento HTML.
 *
 * Other sessions keep re-injecting fetch('http://127.0.0.1:.../ingest/...') into
 * storefront PHTML. Magento CSP connect-src does not allow localhost, so those
 * scripts become console CSP errors on every home paint.
 */
final class DebugIngestStripper
{
    /**
     * @param string $html Magento HTML fragment or full page
     */
    public static function strip(string $html): string
    {
        if ($html === '' || (
            !str_contains($html, '/ingest/')
            && !str_contains($html, '#region agent log')
            && !str_contains($html, 'awa_hero=')
        )) {
            return $html;
        }

        $out = preg_replace(
            '~<script\b[^>]*>\s*//\s*#region agent log(?:(?!</script>).)*//\s*#endregion\s*</script>~s',
            '',
            $html
        );
        if (!is_string($out)) {
            $out = $html;
        }

        $out2 = preg_replace(
            '~<script\b[^>]*>(?:(?!</script>).)*https?://127\.0\.0\.1:\d+/ingest/(?:(?!</script>).)*</script>~s',
            '',
            $out
        );
        if (is_string($out2)) {
            $out = $out2;
        }

        $out3 = preg_replace(
            '~<script\b[^>]*>(?:(?!</script>).)*favicon\.ico\?awa_hero=(?:(?!</script>).)*</script>~s',
            '',
            $out
        );
        if (is_string($out3)) {
            $out = $out3;
        }

        if ($out === $html) {
            return $html;
        }

        return $out;
    }
}
