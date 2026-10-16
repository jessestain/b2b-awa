<?php
/**
 * Teste unitario do guard implantado NO ARQUIVO (nao em copia).
 * Extrai a regex EXATA do arquivo publicado e a exercita contra os casos reais.
 * Objetivo: provar que, quando o template parar de emitir o onload inerte (patch-02),
 * o guard segura o link SEM depender de media="print"/onload=.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php';
$src = file_get_contents($target);

echo 'sha256 do arquivo = ' . hash_file('sha256', $target) . "\n\n";

// --- extrai a regex do guard direto do arquivo ---
$pattern = null;
$lineNo = null;
foreach (explode("\n", $src) as $i => $line) {
    if (strpos($line, 'preg_match') !== false && strpos($line, 'data-awa-defer=["') !== false) {
        $s = strpos($line, "preg_match('") + strlen("preg_match('");
        $e = strpos($line, "', \$attrs)");
        if ($e === false) {
            continue;
        }
        $raw = substr($line, $s, $e - $s);
        $pattern = str_replace("\\'", "'", $raw);   // desescapa a string PHP
        $lineNo = $i + 1;
        break;
    }
}
if ($pattern === null) {
    fwrite(STDERR, "NAO ACHEI o guard no arquivo!\n");
    exit(1);
}
echo "guard encontrado na linha $lineNo e extraido do ARQUIVO:\n  $pattern\n";
if (strpos($pattern, 'home-lcp') === false) {
    fwrite(STDERR, "ABORTA: a regex extraida nao menciona home-lcp\n");
    exit(2);
}
echo "  (validacao da regex: " . var_export(@preg_match($pattern, '') !== false, true) . ")\n\n";

// --- casos ---
$base = 'rel="stylesheet" href="https://awamotos.com/static/x/css/awa-design-system.min.css?v=1" id="awa-design-system-home" data-awa-bundle="design-system"';
$cases = [
    'A) ESTADO POS-patch-02: sem media, sem onload, com home-lcp (DEVE PULAR)' =>
        [$base . ' data-awa-defer="home-lcp"', true],
    'B) ESTADO ATUAL: com home-lcp E o onload inerte do patch-02 (DEVE PULAR - pelo guard OU pelo onload)' =>
        [$base . ' data-awa-defer="home-lcp" onload="this.setAttribute(\'data-awa-ds-loaded\',\'1\')"', true],
    'C) DEFEITO ORIGINAL: DOIS data-awa-defer, home-lcp + catalog-noncritical (DEVE PULAR)' =>
        [$base . ' data-awa-defer="home-lcp" media="print" onload="this.media=\'all\'" data-awa-defer="catalog-noncritical"', true],
    'D) FALLBACK LEGITIMO: link orfao SEM home-lcp (NAO deve pular -> plugin reescreve)' =>
        ['rel="stylesheet" href="https://awamotos.com/static/x/css/awa-design-system.min.css" id="outro" data-awa-defer="catalog-noncritical"', false],
    'E) FALLBACK LEGITIMO: outro id, com o fragmento design-system (NAO deve pular)' =>
        ['rel="stylesheet" href="/static/x/css/awa-design-system.min.css" id="qualquer"', false],
    'F) FRONTEIRA: valor parecido x-home-lcp NAO pode casar (NAO deve pular)' =>
        [$base . ' data-awa-defer="x-home-lcp"', false],
    'G) FRONTEIRA: valor home-lcp-extra NAO pode casar (NAO deve pular)' =>
        [$base . ' data-awa-defer="home-lcp-extra"', false],
    'H) FRONTEIRA: home-lcp como segundo valor numa lista (DEVE PULAR)' =>
        [$base . ' data-awa-defer="catalog-noncritical home-lcp"', true],
];

echo "=== CASOS ===\n";
$fail = 0;
foreach ($cases as $label => [$attrs, $esperadoPular]) {
    $casa = (bool)preg_match($pattern, $attrs);
    $ok = ($casa === $esperadoPular);
    if (!$ok) {
        $fail++;
    }
    printf("%s  %-92s casou=%s esperado=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($casa, true), var_export($esperadoPular, true));
}

// --- e o guard ANTIGO (media/onload) sozinho, para provar que ele NAO segura o caso A ---
echo "\n=== CONTROLE: o guard ANTIGO (media=print || onload=) segura o caso A? ===\n";
$attrsA = $cases['A) ESTADO POS-patch-02: sem media, sem onload, com home-lcp (DEVE PULAR)'][0];
$oldSkip = (bool)(preg_match('/media=["\']print["\']/i', $attrsA) || preg_match('/onload=/i', $attrsA));
echo 'guard ANTIGO pularia? ' . var_export($oldSkip, true) . "  (esperado false -> era AQUI que o plugin re-deferia)\n";
echo 'guard NOVO  pularia? ' . var_export((bool)preg_match($pattern, $attrsA), true) . "  (esperado true)\n";

echo "\nfalhas = $fail\n";
exit($fail === 0 ? 0 : 3);
