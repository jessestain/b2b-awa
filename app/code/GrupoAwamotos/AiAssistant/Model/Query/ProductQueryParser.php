<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Query;

/**
 * Detects storefront product/fitment intent without calling the LLM.
 *
 * Pure PHP — no Magento dependencies — so it can be unit-tested in isolation.
 */
class ProductQueryParser
{
    private const MODEL_BRAND = [
        'biz' => 'Honda',
        'bis' => 'Honda',
        'biss' => 'Honda',
        'cg' => 'Honda',
        'titan' => 'Honda',
        'bros' => 'Honda',
        'bross' => 'Honda',
        'fan' => 'Honda',
        'pop' => 'Honda',
        'xre' => 'Honda',
        'cb' => 'Honda',
        'pcx' => 'Honda',
        'twister' => 'Honda',
        'fazer' => 'Yamaha',
        'factor' => 'Yamaha',
        'ybr' => 'Yamaha',
        'lander' => 'Yamaha',
        'crosser' => 'Yamaha',
        'ninja' => 'Kawasaki',
    ];

    private const BRANDS = ['honda', 'yamaha', 'suzuki', 'kawasaki', 'hero'];

    private const DISPLACEMENTS = [
        '100', '110', '125', '150', '160', '250', '300', '400', '600', '650', '750', '1000',
    ];

    private const PART_KEYWORDS = [
        'retrovisor', 'retrovisores', 'pastilha', 'pastilhas', 'manete', 'manetes',
        'baú', 'bau', 'bauleto', 'corrente', 'amortecedor', 'lona', 'filtro', 'vela',
        'disco', 'cabo', 'guidão', 'guidao', 'pedaleira', 'cavalete', 'carcaça', 'carcaca',
        'lente', 'pisca', 'manopla', 'estribo', 'protetor', 'bagageiro', 'relacao', 'relação',
        'pinhão', 'pinhao', 'coroa', 'kit', 'farol', 'lanterna', 'freio', 'embreagem',
        'óleo', 'oleo', 'pneu', 'câmara', 'camara', 'suporte', 'barra',
    ];

    private const STOP_WORDS = [
        'de', 'da', 'do', 'das', 'dos', 'para', 'com', 'uma', 'um', 'o', 'a', 'e', 'ou',
        'the', 'and', 'or', 'tem', 'quero', 'precisa', 'preciso', 'busca', 'buscar',
        'produto', 'peça', 'peca', 'peças', 'pecas', 'me', 'por', 'favor', 'pra',
        'encontrar', 'ache', 'acha', 'serve', 'servem', 'na', 'no', 'em',
        'que', 'qual', 'quais', 'essa', 'esse', 'este', 'isso', 'como', 'onde',
        'alguma', 'algum', 'tem',
    ];

    /**
     * @return array{
     *     is_product_query: bool,
     *     prefers_fitment: bool,
     *     part: string,
     *     brand: string,
     *     model: string,
     *     year: string,
     *     search_query: string
     * }
     */
    public function parse(string $message): array
    {
        $empty = [
            'is_product_query' => false,
            'prefers_fitment' => false,
            'part' => '',
            'brand' => '',
            'model' => '',
            'year' => '',
            'search_query' => '',
        ];

        $raw = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if ($raw === '') {
            return $empty;
        }

        $lower = mb_strtolower($raw);

        if ($this->isNonProductIntent($lower)) {
            return $empty;
        }

        $year = $this->extractYear($lower);
        $modelToken = $this->extractModelToken($lower);
        $model = '';
        $brand = $this->extractExplicitBrand($lower);
        $displacement = '';

        if ($modelToken !== '') {
            $displacement = $this->extractDisplacementAfter($lower, $modelToken);
            $model = $this->formatModel($modelToken, $displacement);
            if ($brand === '') {
                $brand = self::MODEL_BRAND[$modelToken] ?? '';
            }
        }

        $part = $this->extractPart($lower, $modelToken, $displacement, $year, $brand);
        $viscosity = '';
        if (preg_match('/\b(\d{1,2}w\d{2})\b/u', $lower, $visco) === 1) {
            $viscosity = $visco[1];
            if (!str_contains($part, $viscosity)) {
                $part = trim($part . ' ' . $viscosity);
            }
        }
        $oilCycle = '';
        if (preg_match('/\b(óleo|oleo)\b/u', $part) === 1
            && preg_match('/\b([24]t)\b/u', $lower, $cycle) === 1
        ) {
            $oilCycle = $cycle[1];
            if (!str_contains($part, $oilCycle)) {
                $part = trim($part . ' ' . $oilCycle);
            }
        }

        $isGenericOil = preg_match('/^(óleo|oleo)$/u', $part) === 1
            && $model === ''
            && $viscosity === ''
            && $oilCycle === '';
        $isProduct = !$isGenericOil && ($part !== '' || $model !== '');

        $searchParts = array_filter([$part, $brand, $model, $year], static fn(string $v): bool => $v !== '');
        $searchQuery = trim(implode(' ', $searchParts));
        if ($searchQuery === '') {
            $searchQuery = $raw;
        }

        return [
            'is_product_query' => $isProduct,
            'prefers_fitment' => $model !== '',
            'part' => $part,
            'brand' => $brand,
            'model' => $model,
            'year' => $year,
            'search_query' => $searchQuery,
        ];
    }

    public function isProductQuery(string $message): bool
    {
        return $this->parse($message)['is_product_query'];
    }

    private function isNonProductIntent(string $lower): bool
    {
        if (preg_match('/\b(rastrear|rastreio|meu pedido|meus pedidos|nota fiscal|carrinho|cotações|cotacoes)\b/u', $lower) === 1) {
            return true;
        }

        if (mb_strlen($lower) <= 24 && preg_match(
            '/^(oi|olá|ola|bom dia|boa tarde|boa noite|obrigad[oa]|valeu|ok|obrigado)[\s!.]*$/u',
            $lower
        ) === 1) {
            return true;
        }

        return false;
    }

    private function extractYear(string $lower): string
    {
        if (preg_match('/\b((?:19|20)\d{2})\b/u', $lower, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private function extractModelToken(string $lower): string
    {
        $tokens = array_keys(self::MODEL_BRAND);
        usort($tokens, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($tokens as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $lower) === 1) {
                return $token;
            }
        }

        return '';
    }

    private function extractDisplacementAfter(string $lower, string $modelToken): string
    {
        $pattern = '/\b' . preg_quote($modelToken, '/') . '\b\s*(\d{2,4})\b/u';
        if (preg_match($pattern, $lower, $match) === 1 && in_array($match[1], self::DISPLACEMENTS, true)) {
            return $match[1];
        }

        foreach (self::DISPLACEMENTS as $cc) {
            if (preg_match('/\b' . preg_quote($cc, '/') . '\b/u', $lower) === 1) {
                return $cc;
            }
        }

        return '';
    }

    private function extractExplicitBrand(string $lower): string
    {
        foreach (self::BRANDS as $brand) {
            if (preg_match('/\b' . preg_quote($brand, '/') . '\b/u', $lower) === 1) {
                return ucfirst($brand);
            }
        }

        return '';
    }

    private function formatModel(string $token, string $displacement): string
    {
        $label = in_array($token, ['cg', 'cb', 'xre', 'pcx', 'ybr'], true)
            ? mb_strtoupper($token)
            : ucfirst($token);

        return trim($label . ($displacement !== '' ? ' ' . $displacement : ''));
    }

    private function extractPart(
        string $lower,
        string $modelToken,
        string $displacement,
        string $year,
        string $brand
    ): string {
        $found = [];
        foreach (self::PART_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $lower) === 1) {
                $found[] = $keyword;
            }
        }
        if ($found !== []) {
            return implode(' ', array_unique($found));
        }

        $skip = array_merge(
            self::STOP_WORDS,
            self::BRANDS,
            array_keys(self::MODEL_BRAND),
            self::DISPLACEMENTS,
            $modelToken !== '' ? [$modelToken] : [],
            $displacement !== '' ? [$displacement] : [],
            $year !== '' ? [$year] : [],
            $brand !== '' ? [mb_strtolower($brand)] : []
        );

        $tokens = preg_split('/[\s,;\/|+]+/u', $lower) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4) {
                continue;
            }
            if (in_array($token, $skip, true)) {
                continue;
            }
            $kept[] = $token;
        }

        return implode(' ', array_slice($kept, 0, 4));
    }
}
