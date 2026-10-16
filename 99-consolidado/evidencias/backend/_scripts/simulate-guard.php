<?php
/**
 * PROVA DE CAUSALIDADE (offline, zero impacto em producao).
 * Pega o <link> REAL do HTML servido AGORA e mostra o que cada guard produziria.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php';

// 1) a regex REALMENTE implantada, extraida do arquivo
$src = file_get_contents($target);
$pattern = null;
foreach (explode("\n", $src) as $line) {
    if (strpos($line, 'preg_match') !== false && strpos($line, 'data-awa-defer=["') !== false) {
        $s = strpos($line, "preg_match('") + strlen("preg_match('");
        $e = strpos($line, "', \$attrs)");
        $pattern = str_replace("\\'", "'", substr($line, $s, $e - $s));
        break;
    }
}

// 2) o HTML servido agora
$html = (string)file_get_contents('https://awamotos.com/?cb=' . random_int(1, 999999));
if (!preg_match('/<link[^>]*awa-design-system-home[^>]*>/i', $html, $m)) {
    fwrite(STDERR, "nao achei o link no HTML servido\n");
    exit(1);
}
$tag = $m[0];
echo "TAG SERVIDA AGORA:\n  $tag\n\n";

preg_match('/<link\s+([^>]*?)\s*\/?>/i', $tag, $am);
$attrs = $am[1];

// 3) a transformacao EXATA do plugin (copiada do metodo implantado)
$rewrite = static function (string $attrs): string {
    $attrs = preg_replace('/\s*media=["\']all["\']\s*/i', ' ', $attrs) ?? $attrs;
    $attrs = trim(preg_replace('/\s+/', ' ', $attrs) ?? $attrs);
    $attrs = rtrim($attrs, '/');
    return '<link ' . $attrs . ' media="print" onload="this.media=\'all\'" data-awa-defer="catalog-noncritical"/>';
};

echo "=== ATTRS REAIS ===\n  $attrs\n\n";

// 4) CONTRAPACTUAL: guard ANTIGO (media=print || onload=)
$oldSkip = (bool)(preg_match('/media=["\']print["\']/i', $attrs) || preg_match('/onload=/i', $attrs));
echo "=== SEM O MEU GUARD (guard antigo) ===\n";
echo '  pularia? ' . var_export($oldSkip, true) . "\n";
echo "  SERVIDO SERIA:\n    " . ($oldSkip ? $tag : $rewrite($attrs)) . "\n\n";

// 5) REAL: guard NOVO (data-awa-defer home-lcp)
$newSkip = (bool)preg_match($pattern, $attrs);
echo "=== COM O MEU GUARD (implantado) ===\n";
echo '  pularia? ' . var_export($newSkip, true) . "\n";
echo "  SERVIDO E: \n    " . ($newSkip ? $tag : $rewrite($attrs)) . "\n\n";

echo ($newSkip && !$oldSkip)
    ? "VEREDITO: o guard E LOAD-BEARING — sem ele este link voltaria a media=\"print\" + data-awa-defer duplicado.\n"
    : "VEREDITO: o guard NAO muda nada neste estado (mascarado).\n";
