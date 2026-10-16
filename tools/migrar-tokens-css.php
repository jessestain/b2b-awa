<?php
/**
 * AWA Motos — migrar valores literais identicos para os tokens do design system.
 *
 * POR QUE ISTO E SEGURO
 * Nao muda nenhuma cor: troca um literal pelo token que tem EXATAMENTE o mesmo
 * valor. `#b73337` vira `var(--awa-brand)`, e `--awa-brand` e `#b73337`. O pixel
 * na tela e identico — o que muda e que a marca passa a ter UM lugar so.
 *
 * POR QUE VALE A PENA
 * Medido em 06/10/2026 nos CSS do tema: `#b73337` aparecia ***314 vezes como
 * literal*** contra apenas 104 usos do token. Ou seja: o valor da marca estava
 * duplicado 3x mais do que referenciado. Qualquer mudanca de marca exigiria
 * cacar 314 pontos, e esquecer um criaria a divergencia que ja existe hoje com a
 * escala oklch (uma segunda paleta, com vermelhos diferentes do da marca).
 *
 * O QUE ESTE SCRIPT NAO FAZ (de proposito)
 * Nao toca em `oklch(...)`. A conversao provou que eles NAO sao equivalentes:
 *   oklch(0.525 0.155 27.5) -> #b23c34  x  --awa-brand #b73337  (distancia 20)
 *   oklch(0.32 0.012 27)    -> #393130  x  --awa-ink   #333333  (distancia 11)
 * Trocar seria mudar a cor — decisao de identidade visual, nao padronizacao.
 * Fica registrado como pendencia explicita.
 *
 * Uso:
 *   php tools/migrar-tokens-css.php --check
 *   php tools/migrar-tokens-css.php --write
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';
$modo = in_array('--write', $argv, true) ? 'write' : 'check';
$filtro = null;
foreach ($argv as $a) { if (str_starts_with($a, '--so=')) { $filtro = substr($a, 5); } }

/**
 * literal => token. Somente pares PROVADOS identicos NO RUNTIME.
 *
 * A prova nao e o arquivo CSS: e o `getComputedStyle(:root)` lido nas paginas
 * reais (tools/probe-token-disponivel.js, 06/10/2026). Isso importa porque tokens
 * sao REDEFINIDOS em runtime neste tema — `--awa-ink` vale `#333333` no arquivo e
 * `#1a1a1a` na home, e nem existe no login. Migrar esses mudaria a cor.
 *
 * Os dois abaixo valem o mesmo nas 4 paginas testadas (home, PLP, carrinho, login):
 *   --awa-brand      -> #b73337 em todas
 *   --awa-brand-dark -> #8e2629 em todas
 * Por isso vao SEM fallback: se um dia o token sumir, quero que quebre visivelmente
 * em vez de silenciosamente cair num literal que ninguem mais atualiza.
 */
$mapa = [
    '#b73337' => ['token' => '--awa-brand',      'prova' => 'getComputedStyle(:root) = #b73337 em home, PLP, carrinho e login'],
    '#8e2629' => ['token' => '--awa-brand-dark', 'prova' => 'getComputedStyle(:root) = #8e2629 em home, PLP, carrinho e login'],
];

/**
 * NAO migrados, com o motivo medido. Fica no codigo para ninguem "completar" a
 * migracao depois sem refazer a medicao:
 *
 *   #333333 -> --awa-ink        RESOLVE para #1a1a1a (home/PLP/carrinho) e nao
 *                               existe no login (herda rgb(17,24,39)). MUDARIA a cor.
 *   #6b7280 -> --awa-ink-soft   Ausente no login.
 *   #e7e7ea -> --awa-line       Ausente no login.
 *   #f7f7f8 -> --awa-surface-alt Ausente no login.
 *   #f9eeee -> --awa-brand-soft  Ausente no login.
 */

$arquivos = glob($cssDir . '/awa-*.css') ?: [];
$alvos = [];
foreach ($arquivos as $f) {
    $base = basename($f);
    if (str_contains($base, '.min.css') || str_contains($base, '.bak')) { continue; }
    if ($filtro && !str_contains($base, $filtro)) { continue; }
    $alvos[] = $f;
}

printf("Modo: %s\n", strtoupper($modo));
printf("Arquivos CSS analisados: %d\n\n", count($alvos));

$totalPorToken = array_fill_keys(array_keys($mapa), 0);
$arquivosTocados = [];
$detalhe = [];

foreach ($alvos as $f) {
    $s = file_get_contents($f);
    if ($s === false) { continue; }
    $orig = $s;
    $neste = [];

    // Case-insensitive: #B73337 e #b73337 sao a mesma cor. O \b evita casar
    // prefixo de um hex maior (ex.: #3333333 nao existe, mas #33333333 sim).
    foreach ($mapa as $lit => $info) {
        $tok = 'var(' . $info['token'] . ')';
        $n = 0;
        $s = preg_replace_callback(
            '/' . preg_quote($lit, '/') . '\b/i',
            function () use (&$n, $tok) { $n++; return $tok; },
            $s
        );
        if ($n > 0) {
            $neste[$lit] = $n;
            $totalPorToken[$lit] += $n;
        }
    }

    if ($s !== $orig) {
        $arquivosTocados[] = basename($f);
        $detalhe[] = ['arquivo' => basename($f), 'trocas' => $neste];
        if ($modo === 'write') {
            $bak = $f . '.bak-tokens-' . gmdate('Ymd\THis\Z');
            copy($f, $bak);
            file_put_contents($f, $s);
        }
    }
}

echo "================ RESULTADO ================\n";
$total = 0;
foreach ($mapa as $lit => $info) {
    $n = $totalPorToken[$lit];
    $total += $n;
    printf("  %-10s -> %-20s %4d troca(s)   (prova: %s)\n", $lit, 'var(' . $info['token'] . ')', $n, $info['prova']);
}
printf("\n  *** TOTAL: %d trocas em %d arquivos ***\n", $total, count($arquivosTocados));

echo "\n--- por arquivo (top 15) ---\n";
usort($detalhe, fn($a, $b) => array_sum($b['trocas']) <=> array_sum($a['trocas']));
foreach (array_slice($detalhe, 0, 15) as $d) {
    $parts = [];
    foreach ($d['trocas'] as $lit => $n) { $parts[] = "$lit x$n"; }
    printf("  %-52s %4d  (%s)\n", $d['arquivo'], array_sum($d['trocas']), implode(', ', $parts));
}

if ($modo === 'check') {
    echo "\n(rodar com --write para aplicar; cada arquivo ganha .bak-tokens-<timestamp>)\n";
}
