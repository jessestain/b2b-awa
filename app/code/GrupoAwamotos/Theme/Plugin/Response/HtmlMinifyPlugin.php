<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use Magento\Framework\HTTP\PhpEnvironment\Response as ResponseHttp;
use Magento\Framework\App\State;

/**
 * Minifies HTML response body by removing unnecessary whitespace and comments.
 * Preserves Knockout.js comments, <pre>, <script>, <style>, <textarea>, <svg> content.
 * Uses block-element-only whitespace collapse to avoid breaking inline elements.
 */
class HtmlMinifyPlugin
{
    private const BLOCK_ELEMENTS = 'div|section|article|header|footer|main|nav|aside'
        . '|ul|ol|li|p|h[1-6]|table|tr|td|th|thead|tbody|tfoot'
        . '|form|fieldset|dl|dt|dd|figure|figcaption|blockquote'
        . '|hr|br|head|body|html|link|meta|option';

    public function __construct(
        private readonly State $appState,
    ) {
    }

    /**
     * Minify HTML body before sending response.
     */
    public function beforeSendResponse(ResponseHttp $subject): void
    {
        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = $subject->getBody();
        if (empty($html) || strlen($html) < 500) {
            return;
        }

        $subject->setBody($this->minify($html));
    }

    private function minify(string $html): string
    {
        $preserved = [];
        $index = 0;

        // 1. Preserve <pre>, <script>, <style>, <textarea>, <code>, <svg> blocks
        $html = (string) preg_replace_callback(
            '#<(pre|script|style|textarea|code|svg)\b[^>]*>.*?</\1>#is',
            function (array $matches) use (&$preserved, &$index): string {
                $placeholder = '%%PRESERVE_' . $index . '%%';
                $preserved[$placeholder] = $matches[0];
                $index++;
                return $placeholder;
            },
            $html
        );

        // 2. Preserve Knockout.js comments (<!-- ko ... --> and <!-- /ko -->)
        $html = (string) preg_replace_callback(
            '#<!--\s*/?ko\b.*?-->#s',
            function (array $matches) use (&$preserved, &$index): string {
                $placeholder = '%%PRESERVE_' . $index . '%%';
                $preserved[$placeholder] = $matches[0];
                $index++;
                return $placeholder;
            },
            $html
        );

        // 3. Preserve conditional comments (<!--[if ...)
        $html = (string) preg_replace_callback(
            '#<!--\[if\b.*?\]>.*?<!\[endif\]-->#is',
            function (array $matches) use (&$preserved, &$index): string {
                $placeholder = '%%PRESERVE_' . $index . '%%';
                $preserved[$placeholder] = $matches[0];
                $index++;
                return $placeholder;
            },
            $html
        );

        // Remove remaining HTML comments
        $html = (string) preg_replace('#<!--.*?-->#s', '', $html);

        // Collapse whitespace ONLY around block-level elements (safe for inline)
        $blocks = self::BLOCK_ELEMENTS;
        // After block closing tag: </div>  \n  < → </div><
        $html = (string) preg_replace(
            '#(</(?:' . $blocks . ')>)\s+#is',
            '$1',
            $html
        );
        // Before block opening/closing tag:  \n  <div → <div
        $html = (string) preg_replace(
            '#\s+(</?' . '(?:' . $blocks . ')[\s>/])#is',
            '$1',
            $html
        );

        // Remove leading whitespace on lines
        $html = (string) preg_replace('#^\s+#m', '', $html);

        // Remove empty lines
        $html = (string) preg_replace("#\n{2,}#", "\n", $html);

        // Restore preserved blocks
        foreach ($preserved as $placeholder => $content) {
            $html = str_replace($placeholder, $content, $html);
        }

        return $html;
    }
}
