<?php
/**
 * AWA Motos — quais tokens --awa-* sao USADOS mas nunca DEFINIDOS em lugar nenhum?
 *
 * POR QUE ISTO EXISTE
 * O bug do `--awa-brand: var(--awa-brand)` (achado em 06/10/2026) derrubou 1.170
 * declaracoes CSS e deixou o botao primario transparente. Um token circular e a
 * forma mais visivel de "token sem valor" — mas nao e a unica. Um token usado e
 * nunca definido causa EXATAMENTE o mesmo dano: a declaracao inteira e descartada.
 *
 * Este sweep procura o irmao mais amplo desse bug: todo `var(--awa-*)` cujo token
 * nao tem definicao em NENHUM arquivo CSS que a loja realmente serve.
 *
 * O QUE CONTA COMO "DEFINIDO"
 * Uma linha `--awa-x: <valor>` em qualquer arquivo. Precisa ser valor, nao var():
 *   --awa-x: #fff            -> definido
 *   --awa-x: var(--outro)    -> definido SE o outro for definido (cadeia)
 *   --awa-x: var(--awa-x)    -> CIRCULAR, nao conta
 *
 * Uso:
 *   php tools/varrer-tokens-orfaos.php                 # tudo em web/css
 *   php tools/varrer-tokens-orfaos.php --so=servidos   # so o que a home carrega
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$cssDir = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css';
$moduloDir = $root . '/app/code/GrupoAwamotos/AiAssistant/view/frontend/web/css';

$arquivos = [];
foreach ([$cssDir, $moduloDir] as $dir) {
    foreach (glob($dir . '/*.css') ?: [] as $f) {
        if (str_contains(basename($f), '.bak')) { continue; }
        $arquivos[] = $f;
    }
}

// ── 1) coleta DEFINICOES ─────────────────────────────────────────────────────
$definicoes = [];   // token => lista de {arquivo, valor, linha}
foreach ($arquivos as $f) {
    $linhas = @file($f) ?: [];
    foreach ($linhas as $i => $linha) {
        if (!preg_match_all('/(--awa-[a-z0-9-]+)\s*:\s*([^;{}]+)/i', $linha, $m, PREG_SET_ORDER)) { continue; }
        foreach ($m as $mm) {
            $tok = $mm[1];
            $val = trim($mm[2]);
            $definicoes[$tok][] = ['arquivo' => basename($f), 'valor' => $val, 'linha' => $i + 1];
        }
    }
}

// ── 2) resolve a CADEIA: um token so tem valor real se chegar a um literal ───
/**
 * Separa `var(<token>[, <fallback>])` respeitando PARENTESES ANINHADOS.
 * Um regex simples de fallback quebra em `var(--a, var(--b))` — e foi exatamente
 * esse o erro da primeira versao deste sweep, que marcou 152 tokens como orfaos
 * quando a maioria resolvia. Aqui a contagem de profundidade decide onde o
 * fallback termina.
 */
$partirVar = function (string $v): ?array {
    $v = trim($v);
    if (stripos($v, 'var(') !== 0) { return null; }
    $interior = substr($v, 4, -1);            // tira "var(" e ")"
    $prof = 0; $corte = null;
    for ($i = 0, $n = strlen($interior); $i < $n; $i++) {
        $c = $interior[$i];
        if ($c === '(') { $prof++; }
        elseif ($c === ')') { $prof--; }
        elseif ($c === ',' && $prof === 0) { $corte = $i; break; }
    }
    $tok = trim($corte === null ? $interior : substr($interior, 0, $corte));
    $fb  = $corte === null ? null : trim(substr($interior, $corte + 1));
    if (!preg_match('/^--[a-z0-9-]+$/i', $tok)) { return null; }
    return ['token' => $tok, 'fallback' => ($fb === '' ? null : $fb)];
};

$resolver = function (string $tok, array $pilha = []) use (&$resolver, $definicoes, $partirVar): array {
    if (in_array($tok, $pilha, true)) { return ['ok' => false, 'motivo' => 'circular', 'via' => $pilha]; }
    if (!isset($definicoes[$tok])) { return ['ok' => false, 'motivo' => 'nao_definido', 'via' => []]; }
    $pilha[] = $tok;
    foreach ($definicoes[$tok] as $d) {
        $v = trim($d['valor']);
        $p = $partirVar($v);
        if ($p !== null) {
            // var(--outro) ou var(--outro, fallback)
            $r = $resolver($p['token'], $pilha);
            if ($r['ok']) { return ['ok' => true, 'via' => array_merge([$tok], $r['via']), 'arquivo' => $d['arquivo']]; }
            if ($p['fallback'] !== null) {
                // o fallback pode ser outro var() — segue a cadeia dele tambem
                $pf = $partirVar($p['fallback']);
                if ($pf !== null) {
                    $rf = $resolver($pf['token'], $pilha);
                    if ($rf['ok']) { return ['ok' => true, 'via' => array_merge([$tok], $rf['via']), 'arquivo' => $d['arquivo']]; }
                    if ($pf['fallback'] !== null) { return ['ok' => true, 'via' => [$tok, 'fallback-literal'], 'arquivo' => $d['arquivo']]; }
                    continue;
                }
                return ['ok' => true, 'via' => [$tok, 'fallback:' . $p['fallback']], 'arquivo' => $d['arquivo']];
            }
            continue;
        }
        if (stripos($v, 'var(') === 0) { continue; }
        return ['ok' => true, 'via' => [$tok, $v], 'arquivo' => $d['arquivo']];
    }
    return ['ok' => false, 'motivo' => 'todos_os_valores_sao_var', 'via' => $pilha];
};

// ── 3) coleta USOS e checa se o token resolve ────────────────────────────────
$usos = [];   // token => {n, sem_fallback, arquivos:Set}
foreach ($arquivos as $f) {
    $s = @file_get_contents($f) ?: '';
    // var(--awa-x) sem fallback
    if (preg_match_all('/var\(\s*(--awa-[a-z0-9-]+)\s*\)/i', $s, $m)) {
        foreach ($m[1] as $tok) {
            $usos[$tok]['n'] = ($usos[$tok]['n'] ?? 0) + 1;
            $usos[$tok]['sem_fallback'] = ($usos[$tok]['sem_fallback'] ?? 0) + 1;
            $usos[$tok]['arquivos'][basename($f)] = true;
        }
    }
    // var(--awa-x, fallback)
    if (preg_match_all('/var\(\s*(--awa-[a-z0-9-]+)\s*,/i', $s, $m)) {
        foreach ($m[1] as $tok) {
            $usos[$tok]['n'] = ($usos[$tok]['n'] ?? 0) + 1;
            $usos[$tok]['com_fallback'] = ($usos[$tok]['com_fallback'] ?? 0) + 1;
            $usos[$tok]['arquivos'][basename($f)] = true;
        }
    }
}

// ── 4) relatorio: tokens que NAO resolvem ────────────────────────────────────
$orfaos = [];
foreach ($usos as $tok => $u) {
    $r = $resolver($tok);
    if (!$r['ok']) { $orfaos[$tok] = ['uso' => $u, 'resolucao' => $r]; }
}

uksort($orfaos, fn($a, $b) => ($orfaos[$b]['uso']['sem_fallback'] ?? 0) <=> ($orfaos[$a]['uso']['sem_fallback'] ?? 0));

// ── CONTROLE ────────────────────────────────────────────────────────────────
// Antes de confiar no numero, testo o instrumento contra casos de resposta
// CONHECIDA. Sem isto eu persigo falso positivo — foi o que aconteceu na
// primeira versao, que marcou 152 orfaos por causa de um regex que quebrava em
// var() aninhado.
echo "================ CONTROLE DO INSTRUMENTO ================\n";
$controles = [
    // token => resultado esperado
    '--awa-brand'      => true,    // corrigido hoje: --awa-brand: #b73337
    '--awa-brand-dark' => true,    // corrigido hoje: #8e2629
    '--awa-ink'        => true,    // #333333
    '--awa-text-soft'  => true,    // #475569, criado hoje
];
$falhou = 0;
foreach ($controles as $tok => $esperadoOk) {
    $r = $resolver($tok);
    $ok = $r['ok'] === $esperadoOk;
    if (!$ok) { $falhou++; }
    $via = implode(' -> ', array_slice($r['via'] ?? [], 0, 3));
    printf("  %-20s resolvido=%-6s esperado=%-6s %s  %s\n", $tok, $r['ok'] ? 'SIM' : 'NAO',
        $esperadoOk ? 'SIM' : 'NAO', $ok ? 'OK' : '*** FALHOU ***', $via);
}
// controle negativo: um token que eu INVENTEI nao pode resolver
$inv = $resolver('--awa-nao-existe-mesmo-xyz');
printf("  %-20s resolvido=%-6s esperado=%-6s %s\n", '--awa-nao-existe-mesmo-xyz',
    $inv['ok'] ? 'SIM' : 'NAO', 'NAO', $inv['ok'] ? '*** FALHOU ***' : 'OK');
if ($inv['ok']) { $falhou++; }
printf("\n  *** controle: %s ***\n\n", $falhou === 0 ? 'PASSOU — o instrumento mede o que diz medir' : "FALHOU em $falhou caso(s) — NAO confiar no relatorio abaixo");

printf("Arquivos analisados ....... %d\n", count($arquivos));
printf("Tokens DEFINIDOS ......... %d\n", count($definicoes));
printf("Tokens USADOS ............ %d\n", count($usos));
printf("*** TOKENS QUE NAO RESOLVEM: %d ***\n\n", count($orfaos));

if (!$orfaos) { echo "Nenhum token orfao.\n"; exit(0); }

$perdaTotal = 0;
printf("  %-34s %8s %8s  %s\n", 'token', 'semFb', 'comFb', 'motivo');
foreach ($orfaos as $tok => $o) {
    $sem = $o['uso']['sem_fallback'] ?? 0;
    $com = $o['uso']['com_fallback'] ?? 0;
    $perdaTotal += $sem;
    printf("  %-34s %8d %8d  %s\n", $tok, $sem, $com, $o['resolucao']['motivo']);
}

printf("\n  *** DECLARACOES DESCARTADAS (usos sem fallback): %d ***\n", $perdaTotal);
echo "\n  --- onde os 10 piores aparecem ---\n";
$i = 0;
foreach ($orfaos as $tok => $o) {
    if ($i++ >= 10) { break; }
    $arqs = array_keys($o['uso']['arquivos']);
    printf("  %-34s %s%s\n", $tok, implode(', ', array_slice($arqs, 0, 3)), count($arqs) > 3 ? ' (+' . (count($arqs) - 3) . ')' : '');
}
