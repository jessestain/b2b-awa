<?php
/**
 * AWA Motos — dar nome canonico ao #475569 (slate-600) e migrar os usos.
 *
 * O PROBLEMA (medido em 06/10/2026)
 * O #475569 e a cor de texto secundario de menus e blocos B2B:
 *   .awa-main-nav .dropdown-menu li a · .awa-vertical-menu .dropdown-menu li a
 *   .b2b-login-to-see-price · .info-price   (junto com bg #f8fafc e borda #e2e8f0)
 * Aparece 163x como `color` e 30x como fundo, em 29 arquivos, SEM token proprio.
 *
 * E ja tinha sido "tokenizada" com QUATRO nomes diferentes, em arquivos diferentes:
 *   _awa-color-system.less ............ --awa-neutral-600: #475569   (LESS morto)
 *   _awa-consolidated.less ............ --awa-cons-c22: #475569      (LESS morto)
 *   awa-super-global-20260611m.css .... --awa-sg-c16: #475569
 *   awa-components-b2b-foundation.css . --awa-text-2: #475569
 *   HeaderImpeccableCascadeLockCss.php  --awa-border-subtle:#475569  (uso: borda)
 * Ou seja: o mesmo valor com cinco nomes, e nenhum deles canonico. E a prova
 * concreta da explosao de 1.556 tokens --awa-* do tema.
 *
 * POR QUE O FALLBACK E OBRIGATORIO
 * O design system NAO carrega em /b2b/account/login/ (medido: design-system=0 no HTML).
 * Sem fallback, `var(--awa-text-soft)` resolveria para nada e a cor herdaria —
 * mudando a aparencia exatamente na tela de login B2B. Com fallback:
 *   onde o token existe  -> usa o token (mesmo valor, e passa a propagar mudanca)
 *   onde o token nao existe -> usa #475569 (mesmo valor)
 * Em nenhum caso o pixel muda.
 *
 * Uso:
 *   php tools/migrar-text-soft.php --check
 *   php tools/migrar-text-soft.php --write
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';
$modo = in_array('--write', $argv, true) ? 'write' : 'check';

$LITERAL = '#475569';
$TOKEN = 'var(--awa-text-soft, #475569)';

// .min.css entra tambem: varios desses arquivos nao tem fonte .css ao lado.
$arquivos = [];
foreach (glob($cssDir . '/awa-*.css') ?: [] as $f) {
    $base = basename($f);
    if (str_contains($base, '.bak')) { continue; }
    $arquivos[] = $f;
}

$totalColor = 0; $totalBg = 0; $tocados = [];
$detalhe = [];

foreach ($arquivos as $f) {
    $s = @file_get_contents($f);
    if ($s === false || stripos($s, $LITERAL) === false) { continue; }
    $orig = $s;
    $n = 0;
    // Substitui o hex em qualquer propriedade de cor/fundo/borda. Case-insensitive.
    $s = preg_replace_callback(
        '/' . preg_quote($LITERAL, '/') . '\b/i',
        function () use (&$n, $TOKEN) { $n++; return $TOKEN; },
        $s
    );
    if ($s === $orig || $n === 0) { continue; }

    // conta por propriedade, para o relatorio
    preg_match_all('/([a-z-]+)\s*:\s*[^;{}]*' . preg_quote($LITERAL, '/') . '/i', $orig, $m);
    $c = 0; $b = 0; $o = 0;
    foreach ($m[1] as $prop) {
        $p = strtolower($prop);
        if ($p === 'color' || str_ends_with($p, '-color')) { $c++; }
        elseif (str_contains($p, 'background')) { $b++; }
        else { $o++; }
    }
    $totalColor += $c; $totalBg += $b;
    $tocados[] = basename($f);
    $detalhe[] = ['arquivo' => basename($f), 'trocas' => $n, 'color' => $c, 'bg' => $b, 'outros' => $o];

    if ($modo === 'write') {
        copy($f, $f . '.bak-textsoft-' . gmdate('Ymd\THis\Z'));
        file_put_contents($f, $s);
    }
}



echo "Modo: " . strtoupper($modo) . "\n";
echo "Literal : $LITERAL\n";
echo "Token   : $TOKEN\n\n";
printf("  *** %d trocas em %d arquivos ***\n", array_sum(array_column($detalhe, 'trocas')), count($tocados));
printf("      como cor de texto: %d · como fundo: %d\n\n", $totalColor, $totalBg);

usort($detalhe, fn($a, $b) => $b['trocas'] <=> $a['trocas']);
echo "--- top 15 arquivos ---\n";
foreach (array_slice($detalhe, 0, 15) as $d) {
    printf("  %-52s %4d  (color %d, bg %d, outros %d)\n", $d['arquivo'], $d['trocas'], $d['color'], $d['bg'], $d['outros']);
}
if ($modo === 'check') {
    echo "\n(rodar com --write para aplicar; cada arquivo ganha .bak-textsoft-<ts>)\n";
}
