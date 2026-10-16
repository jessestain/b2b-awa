<?php
/**
 * task-42 — CORRECAO: o e-mail do B2B era enviado com area=adminhtml, mas o template
 * e declarado area="frontend" (B2B/etc/email_templates.xml) e o arquivo vive em
 * view/frontend/email/. A resolucao do header.html/footer.html so acha em frontend.
 *
 * Backup -> edicao por ANCORA EXATA -> php -l. Nao roda opcache (passo separado).
 * Uso: php fix-email-area.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php';
$apply = in_array('--apply', $argv, true);

$anchor = "                    'area' => \\Magento\\Framework\\App\\Area::AREA_ADMINHTML,\n";
$novo   = "                    'area' => \\Magento\\Framework\\App\\Area::AREA_FRONTEND, // task-42: area=adminhtml nao resolve header.html/footer.html\n";

$src = file_get_contents($target);
$n = substr_count($src, $anchor);
echo "ancoras exatas encontradas = $n\n";
if ($n !== 1) {
    fwrite(STDERR, "ABORTA: esperava exatamente 1 ancora, achei $n\n");
    exit(2);
}
$new = str_replace($anchor, $novo, $src);
if ($new === $src) {
    fwrite(STDERR, "ABORTA: nada mudou\n");
    exit(3);
}

$bakDir = $root . '/_backups/email-header-' . gmdate('Ymd\THis\Z');
if ($apply) {
    if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
        fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
        exit(4);
    }
    copy($target, $bakDir . '/CustomerApproval.php.orig');
    file_put_contents($bakDir . '/ANTES.txt', sprintf(
        "arquivo: %s\nsha256: %s\nbytes: %d\ndono: %d\ngrupo: %d\nmodo: %o\nmotivo: task-42 e-mail header.html\n",
        $target, hash_file('sha256', $target), filesize($target), fileowner($target), filegroup($target), fileperms($target) & 0777
    ));
    echo "BACKUP = $bakDir\n";
}
echo 'sha256 ANTES = ' . hash_file('sha256', $target) . "\n";

$tmp = '/tmp/CustomerApproval.new.php';
file_put_contents($tmp, $new);
$out = [];
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
echo 'php -l (novo) => rc=' . $rc . ' :: ' . implode(' | ', $out) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: nao passa no lint, nada foi escrito\n");
    exit(5);
}

echo "--- DIFF ---\n";
file_put_contents('/tmp/CustomerApproval.old.php', $src);
system('diff -u /tmp/CustomerApproval.old.php ' . escapeshellarg($tmp));

if (!$apply) {
    echo "\nDRY-RUN: nada escrito\n";
    exit(0);
}
file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nAPLICADO: sha256 DEPOIS = " . hash_file('sha256', $target) .
    ' | dono/grupo/modo = ' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
echo "BACKUP=$bakDir\n";
