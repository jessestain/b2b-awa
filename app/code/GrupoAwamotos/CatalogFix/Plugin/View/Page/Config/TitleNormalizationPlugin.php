<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\View\Page\Config;

use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;

/**
 * PDP-001: Normaliza títulos de página ALL CAPS (vindos do ERP via meta_title) para Title Case.
 *
 * Intercepta Title::get() para o <title> e Config::setMetaTitle() para <meta name="title">.
 *  - getMetaTitle() é método mágico (__call/getData) — não é interceptável por plugins
 *  - get() é o método usado pelo Renderer para gerar <title>
 *  - setMetaTitle() é público e interceptável, normalizando <meta name="title">
 *
 * Exemplo:
 *   "RETROVISOR TITAN 2000 03 D E  | AWA Motos" → "Retrovisor Titan 2000 03 D E | AWA Motos"
 */
class TitleNormalizationPlugin
{
    private const FORCE_UPPERCASE = [
        'AWA', 'CBX', 'BIZ', 'NXR', 'XRE', 'YBR', 'GSX', 'CRF', 'XTZ', 'PCX',
        'LED', 'ABS', 'EFI', 'POP',
    ];

    private const LOWERCASE_WORDS = [
        'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
        'ao', 'os', 'as', 'um', 'ou', 'com', 'sem', 'por',
    ];

    /**
     * PDP-004: Normaliza o <meta name="title"> que vem do campo meta_title do ERP (ALL CAPS).
     *
     * O valor recebido pode incluir sufixo da loja (ex: "RETROVISOR ... | AWA Motos"),
     * por isso precisamos extrair apenas o título principal antes de normalizar.
     *
     * @param Config $subject
     * @param string|null $title
     * @return array{0: string|null}
     */
    public function beforeSetMetaTitle(Config $subject, ?string $title): array
    {
        if ($title === null || $title === '') {
            return [$title];
        }

        $sepPos = mb_strpos($title, ' | ', 0, 'UTF-8');
        if ($sepPos === false) {
            $title = $this->normalizeIfAllCaps($title);
            return [$title];
        }

        $mainTitle = trim(mb_substr($title, 0, $sepPos, 'UTF-8'));
        $suffix    = mb_substr($title, $sepPos, null, 'UTF-8');
        $normalized = $this->normalizeIfAllCaps($mainTitle);

        return [$this->deDuplicateBrandSuffix($normalized !== $mainTitle ? $normalized . $suffix : $title)];
    }

    /**
     * @param Title $subject
     * @param string $result
     * @return string
     */
    public function afterGet(Title $subject, string $result): string
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        // Detecta separador " | " com eventuais espaços extras ao redor
        $sepPos = mb_strpos($result, ' | ', 0, 'UTF-8');
        if ($sepPos === false) {
            return $this->normalizeIfAllCaps($result);
        }

        $mainTitle   = trim(mb_substr($result, 0, $sepPos, 'UTF-8'));
        $suffix      = mb_substr($result, $sepPos, null, 'UTF-8');

        $normalized = $this->normalizeIfAllCaps($mainTitle);
        if ($normalized !== $mainTitle) {
            return $this->deDuplicateBrandSuffix($normalized . $suffix);
        }

        return $this->deDuplicateBrandSuffix($result);
    }

    /**
     * SEO-012 (04/10/2026): remove sufixo de marca DUPLICADO.
     *
     * O `meta_title` gravado no catalogo ja traz " — AWA Motos | Peças para Motos" e o template
     * da PDP acrescenta " | AWA Motos". Em produtos cujo meta_title veio de importacao, o
     * resultado servido ficava com DOIS sufixos, medido em PDP real:
     *   "Borracha do Estribo Yes (par) — AWA Motos | Peças para Motos | AWA Motos" (72 chars)
     * Google trunca em ~60. Aqui mantemos apenas UM sufixo canonico.
     *
     * So age quando ha duplicata; titulo sem duplicata passa intacto.
     */
    private function deDuplicateBrandSuffix(string $title): string
    {
        if (substr_count($title, '| AWA Motos') < 2) {
            return $title;
        }

        $pos = mb_strpos($title, '| AWA Motos', 0, 'UTF-8');
        $main = rtrim(mb_substr($title, 0, $pos, 'UTF-8'));

        // tira restos de sufixo que sobraram colados ao nome
        $main = (string) preg_replace('/\s*(?:—|-|\|)\s*AWA Motos\s*$/u', '', $main);
        $main = (string) preg_replace('/\s*\|\s*Pe(?:ç|c)as para Motos\s*$/u', '', $main);

        return rtrim($main, " \t—|") . ' | AWA Motos';
    }

    private function normalizeIfAllCaps(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= 3) {
            return $value;
        }
        if ($value !== mb_strtoupper($value, 'UTF-8')) {
            return $value;
        }
        return $this->toTitleCase($value);
    }

    private function toTitleCase(string $name): string
    {
        $result = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

        $lowercaseMap = array_flip(self::LOWERCASE_WORDS);
        $result = (string) preg_replace_callback(
            '/\b([a-zA-ZÀ-ÿ]{1,2})\b/u',
            static function (array $m) use ($lowercaseMap): string {
                $lower = mb_strtolower($m[1], 'UTF-8');
                return isset($lowercaseMap[$lower]) ? $lower : mb_strtoupper($m[1], 'UTF-8');
            },
            $result
        );

        $result = (string) preg_replace_callback(
            '/\b(' . implode('|', array_filter(self::LOWERCASE_WORDS, static fn(string $w) => mb_strlen($w) === 3)) . ')\b/iu',
            static fn(array $m): string => mb_strtolower($m[1], 'UTF-8'),
            $result
        );

        $result = mb_strtoupper(mb_substr($result, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($result, 1, null, 'UTF-8');

        $pattern = '/\b(' . implode('|', array_map(
            static fn(string $w): string => preg_quote(mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'), '/'),
            self::FORCE_UPPERCASE
        )) . ')\b/u';

        $result = (string) preg_replace_callback(
            $pattern,
            static fn(array $m): string => mb_strtoupper($m[1], 'UTF-8'),
            $result
        );

        $result = (string) preg_replace_callback(
            '/\b([a-zA-Z]{1,3})\/([a-zA-Z]{1,3})\b/',
            static fn(array $m): string => mb_strtoupper($m[1] . '/' . $m[2], 'UTF-8'),
            $result
        );

        return $result;
    }
}
