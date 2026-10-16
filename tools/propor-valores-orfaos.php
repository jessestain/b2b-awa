<?php
/**
 * AWA Motos — para cada token ORFAO, extrair dos proprios usos o valor pretendido.
 *
 * PRINCIPIO
 * Nao invento valores. Quando alguem escreve `var(--awa-neutral-800, #1f2937)`,
 * essa linha e uma declaracao de intencao: "o valor disto e #1f2937". O fallback
 * e a especificacao. Este script colhe esses fallbacks e usa o mais frequente
 * como valor proposto — com a contagem e a alternativa a vista, para que a
 * decisao seja auditavel e nao uma escolha minha.
 *
 * O QUE ELE *NAO* FAZ
 * Nao escreve nada. So produz o mapa token -> valor proposto + confianca
 * (quantos usos concordam). Tokens sem fallback nenhum ficam marcados como
 * INDETERMINADO e nao entram no lote aplicavel.
 *
 * Uso: php tools/propor-valores-orfaos.php [--json]
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$dirs = [
    $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css',
    $root . '/app/code/GrupoAwamotos/AiAssistant/view/frontend/web/css',
];

$arquivos = [];
foreach ($dirs as $d) {
    foreach (glob($d . '/*.css') ?: [] as $f) {
        if (str_contains(basename($f), '.bak')) { continue; }
        if (str_contains(basename($f), '.min.css')) { continue; }   // a fonte basta
        $arquivos[] = $f;
    }
}

// ── definicoes ───────────────────────────────────────────────────────────────
$definido = [];
foreach ($arquivos as $f) {
    $s = @file_get_contents($f) ?: '';
    if (preg_match_all('/(--awa-[a-z0-9-]+)\s*:/i', $s, $m)) {
        foreach ($m[1] as $t) { $definido[$t] = true; }
    }
}

// ── usos + fallbacks ─────────────────────────────────────────────────────────
/** Separa var(token[, fallback]) respeitando parenteses aninhados. */
$partir = function (string $interior): array {
    $prof = 0; $corte = null;
    for ($i = 0, $n = strlen($interior); $i < $n; $i++) {
        $c = $interior[$i];
        if ($c === '(') { $prof++; }
        elseif ($c === ')') { $prof--; }
        elseif ($c === ',' && $prof === 0) { $corte = $i; break; }
    }
    $tok = trim($corte === null ? $interior : substr($interior, 0, $corte));
    $fb  = $corte === null ? null : trim(substr($interior, $corte + 1));
    return [$tok, ($fb === '' ? null : $fb)];
};

$usos = [];      // token => {sem_fb, com_fb, fallbacks: {valor => n}, propriedades: {p => n}, arquivos:Set}
foreach ($arquivos as $f) {
    $s = @file_get_contents($f) ?: '';
    $base = basename($f);
    /*
     * A versao anterior capturava `([^;{}]*var\(--awa-[a-z0-9-]+)` — o trecho
     * TERMINAVA no nome do token, entao o `)` e o fallback nunca entravam e o
     * fallback jamais era lido. O sintoma foi o nome truncado no relatorio
     * (`--awa-primar`, `--awa-space-`). Aqui a declaracao inteira e lida:
     * da propriedade ate o `;` ou `}`, e cada var() e extraida com profundidade.
     */
    if (!preg_match_all('/([a-z-]+)\s*:\s*([^;{}]+)/i', $s, $decl, PREG_SET_ORDER)) { continue; }
    foreach ($decl as $D) {
        $prop = strtolower($D[1]);
        $valor = $D[2];
        if (!preg_match_all('/var\(/i', $valor, $mm, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($mm[0] as $pos) {
            $ini = $pos[1] + 4;
            $prof = 1; $j = $ini;
            $n = strlen($valor);
            while ($j < $n && $prof > 0) {
                if ($valor[$j] === '(') { $prof++; }
                elseif ($valor[$j] === ')') { $prof--; }
                $j++;
            }
            if ($prof !== 0) { continue; }               // parentese desbalanceado, ignora
            $interior = substr($valor, $ini, $j - $ini - 1);
            [$tok, $fb] = $partir($interior);
            if (!preg_match('/^--awa-[a-z0-9-]+$/i', $tok)) { continue; }
            $u =& $usos[$tok];
            $u['arquivos'][$base] = true;
            $u['propriedades'][$prop] = ($u['propriedades'][$prop] ?? 0) + 1;
            if ($fb === null) {
                $u['sem_fb'] = ($u['sem_fb'] ?? 0) + 1;
            } else {
                $u['com_fb'] = ($u['com_fb'] ?? 0) + 1;
                $u['fallbacks'][$fb] = ($u['fallbacks'][$fb] ?? 0) + 1;
            }
        }
    }
}

// ── CONTROLE ────────────────────────────────────────────────────────────────
// O relatorio anterior saiu com nomes truncados e 741 "indeterminados" — sinal
// de parser errado, nao de codigo ruim. Um token truncado termina em '-'.
$modoJson = in_array('--json', $argv, true);
$suspeitos = [];
foreach ($usos as $tok => $u) {
    // Unico sinal confiavel de truncamento: terminar em '-'. O tamanho do sufixo
    // NAO serve de criterio — `--awa-bg` e `--awa-x` sao tokens legitimos, e a
    // primeira versao deste controle os marcou como suspeitos por engano.
    if (str_ends_with($tok, '-')) { $suspeitos[] = $tok; }
}
$comFallback = 0;
foreach ($usos as $u) { if (($u['com_fb'] ?? 0) > 0) { $comFallback++; } }
$ok = count($suspeitos) === 0;

// No modo --json o controle vai para STDERR: stdout tem que ser JSON puro, e
// era isso que quebrava o gerar-tokens-base.php (que consome o mapa).
if ($modoJson) {
    fwrite(STDERR, sprintf("controle: truncados=%d fallbacks_lidos=%d/%d %s\n",
        count($suspeitos), $comFallback, count($usos), $ok ? 'PASSOU' : 'FALHOU'));
    if (!$ok) { exit(1); }
} else {
    echo "================ CONTROLE DO INSTRUMENTO ================\n";
    printf("  tokens com nome SUSPEITO (truncado): %d %s\n", count($suspeitos),
        $suspeitos ? '>>> ' . implode(', ', array_slice($suspeitos, 0, 5)) : '(nenhum)');
    printf("  tokens com fallback lido .......... %d de %d\n", $comFallback, count($usos));
    printf("  *** controle: %s ***\n\n", $ok
        ? 'PASSOU — nomes integros e fallbacks sendo lidos'
        : 'FALHOU — o parser ainda trunca; NAO confiar no relatorio abaixo');
    if (!$ok) { exit(1); }
}

// ── so os orfaos ─────────────────────────────────────────────────────────────
$orfaos = [];
foreach ($usos as $tok => $u) {
    if (isset($definido[$tok])) { continue; }
    $fbs = $u['fallbacks'] ?? [];
    arsort($fbs);
    $melhor = array_key_first($fbs);
    $orfaos[$tok] = [
        'sem_fb' => $u['sem_fb'] ?? 0,
        'com_fb' => $u['com_fb'] ?? 0,
        'total' => ($u['sem_fb'] ?? 0) + ($u['com_fb'] ?? 0),
        'proposto' => $melhor,
        'concordam' => $melhor !== null ? $fbs[$melhor] : 0,
        'variantes' => count($fbs),
        'outros' => array_slice($fbs, 1, 2, true),
        'propriedades' => $u['propriedades'] ?? [],
        'arquivos' => array_keys($u['arquivos'] ?? []),
    ];
}

uksort($orfaos, fn($a, $b) => $orfaos[$b]['total'] <=> $orfaos[$a]['total']);

// O modo --json sai ANTES de qualquer eco: o controle e os relatorios humanos
// poluiriam a saida e quebrariam quem consome o JSON (o gerar-tokens-base.php).
// Se o controle falhar, o erro vai para STDERR e o exit code denuncia.
if (in_array('--json', $argv, true)) {
    fwrite(STDERR, "controle: nomes truncados=" . count($suspeitos) . " fallbacks_lidos={$comFallback}\n");
    if ($suspeitos) { fwrite(STDERR, "FALHOU: parser trunca\n"); exit(1); }
    echo json_encode($orfaos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("  %-34s %6s %6s  %-22s %s\n", 'token', 'semFb', 'comFb', 'valor proposto', 'concordam/variantes');
printf("  %s\n", str_repeat('-', 96));
foreach ($orfaos as $tok => $o) {
    if ($o['proposto'] === null) {
        printf("  %-34s %6d %6d  %-22s *** INDETERMINADO ***\n", $tok, $o['sem_fb'], $o['com_fb'], '-');
        continue;
    }
    $prop = mb_substr($o['proposto'], 0, 21);
    $alerta = $o['variantes'] > 1 ? " (AMBIGUO: " . implode(' | ', array_map(fn($v, $n) => mb_substr((string)$v, 0, 14) . " x$n", array_keys($o['outros']), $o['outros'])) . ")" : '';
    printf("  %-34s %6d %6d  %-22s %d/%d%s\n", $tok, $o['sem_fb'], $o['com_fb'], $prop, $o['concordam'], $o['variantes'], $alerta);
}
