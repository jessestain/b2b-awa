<?php
/**
 * AWA Motos — completar o `awa-tokens-base.css` com os tokens LITERAIS do design system.
 *
 * O QUE FOI MEDIDO (06/10/2026, getComputedStyle por pagina)
 *   home ....... todos os tokens criticos definidos
 *   PLP ........ 3 vazios (--awa-font-size-10/11/13)
 *   LOGIN B2B .. *** 18 VAZIOS *** — --awa-ink, --awa-text, --awa-line, --awa-surface,
 *                --awa-radius-*, --awa-font-size-* ...
 *
 * A CAUSA
 * O design system e removido do login (`b2b_auth_shell.xml`) e da home
 * (`cms_index_index.xml`). O `awa-tokens-base.css` entrou para resolver isso, mas
 * so levou os 60 tokens ORFAOS — os que o codigo usava sem definir. Os tokens que
 * o design system DEFINE nunca foram copiados, entao onde ele nao carrega, eles
 * simplesmente nao existem.
 *
 * O QUE ESTE SCRIPT FAZ
 * Copia para o base todo token do design system cujo valor seja LITERAL ou uma
 * cadeia que termine em literal. Tokens cujo valor e `var()` de outro token ficam
 * de fora: eles resolvem sozinhos onde o outro existir, e copiar a cadeia inteira
 * duplicaria a origem da verdade.
 *
 * NAO SOBRESCREVE o que ja esta no base: o base e gerado, e o que ja veio dos
 * fallbacks foi declarado pelo proprio codigo que consome o token.
 *
 * Uso:
 *   php tools/completar-tokens-base.php --check
 *   php tools/completar-tokens-base.php --write
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';
$base = $cssDir . '/awa-tokens-base.css';
$ds = $cssDir . '/awa-design-system.css';
$modo = in_array('--write', $argv, true) ? 'write' : 'check';

if (!is_file($ds)) { fwrite(STDERR, "design system nao encontrado\n"); exit(1); }
if (!is_file($base)) { fwrite(STDERR, "awa-tokens-base.css nao existe — rodar gerar-tokens-base.php --write\n"); exit(1); }

// ── tokens ja no base ────────────────────────────────────────────────────────
$baseCss = file_get_contents($base) ?: '';
preg_match_all('/^\s*(--awa-[a-z0-9-]+)\s*:/m', $baseCss, $mb);
$jaNoBase = array_flip(array_unique($mb[1]));

// ── tokens do design system, so os de valor literal ──────────────────────────
$dsCss = file_get_contents($ds) ?: '';
preg_match_all('/^\s*(--awa-[a-z0-9-]+)\s*:\s*([^;]+);/m', $dsCss, $md, PREG_SET_ORDER);

$adicionar = [];
$pulados = [];
foreach ($md as $m) {
    $tok = $m[1];
    $val = trim($m[2]);

    // O regex `[^;]+` arrasta o comentario junto (`40px (68 usos) · ...`). Corta.
    $val = trim(preg_replace('/\/\*.*$/s', '', $val) ?? $val);
    $val = trim(preg_replace('/\s*\/\/.*$/', '', $val) ?? $val);

    if (isset($jaNoBase[$tok])) { $pulados[$tok] = 'ja esta no base'; continue; }
    if ($val === '') { $pulados[$tok] = 'valor vazio apos limpar comentario'; continue; }
    // Estrito: so valor que se sustenta sozinho. Um token cujo valor depende de
    // outro var() nao pode ser copiado para um arquivo que carrega em paginas
    // onde esse outro pode nao existir — a cadeia quebraria.
    if (stripos($val, 'var(') !== false) { $pulados[$tok] = 'valor depende de outro var()'; continue; }
    if (stripos($val, '!important') !== false) { $pulados[$tok] = 'valor tem !important (nao e token de design)'; continue; }
    $adicionar[$tok] = $val;
}

ksort($adicionar);

printf("Modo: %s\n", strtoupper($modo));
printf("Tokens ja no base ................. %d\n", count($jaNoBase));
printf("Tokens do design system ........... %d\n", count($md));
printf("*** A ADICIONAR (valor literal) ... %d ***\n", count($adicionar));
printf("Pulados .......................... %d\n\n", count($pulados));

echo "  --- a adicionar ---\n";
foreach ($adicionar as $tok => $val) {
    printf("    %-30s %s\n", $tok, mb_substr($val, 0, 46));
}
echo "\n  --- pulados (por motivo) ---\n";
$porMotivo = [];
foreach ($pulados as $tok => $motivo) { $porMotivo[$motivo][] = $tok; }
foreach ($porMotivo as $motivo => $toks) {
    printf("    %-34s %d  (%s%s)\n", $motivo, count($toks),
        implode(', ', array_slice($toks, 0, 3)), count($toks) > 3 ? ' ...' : '');
}

if ($modo === 'write') {
    // Preserva a estrutura do arquivo e acrescenta um bloco novo antes do '}' final.
    $bloco = ["", "  /* ------------------------------------------------------------------ */",
        "  /* Tokens do design system, replicados aqui porque ele nao carrega em     */",
        "  /* todas as paginas: o login B2B media 18 tokens VAZIOS em 06/10/2026.    */",
        "  /* Sem isto, todo CSS que os usa e descartado nessas paginas.             */",
        "  /* ------------------------------------------------------------------ */"];
    foreach ($adicionar as $tok => $val) {
        $bloco[] = sprintf('  %-34s %s;', $tok . ':', $val);
    }
    $bloco[] = '}';
    $novo = preg_replace('/\}\s*$/', implode("\n", $bloco) . "\n", $baseCss, 1);
    if ($novo === null || $novo === $baseCss) { fwrite(STDERR, "nao consegui inserir o bloco\n"); exit(1); }
    copy($base, $base . '.bak-completar-' . gmdate('Ymd\THis\Z'));
    file_put_contents($base, $novo);
    printf("\n  base agora com %d tokens (+%d)\n", count($jaNoBase) + count($adicionar), count($adicionar));
} else {
    echo "\n(rodar com --write para completar)\n";
}
