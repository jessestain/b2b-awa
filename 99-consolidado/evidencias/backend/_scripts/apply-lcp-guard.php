<?php
/**
 * LEAD opcao B — guard CLS/LCP em deferStylesheetsByFragments().
 * Uso:
 *   php apply-lcp-guard.php            -> DRY-RUN: backup + conta ancoras + diff (nao escreve)
 *   php apply-lcp-guard.php --apply    -> escreve (mesmo inode) apos validar a ancora
 *
 * Regras respeitadas:
 *  - backup ANTES de qualquer alteracao em _backups/cls-lcp-guard-<UTC>/
 *  - edicao por ANCORA DE TEXTO EXATO (nunca regex gananciosa)
 *  - nao roda opcache-reset (isso e passo separado, SO depois de php -l passar)
 */
$root    = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target  = $root . '/app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php';
$apply   = in_array('--apply', $argv, true);
$ts      = gmdate('Ymd\THis\Z');
$bakDir  = $root . '/_backups/cls-lcp-guard-' . $ts;

$anchor = <<<'TXT'
                    if (preg_match('/media=["\']print["\']/i', $attrs) || preg_match('/onload=/i', $attrs)) {
                        return $matches[0];
                    }
TXT;

$guard = <<<'TXT'
                    /* Guard CLS/LCP (05/10/2026 - lead, opcao B). O template awa-home-critical-stack.phtml
                       declara a criticidade de first paint no proprio <link> com data-awa-defer="home-lcp".
                       Este metodo e um FALLBACK para o caso de o layout nao remover um <link> bloqueante
                       orfao (FPC velho) e por isso NAO pode reescrever um link que se declarou
                       first-paint-critical: quem declara a criticidade e o template, nao o fallback.
                       SEM este guard o <link id="awa-design-system-home"> saia media="print"
                       onload="this.media='all'" e a home media CLS 1.7012 (um unico shift de 0.9931 em
                       t=2017ms, FCP=1612ms; o header inteiro saltava: body margin 8px -> 0).
                       Meta: CLS <= 0.10. Nao remover sem medir de novo.
                       O atributo chega DUAS vezes no mesmo tag (home-lcp e catalog-noncritical), por isso
                       o teste e por ATRIBUTO (procura o valor home-lcp dentro de um data-awa-defer), nunca
                       pela tag inteira. */
                    if (preg_match('/data-awa-defer=["\'][^"\']*(?<![A-Za-z0-9_-])home-lcp(?![A-Za-z0-9_-])[^"\']*["\']/i', $attrs)) {
                        return $matches[0];
                    }
TXT;

$orig = file_get_contents($target);
if ($orig === false) {
    fwrite(STDERR, "nao li $target\n");
    exit(1);
}

// --- BACKUP ---
if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
    fwrite(STDERR, "nao criei $bakDir\n");
    exit(1);
}
$bak = $bakDir . '/OptimizeHeadStylesPlugin.php.orig';
if (!copy($target, $bak)) {
    fwrite(STDERR, "backup falhou\n");
    exit(1);
}
file_put_contents($bakDir . '/ANTES.txt', sprintf(
    "arquivo: %s\nsha256: %s\nbytes: %d\nlinhas: %d\ndono: %d\ngrupo: %d\nmodo: %o\n",
    $target,
    hash_file('sha256', $target),
    filesize($target),
    count(file($target)),
    fileowner($target),
    filegroup($target),
    fileperms($target) & 0777
));

echo "BACKUP  = $bakDir\n";
echo 'sha256 ANTES  = ' . hash_file('sha256', $target) . "\n";
echo 'bytes ANTES   = ' . filesize($target) . ' | linhas = ' . count(file($target)) . "\n";

// --- LOCALIZA A ANCORA (texto exato, tenta LF e CRLF) ---
$eol = "\n";
$count = substr_count($orig, $anchor);
if ($count === 0) {
    $anchorCrlf = str_replace("\n", "\r\n", $anchor);
    if (substr_count($orig, $anchorCrlf) === 1) {
        $anchor = $anchorCrlf;
        $guard = str_replace("\n", "\r\n", $guard);
        $eol = "\r\n";
        $count = 1;
    }
}
echo "ancoras exatas encontradas = $count  (eol=" . ($eol === "\n" ? 'LF' : 'CRLF') . ")\n";
echo "ocorrencias do fragmento 'media=[\"\\']print[\"\\']' no arquivo (informativo) = " .
    substr_count($orig, 'media=["\']print["\']') . "\n";

if ($count !== 1) {
    fwrite(STDERR, "ABORTA: a ancora precisa aparecer exatamente 1x, apareceu $count\n");
    exit(2);
}

$new = str_replace($anchor, $anchor . "\n" . $guard, $orig);
if ($new === $orig) {
    fwrite(STDERR, "ABORTA: substituicao nao mudou nada\n");
    exit(3);
}
if (substr_count($new, 'home-lcp') < 1) {
    fwrite(STDERR, "ABORTA: guard nao entrou\n");
    exit(4);
}

file_put_contents('/tmp/lcp-guard.new.php', $new);

echo "\n--- DIFF (unified, 3 linhas de contexto) ---\n";
$tmpOld = '/tmp/lcp-guard.old.php';
file_put_contents($tmpOld, $orig);
system('diff -u ' . escapeshellarg($tmpOld) . ' ' . escapeshellarg('/tmp/lcp-guard.new.php') . ' | head -60');

// --- VALIDACAO SINTATICA ANTES DE ESCREVER NO ALVO ---
$lint = [];
exec('php -l ' . escapeshellarg('/tmp/lcp-guard.new.php') . ' 2>&1', $lint, $rc);
echo "\nphp -l /tmp/lcp-guard.new.php => rc=$rc :: " . implode(' | ', $lint) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: o conteudo novo NAO passa no php -l. Nada foi escrito no alvo.\n");
    exit(5);
}

if (!$apply) {
    echo "\nDRY-RUN: nada foi escrito em $target\n";
    exit(0);
}

$bytes = file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nAPLICADO: $bytes bytes | sha256 DEPOIS = " . hash_file('sha256', $target) .
    ' | dono/grupo/modo = ' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
echo "(nota: opcache NAO foi resetado — proximo passo e php -l no ALVO, e so entao opcache-reset.sh)\n";
