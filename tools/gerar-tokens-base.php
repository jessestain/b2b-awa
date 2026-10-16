<?php
/**
 * AWA Motos — gerar `awa-tokens-base.css` a partir dos fallbacks do proprio codigo.
 *
 * POR QUE UM ARQUIVO BASE
 * Os CSS do tema sao recortados por pagina: `cms_index_index.xml` remove o
 * design system da home, `b2b_auth_shell.xml` remove do login, e o
 * `awa-head-preload-critical-home.css` nao carrega na PLP. Resultado medido:
 * `--awa-font-size-10/11/13` existem na home e NAO existem na PLP — os mesmos
 * tokens resolvem diferente conforme a pagina.
 *
 * Este arquivo entra pelo `default_head_blocks.xml`, que vale para TODAS as
 * paginas, e nao e removido por nenhum dos recortes.
 *
 * DE ONDE VEM CADA VALOR
 * Do fallback que o proprio codigo escreve. `var(--awa-touch-min, 44px)` e uma
 * declaracao de intencao: o valor e 44px. So entram tokens cujo fallback aparece
 * com UM SO valor em todos os usos — 69 dos 86 orfaos. Os ambiguos (ex.:
 * `--awa-header-height` com 6 valores diferentes) ficam de fora de proposito:
 * escolher ali seria decisao de design, nao padronizacao.
 *
 * Uso:
 *   php tools/gerar-tokens-base.php --check
 *   php tools/gerar-tokens-base.php --write
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';
$saida = $cssDir . '/awa-tokens-base.css';
$modo = in_array('--write', $argv, true) ? 'write' : 'check';

// O mapa vem do propor-valores-orfaos.php --json: uma fonte so, sem duplicar logica.
$json = shell_exec('php ' . escapeshellarg($root . '/tools/propor-valores-orfaos.php') . ' --json 2>/dev/null');
$orfaos = json_decode((string)$json, true);
if (!is_array($orfaos)) { fwrite(STDERR, "Nao consegui ler o mapa de orfaos.\n"); exit(1); }

// Agrupa por familia para o arquivo sair legivel e revisavel.
$grupos = [];
$descartados = [];
foreach ($orfaos as $tok => $o) {
    if ($o['proposto'] === null) { $descartados[$tok] = 'sem fallback'; continue; }
    if ($o['variantes'] > 1) { $descartados[$tok] = $o['variantes'] . ' valores diferentes'; continue; }
    // fallback que e outro var() nao serve como valor literal
    if (preg_match('/^var\(/i', (string)$o['proposto'])) { $descartados[$tok] = 'fallback e outro var()'; continue; }
    preg_match('/^--awa-([a-z]+)/', $tok, $m);
    $fam = $m[1] ?? 'outros';
    $grupos[$fam][$tok] = ['valor' => $o['proposto'], 'usos' => $o['total'], 'concordam' => $o['concordam']];
}
ksort($grupos);
foreach ($grupos as &$g) { ksort($g); }
unset($g);

$total = 0;
foreach ($grupos as $g) { $total += count($g); }

$linhas = [];
$linhas[] = '/*';
$linhas[] = ' * AWA Motos — tokens base do tema (arquivo gerado)';
$linhas[] = ' * ================================================';
$linhas[] = ' *';
$linhas[] = ' * POR QUE ESTE ARQUIVO EXISTE';
$linhas[] = ' * Os CSS do tema sao recortados por pagina: a home remove o design system,';
$linhas[] = ' * o login remove tambem, e o CSS critico da home nao carrega na PLP. Medido';
$linhas[] = ' * em 06/10/2026: `--awa-font-size-10/11/13` existiam na home e NAO na PLP.';
$linhas[] = ' * Este arquivo entra pelo `default_head_blocks.xml`, que vale para todas as';
$linhas[] = ' * paginas e nao e removido por nenhum desses recortes.';
$linhas[] = ' *';
$linhas[] = ' * DE ONDE VEM CADA VALOR';
$linhas[] = ' * Do fallback escrito no proprio codigo: `var(--awa-touch-min, 44px)` declara';
$linhas[] = ' * que o valor e 44px. Entram apenas tokens cujo fallback aparece com UM SO';
$linhas[] = ' * valor em todos os usos. Os ambiguos ficam de fora — escolher ali seria';
$linhas[] = ' * decisao de design, nao padronizacao.';
$linhas[] = ' *';
$linhas[] = ' * NAO EDITE A MAO: e gerado por `tools/gerar-tokens-base.php`.';
$linhas[] = ' * Gerado em ' . gmdate('Y-m-d\TH:i:s\Z') . ' · ' . $total . ' tokens.';
$linhas[] = ' */';
$linhas[] = ':root {';
foreach ($grupos as $fam => $toks) {
    $linhas[] = '';
    $linhas[] = '  /* ' . $fam . ' */';
    foreach ($toks as $tok => $info) {
        $linhas[] = sprintf('  %-34s %s;', $tok . ':', $info['valor']);
    }
}
$linhas[] = '}';
$conteudo = implode("\n", $linhas) . "\n";

printf("Modo: %s\n", strtoupper($modo));
printf("Tokens com valor declarado (fallback unico) ... %d\n", $total);
printf("Descartados por ambiguidade ou ausencia ...... %d\n", count($descartados));
echo "\n  --- por familia ---\n";
foreach ($grupos as $fam => $toks) {
    printf("    %-14s %3d   %s\n", $fam, count($toks),
        implode(' ', array_map(fn($t) => str_replace('--awa-', '', $t), array_slice(array_keys($toks), 0, 4)))
        . (count($toks) > 4 ? ' ...' : ''));
}
echo "\n  --- descartados (nao aplico: seria escolha de design) ---\n";
foreach (array_slice($descartados, 0, 12, true) as $tok => $motivo) {
    printf("    %-34s %s\n", $tok, $motivo);
}
if (count($descartados) > 12) { printf("    ... e mais %d\n", count($descartados) - 12); }

if ($modo === 'write') {
    if (file_exists($saida)) { copy($saida, $saida . '.bak-' . gmdate('Ymd\THis\Z')); }
    file_put_contents($saida, $conteudo);
    printf("\n  escrito: %s (%d bytes)\n", basename($saida), strlen($conteudo));
} else {
    echo "\n(rodar com --write para gerar; preview das 12 primeiras linhas:)\n";
    echo implode("\n", array_slice(explode("\n", $conteudo), 22, 12)), "\n";
}
