<?php
/**
 * Medidor limpo da pagina de busca. Remove <style>/<script> antes de contar.
 * Uso: php medir-busca.php "<url>" ["<label>"]
 */
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0';
$url = $argv[1];
$label = $argv[2] ?? '';

function fetch(string $url, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $eff = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $n = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    curl_close($ch);
    return [substr($raw, 0, $hs), substr($raw, $hs), $code, $eff, $n];
}

[$head, $body, $code, $eff, $nredir] = fetch($url, $ua);

$clean = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $body);
$clean = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $clean);
$clean = preg_replace('#<!--.*?-->#s', '', $clean);

$cards = preg_match_all('/class="product-item-link"/', $clean);
$amount = '-';
if (preg_match('#<span class="toolbar-amount"[^>]*>(.*?)</span>#is', $clean, $m)) {
    $amount = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
}
if (preg_match('#<p class="toolbar-amount"[^>]*>(.*?)</p>#is', $clean, $m)) {
    $amount = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
}

$frases = [];
foreach ([
    'nenhum resultado' => '/[^<>]{0,60}nenhum resultado[^<>]{0,80}/i',
    'sua busca por'    => '/[^<>]{0,20}sua busca por[^<>]{0,140}/i',
    'quis dizer'       => '/[^<>]{0,60}quis dizer[^<>]{0,140}/i',
    'resultados para'  => '/[^<>]{0,20}resultados para[^<>]{0,120}/i',
] as $k => $re) {
    if (preg_match($re, $clean, $m)) {
        $frases[$k] = trim(html_entity_decode(preg_replace('/\s+/', ' ', $m[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

// bloco de sugestao clicavel
$links = [];
if (preg_match_all('#<a[^>]+href="([^"]*catalogsearch/result/[^"]*)"[^>]*>(.*?)</a>#is', $clean, $mm, PREG_SET_ORDER)) {
    foreach (array_slice($mm, 0, 6) as $a) {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($a[2])));
        if ($t !== '') {
            $links[] = $t . '  ->  ' . html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
}

echo "=========== $label\n";
echo "URL pedida : $url\n";
echo "HTTP final : $code | redirecionamentos: $nredir | URL efetiva: $eff\n";
echo "toolbar-amount : $amount\n";
echo "cards (.product-item-link) : $cards\n";
echo "frases : " . (($frases) ? json_encode($frases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(nenhuma)') . "\n";
echo "links de sugestao : " . (($links) ? "\n  - " . implode("\n  - ", $links) : '(nenhum)') . "\n";
$hdr = [];
foreach (explode("\n", $head) as $l) {
    if (preg_match('/^(HTTP\/|age:|x-cache|location:|x-magento-cache-debug:|cache-control:)/i', trim($l))) {
        $hdr[] = trim($l);
    }
}
echo "headers : " . implode(' || ', $hdr) . "\n\n";
