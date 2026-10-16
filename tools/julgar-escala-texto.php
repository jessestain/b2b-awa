<?php
/**
 * AWA Motos — o Jev julga a escala de cores de TEXTO do tema.
 *
 * O PROBLEMA
 * Medido em 06/10/2026, o tema usa 5 tons de texto que nao foram reconciliados:
 *
 *   #1a1a1a  --awa-ink redefinido em awa-impeccable-layout-2026-06-16 (vence o design system)
 *   #333333  --awa-ink no design system
 *   #3f454d  --awa-text ("corpo de texto, um tom acima do ink")
 *   #475569  SEM TOKEN, usado 51x como `color` + 14x background  <- slate-600 do Tailwind
 *   #6b7280  --awa-ink-soft
 *
 * Nao e um bug de sintaxe: e uma escala que cresceu por acumulo. Cada tom tem uso
 * real em tela, entao escolher qual vira o canonico e uma decisao de DESIGN — e o
 * Jev e bom exatamente nisso: julgar papeis, nao calcular.
 *
 * DIVISAO DO TRABALHO
 *   CODIGO  mede cada tom (uso, propriedade, contraste WCAG sobre o fundo real) e
 *           monta as perguntas.
 *   JEV     para cada tom, decide se ele tem PAPEL PROPRIO ou se e ruido de escala.
 *
 * Uso: php tools/julgar-escala-texto.php
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';

$chave = getenv('TYPESAFE_API_KEY');
if (!$chave) { fwrite(STDERR, "TYPESAFE_API_KEY nao definida.\n"); exit(2); }

// ── CODIGO: mede cada tom ────────────────────────────────────────────────────
$tons = [
    '#1a1a1a' => ['token' => '--awa-ink (redefinido em runtime)', 'rgb' => [26, 26, 26]],
    '#333333' => ['token' => '--awa-ink (valor do design system)', 'rgb' => [51, 51, 51]],
    '#3f454d' => ['token' => '--awa-text', 'rgb' => [63, 69, 77]],
    '#475569' => ['token' => '(SEM TOKEN)', 'rgb' => [71, 85, 105]],
    '#6b7280' => ['token' => '--awa-ink-soft', 'rgb' => [107, 114, 128]],
];

/** Contraste WCAG de dois RGB (0-255). */
$lum = function (array $c): float {
    $f = fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
};
$contraste = function (array $a, array $b) use ($lum): float {
    $l1 = $lum($a); $l2 = $lum($b);
    return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
};

$branco = [255, 255, 255];
$cinzaClaro = [247, 247, 248];  // --awa-surface-alt

$medido = [];
foreach ($tons as $hex => $info) {
    $usosColor = 0; $usosBg = 0; $usosOutros = 0; $arquivos = 0;
    foreach (glob($cssDir . '/awa-*.css') ?: [] as $f) {
        $base = basename($f);
        if (str_contains($base, '.bak')) { continue; }
        $s = @file_get_contents($f);
        if ($s === false || stripos($s, $hex) === false) { continue; }
        $arquivos++;
        // conta a propriedade imediatamente antes de cada ocorrencia
        preg_match_all('/([a-z-]+)\s*:\s*[^;{}]*' . preg_quote($hex, '/') . '/i', $s, $m);
        foreach ($m[1] as $prop) {
            $p = strtolower($prop);
            if ($p === 'color' || str_ends_with($p, '-color')) { $usosColor++; }
            elseif (str_contains($p, 'background')) { $usosBg++; }
            else { $usosOutros++; }
        }
    }
    $medido[$hex] = $info + [
        'usos_color' => $usosColor,
        'usos_bg' => $usosBg,
        'usos_outros' => $usosOutros,
        'arquivos' => $arquivos,
        'contraste_branco' => $contraste($info['rgb'], $branco),
        'contraste_cinza' => $contraste($info['rgb'], $cinzaClaro),
    ];
}

echo "================ ESCALA DE TEXTO MEDIDA ================\n";
printf("  %-10s %-34s %6s %6s %6s %8s %8s\n", 'hex', 'token', 'color', 'bg', 'outros', 'c/branco', 'c/#f7f7f8');
foreach ($medido as $hex => $m) {
    printf("  %-10s %-34s %6d %6d %6d %8s %8s\n", $hex, mb_substr($m['token'], 0, 33),
        $m['usos_color'], $m['usos_bg'], $m['usos_outros'], $m['contraste_branco'], $m['contraste_cinza']);
}

// ── JEV: julga o papel de cada tom ───────────────────────────────────────────
$estado = ['contexto' => 'Tema de e-commerce B2B de pecas de moto (Magento 2, pt-BR). '
    . 'Fundo predominante branco #ffffff; fundo alternativo #f7f7f8. '
    . 'A escala de texto do design system tem --awa-ink (#333333) para texto principal e '
    . '--awa-ink-soft (#6b7280) para secundario, alem de --awa-text (#3f454d).'];
$perguntas = [];
foreach ($medido as $hex => $m) {
    $estado["tom_$hex"] = [
        'cor' => $hex,
        'token_atual' => $m['token'],
        'usos_como_cor_de_texto' => $m['usos_color'],
        'usos_como_fundo' => $m['usos_bg'],
        'usos_outros' => $m['usos_outros'],
        'arquivos_onde_aparece' => $m['arquivos'],
        'contraste_sobre_branco' => $m['contraste_branco'],
        'contraste_sobre_cinza_claro' => $m['contraste_cinza'],
    ];
    $perguntas["papel_$hex"] = [
        'type' => 'choice',
        'instructions' => 'No contexto descrito, a cor ' . $hex . ' (' . $m['token'] . ', usada '
            . $m['usos_color'] . 'x como cor de texto, ' . $m['usos_bg'] . 'x como fundo, contraste '
            . $m['contraste_branco'] . ':1 sobre branco) tem PAPEL PROPRIO na escala tipografica, '
            . 'ou e ruido de escala que deveria ser unificado a um tom existente?',
        'criteria' => [
            'papel_proprio_texto'   => 'Tem papel proprio como cor de TEXTO e deve virar um token da escala',
            'papel_proprio_fundo'   => 'Tem papel proprio como FUNDO/superficie, nao como texto',
            'unificar_com_ink'      => 'E ruido: deveria ser unificado ao --awa-ink (texto principal)',
            'unificar_com_soft'     => 'E ruido: deveria ser unificado ao --awa-ink-soft (texto secundario)',
            'unificar_com_text'     => 'E ruido: deveria ser unificado ao --awa-text (corpo de texto)',
            'precisa_olhar'         => 'Nao da para decidir so com os numeros; precisa ver em tela',
        ],
    ];
}

$ch = curl_init('https://api.typesafe.ai/v1/systemone');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['state' => $estado, 'model' => 'jev-latest', 'questions' => $perguntas], JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200) { fwrite(STDERR, "HTTP $http: " . substr((string)$resp, 0, 300) . "\n"); exit(1); }
$ans = json_decode((string)$resp, true)['answers'] ?? [];

echo "\n================ VEREDITO DO JEV ================\n";
foreach ($medido as $hex => $m) {
    $v = $ans["papel_$hex"] ?? [];
    printf("  %-10s %-26s conf=%.2f  %s\n", $hex, $v['choice'] ?? '?', $v['confidence'] ?? 0,
        mb_substr($m['token'], 0, 34));
}

// ── CODIGO: agrupa a acao ────────────────────────────────────────────────────
$porAcao = [];
foreach ($medido as $hex => $m) {
    $porAcao[$ans["papel_$hex"]['choice'] ?? '?'][] = $hex;
}
echo "\n  --- agrupado ---\n";
foreach ($porAcao as $acao => $hexes) {
    printf("    %-24s %s\n", $acao, implode(' ', $hexes));
}
