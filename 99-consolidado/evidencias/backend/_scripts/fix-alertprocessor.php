<?php
/**
 * task-74 — troca o TETO DE OCORRENCIAS (`occurrences <= 3`) por TETO POR JANELA DE TEMPO.
 *
 * O cooldown de 6h + notified_at continua INTACTO — e ele que garante o 1x/6h.
 * A guarda de occurrences sai porque, depois do dedupe da task-49, ela virou teto de
 * ocorrencias e silenciava incidente longo (occurrences > 3 => nunca mais notificava).
 *
 * Nenhum construtor muda => sem setup:di:compile.
 * Uso: php fix-alertprocessor.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php';
$apply = in_array('--apply', $argv, true);
$bakDir = $root . '/_backups/alert-window-' . gmdate('Ymd\THis\Z');

$anchor = <<<'TXT'
                // Send notification for unacknowledged critical alerts.
                //
                // COOLDOWN 10/10/2026 — o cron roda a cada 5 min (crontab.xml: */5 * * * *).
                // Antes desta guarda, TODO alerta 'open' com occurrences <= 3 era reenviado
                // a cada rodada: com 13 alertas abertos isso gerou 247 e-mails num unico dia
                // (13 x 12 rodadas/hora). Agora cada alerta notifica no maximo 1x a cada
                // self::NOTIFY_COOLDOWN_HOURS horas. A coluna notified_at vive em
                // awa_log_alerts (etc/db_schema.xml) — schema declarativo do Magento.
                if ($alert->getStatus() === 'open' && $alert->getOccurrences() <= 3) {
TXT;

$novo = <<<'TXT'
                // Send notification for unacknowledged critical alerts.
                //
                // COOLDOWN 10/10/2026 — o cron roda a cada 5 min (crontab.xml: schedule a cada
                // 5 minutos). Antes desta guarda, TODO alerta 'open' era reenviado a cada rodada:
                // com 13 alertas abertos isso gerou 247 e-mails num unico dia (13 x 12 rodadas
                // por hora). Agora cada alerta notifica no maximo 1x a cada
                // self::NOTIFY_COOLDOWN_HOURS horas. A coluna notified_at vive em
                // awa_log_alerts (etc/db_schema.xml) — schema declarativo do Magento.
                //
                // TETO POR JANELA DE TEMPO — 11/10/2026, task-74.
                // A condicao "occurrences <= 3" existia para conter o flood ANTES do cooldown.
                // Depois do dedupe dos analyzers (task-49) ela virou TETO DE OCORRENCIAS e passou
                // a SILENCIAR incidente longo: um alerta com occurrences maior que 3 nunca mais
                // notificava, e como o dedupe soma na MESMA linha, a recorrencia tambem ficava
                // muda para sempre (o alerta 157 chegou a occurrences=3 e o proximo incremento o
                // deixaria mudo). O volume ja e contido pelo cooldown acima: cada alerta dispara
                // no maximo 1x a cada NOTIFY_COOLDOWN_HOURS, e o dedupe garante no maximo UMA
                // linha aberta por (alert_type, severity, source). Pior caso medido: 5 linhas
                // notificaveis x 4 por dia = 20 e-mails por dia, contra 60 por dia do passivo de
                // 15 duplicatas e 247 por dia antes do cooldown. A matriz esta no relatorio da
                // task-74 e foi conferida antes de remover a guarda.
                if ($alert->getStatus() === 'open') {
TXT;

$src = file_get_contents($target);
if (substr_count($src, $anchor) !== 1) {
    $a2 = str_replace("\n", "\r\n", $anchor);
    if (substr_count($src, $a2) === 1) {
        $anchor = $a2;
        $novo = str_replace("\n", "\r\n", $novo);
    }
}
$n = substr_count($src, $anchor);
echo "ancoras exatas = $n\n";
echo 'construtores antes = ' . substr_count($src, 'function __construct') . "\n";
echo 'ocorrencias da guarda antiga no arquivo = ' . substr_count($src, 'occurrences() <= 3') . "\n";
if ($n !== 1) {
    fwrite(STDERR, "ABORTA: esperava 1 ancora, achei $n\n");
    exit(2);
}

$new = str_replace($anchor, $novo, $src);
file_put_contents('/tmp/ap.new.php', $new);
file_put_contents('/tmp/ap.old.php', $src);

$out = [];
exec('php -l /tmp/ap.new.php 2>&1', $out, $rc);
echo 'php -l (novo) => rc=' . $rc . ' :: ' . implode(' | ', $out) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: nao passa no lint; nada escrito\n");
    exit(3);
}
echo 'construtores depois = ' . substr_count($new, 'function __construct') . "\n";
echo 'guarda antiga depois = ' . substr_count($new, 'occurrences() <= 3') . " (esperado 0)\n";

echo "\n--- DIFF ---\n";
system('diff -u /tmp/ap.old.php /tmp/ap.new.php | head -45');

if (!$apply) {
    echo "\nDRY-RUN: nada escrito.\n";
    exit(0);
}

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
    fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
    exit(4);
}
copy($target, $bakDir . '/AlertProcessor.php.orig');
file_put_contents($bakDir . '/ANTES.txt', sprintf(
    "arquivo: %s\nsha256: %s\nbytes: %d\ndono: %d\ngrupo: %d\nmodo: %o\nmotivo: task-74 teto de ocorrencias -> teto por janela de tempo\n",
    $target, hash_file('sha256', $target), filesize($target), fileowner($target), filegroup($target), fileperms($target) & 0777
));

file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nBACKUP = $bakDir\n";
echo 'APLICADO: sha256 ' . hash_file('sha256', $target) . ' | bytes=' . filesize($target)
    . ' | dono/grupo/modo=' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
