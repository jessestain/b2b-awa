<?php
/**
 * PROBLEMA B — remove a entrada <sequence> morta de Rokanthemes_Faq em
 * app/code/GrupoAwamotos/StoreSetup/etc/module.xml
 *
 * Metodo: comparacao de linha EXATA (nunca regex gananciosa).
 * O XML e validado EM MEMORIA antes de qualquer escrita no arquivo de producao.
 * Escrita com file_put_contents (trunca no lugar -> preserva inode, dono e modo).
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/StoreSetup/etc/module.xml';
$anchor = '<module name="Rokanthemes_Faq"/>';

$orig = file_get_contents($target);
if ($orig === false) {
    fwrite(STDERR, "nao li $target\n");
    exit(1);
}

$lines = preg_split('/(\r\n|\n)/', $orig, -1, PREG_SPLIT_DELIM_CAPTURE);
// reconstruir preservando os terminadores de linha
$out = [];
$removed = 0;
for ($i = 0; $i < count($lines); $i += 2) {
    $line = $lines[$i];
    $eol = $lines[$i + 1] ?? '';
    if (trim($line) === $anchor) {
        $removed++;
        continue;
    }
    $out[] = $line . $eol;
}
$new = implode('', $out);

echo "linhas removidas = $removed\n";
if ($removed !== 1) {
    fwrite(STDERR, "ABORTA: esperava exatamente 1 ocorrencia da ancora exata, achei $removed\n");
    exit(2);
}
if (strpos($new, 'Rokanthemes_Faq') !== false) {
    fwrite(STDERR, "ABORTA: ainda existe 'Rokanthemes_Faq' no conteudo novo\n");
    exit(3);
}

// VALIDACAO EM MEMORIA antes de escrever
libxml_use_internal_errors(true);
$dom = new DOMDocument();
if (!$dom->loadXML($new)) {
    foreach (libxml_get_errors() as $e) {
        fwrite(STDERR, 'XML invalido: ' . trim($e->message) . "\n");
    }
    fwrite(STDERR, "ABORTA: XML novo nao e bem formado\n");
    exit(4);
}
$rootEl = $dom->documentElement->nodeName;
$modName = $dom->getElementsByTagName('module')->item(0)->getAttribute('name');
$seq = $dom->getElementsByTagName('sequence')->item(0);
$seqNames = [];
if ($seq) {
    foreach ($seq->getElementsByTagName('module') as $m) {
        $seqNames[] = $m->getAttribute('name');
    }
}
echo "XML valido: root=$rootEl module=$modName setup_version=" .
    $dom->getElementsByTagName('module')->item(0)->getAttribute('setup_version') . "\n";
echo 'sequence agora: ' . implode(', ', $seqNames) . "\n";
echo "sha256 ANTES = " . hash_file('sha256', $target) . "\n";

$bytes = file_put_contents($target, $new);
clearstatcache(true, $target);
$st = stat($target);
echo "escrito = $bytes bytes\n";
echo "sha256 DEPOIS = " . hash_file('sha256', $target) . "\n";
echo 'tamanho depois = ' . filesize($target) . " bytes\n";
printf("dono/modo depois = %d:%d %o\n", $st['uid'], $st['gid'], $st['mode'] & 0777);
echo "\n--- CONTEUDO FINAL ---\n" . file_get_contents($target) . "\n";
