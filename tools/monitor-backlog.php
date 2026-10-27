<?php
/**
 * monitor-backlog.php — monitora ACÚMULO (backlog), NÃO liveness de processo.
 *
 * POR QUE (o defeito que este script corrige, medido em 03/10/2026)
 *   O watchdog de consumers media LIVENESS: contava processos. Resultado:
 *     • 186 restarts em 5 h — todos de consumers SAUDÁVEIS que saem quando a fila esvazia ✗
 *     • e, ao mesmo tempo, NÃO enxergou o `product_alert`, onde estava o problema real
 *       (63 mensagens publicadas e nunca despachadas ✗), porque ele não era um dos 3 da lista fixa ✗
 *   O mesmo defeito apareceu no monitor de cron (`OPS-006`): ele alertava `missing` olhando a
 *   `cron_schedule` (retenção curta) em vez de olhar o que importa — **acúmulo de atraso**.
 *   → regra: medir ACÚMULO, não processo; e cobrir TODOS os consumers, não uma lista fixa.
 *
 * O QUE ELE FAZ
 *   1. Consumers: lista DINÂMICA via `bin/magento queue:consumers:list` (cobre todos, inclusive
 *      os que ninguém lembrou de pôr no shell script) e mede o backlog por fila/tópico
 *      (`queue_message_status.status = 1` = pendente).
 *   2. Cron: mede a **idade do pendente mais antigo** e quantos estão vencidos há mais de
 *      `--idade-min` (default 20 min) — por `job_code`.
 *   3. Compara com o estado anterior (`var/monitor-backlog.state.json`) e ALARMA quando o
 *      backlog **cresce** (não quando o processo está ausente ✗).
 *      **Sem pendente + sem processo = SAUDÁVEL (não alerta)** ✓ — por construção.
 *
 * USO
 *   php monitor-backlog.php                 # imprime o quadro e sai 0
 *   php monitor-backlog.php --alarmar       # sai 1 se houver ALARME (para cron/watchdog)
 *   php monitor-backlog.php --idade-min=20  # limiar do cron (default 20)
 *
 * INSTALAÇÃO (proposta — não instalado por este script)
 *   No cron, a cada 5 minutos:
 *     /usr/bin/php <docroot>/tools/monitor-backlog.php --alarmar >> <docroot>/var/log/monitor-backlog.log 2>&1
 *   (escrevi "a cada 5 minutos" em palavras de propósito: a sequência que fecha comentário
 *    de bloco nao pode aparecer aqui dentro — foi o que quebrou este arquivo na 1a versao ✗)
 *
 * CUIDADO: só LEITURA no banco. Não reinicia consumer, não republica, não descarta mensagem
 * (reprocessar/descartar as mensagens antigas é decisão do usuário — podem sair para pessoa real ✗).
 *
 * @author backend-operacoes (task: substituir os 2 monitores de liveness)
 */

$docroot = getenv('DOCROOT') ?: dirname(__DIR__, 2);
if (!is_file($docroot . '/app/etc/env.php')) { fwrite(STDERR, "ERRO: docroot invalido ($docroot)\n"); exit(2); }

$alarmar   = in_array('--alarmar', $argv, true);
$idadeMin  = 20;
foreach ($argv as $a) { if (preg_match('/^--idade-min=(\d+)$/', $a, $m)) { $idadeMin = (int) $m[1]; } }
$stateFile = $docroot . '/var/monitor-backlog.state.json';
$linhas = []; $alarmes = []; $falhas = [];

$env = include $docroot . '/app/etc/env.php';
$d = $env['db']['connection']['default'] ?? null;
if (!$d) { fwrite(STDERR, "ERRO: sem conexao default no env.php\n"); exit(2); }
$pdo = new PDO("mysql:host={$d['host']};dbname={$d['dbname']}", $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ---------- 1) consumers: lista DINAMICA ----------
$listaBruta = @shell_exec('php ' . escapeshellarg($docroot . '/bin/magento') . ' queue:consumers:list 2>/dev/null');
$consumers = array_values(array_filter(array_map('trim', explode("\n", (string) $listaBruta)), static fn($l) => $l !== ''));
$linhas[] = sprintf('consumers declarados: %d %s', count($consumers), $consumers === [] ? '(nao consegui listar - verificar)' : '');

// backlog por fila/topico (status=1 = pendente)
$porFila = [];
try {
    foreach ($pdo->query("SELECT q.name AS fila, COUNT(*) n, MIN(s.updated_at) mais_antigo
                          FROM queue_message_status s JOIN queue q ON q.id = s.queue_id
                          WHERE s.status = 1 GROUP BY q.name ORDER BY n DESC") as $r) {
        $porFila[$r['fila']] = ['n' => (int) $r['n'], 'mais_antigo' => $r['mais_antigo']];
    }
} catch (Throwable $e) { $linhas[] = 'AVISO: nao consegui ler queue_message_status: ' . substr($e->getMessage(), 0, 80); }

$totalConsumers = 0;
foreach ($porFila as $fila => $v) { $totalConsumers += $v['n']; $linhas[] = sprintf('  fila %-28s pendentes=%-6d mais_antigo=%s', $fila, $v['n'], substr((string) $v['mais_antigo'], 0, 16)); }
if ($porFila === []) { $linhas[] = '  (nenhuma fila com pendente)'; }

// ---------- 1b) PUBLICADAS SEM STATUS (o caso `product_alert`: 63 mensagens orfas) ----------
// Medido em 03/10/2026: 63 mensagens em `queue_message` (topico product_alert) e ZERO linhas em
// `queue_message_status` -> publicadas e nunca despachadas, INVISIVEIS para um monitor que so olha
// o backlog por status. Este bloco e o que faz o monitor enxergar o problema real.
$orfas = [];
try {
    // Sem filtro de tempo de proposito: `queue_message` nao tem coluna de created_at neste install
    // (medido: "Unknown column 'm.created_at'" ✗). E nao precisa: mensagem publicada SEM linha de
    // status significa que NENHUM consumer a pegou — presenca ja e o alarme (o caso dos 63 ✓).
    foreach ($pdo->query("SELECT m.topic_name AS topico, COUNT(*) n
                          FROM queue_message m
                          LEFT JOIN queue_message_status s ON s.message_id = m.id
                          WHERE s.message_id IS NULL
                          GROUP BY m.topic_name ORDER BY n DESC") as $r) {
        $orfas[$r['topico']] = ['n' => (int) $r['n'], 'mais_antigo' => null];
    }
} catch (Throwable $e) { $falhas[] = 'checagem de mensagens orfas: ' . substr($e->getMessage(), 0, 80); }
$totalOrfas = 0;
foreach ($orfas as $topico => $v) { $totalOrfas += $v['n']; $linhas[] = sprintf('  ORFA %-28s publicadas-sem-status=%-5d', $topico, $v['n']); }
if ($orfas === [] && $falhas === []) { $linhas[] = '  (nenhuma mensagem publicada-sem-status)'; }
// alarme dos orfaos movido para o bloco de CRESCIMENTO (abaixo): absoluto repetia a cada execucao

// ---------- 2) cron: atraso por job_code ----------
$cronOverdue = [];
try {
    $st = $pdo->prepare("SELECT job_code, COUNT(*) n, MIN(scheduled_at) mais_antigo
                         FROM cron_schedule
                         WHERE status = 'pending' AND scheduled_at < (NOW() - INTERVAL ? MINUTE)
                         GROUP BY job_code ORDER BY n DESC");
    $st->execute([$idadeMin]);
    foreach ($st as $r) { $cronOverdue[$r['job_code']] = ['n' => (int) $r['n'], 'mais_antigo' => $r['mais_antigo']]; }
} catch (Throwable $e) { $linhas[] = 'AVISO: nao consegui ler cron_schedule: ' . substr($e->getMessage(), 0, 80); }

$totalCron = 0;
foreach ($cronOverdue as $job => $v) { $totalCron += $v['n']; $linhas[] = sprintf('  cron %-46s vencidos=%-5d mais_antigo=%s', $job, $v['n'], substr((string) $v['mais_antigo'], 0, 16)); }
if ($cronOverdue === []) { $linhas[] = sprintf('  (nenhum job vencido ha mais de %d min)', $idadeMin); }

// ---------- 3) ALARME por CRESCIMENTO (nao por processo ausente) ----------
$atual = ['ts' => date('c'), 'consumers' => $totalConsumers, 'cron' => $totalCron, 'por_fila' => array_map(static fn($v) => $v['n'], $porFila), 'cron_por_job' => array_map(static fn($v) => $v['n'], $cronOverdue), 'orfas' => array_map(static fn($v) => $v['n'], $orfas)];
$anterior = null;
if (is_file($stateFile)) { $anterior = json_decode((string) file_get_contents($stateFile), true); }

if (is_array($anterior)) {
    if ($totalConsumers > (int) ($anterior['consumers'] ?? 0)) {
        $alarmes[] = sprintf('ALARME: backlog de consumers CRESCEU %d -> %d (antes %s)',
            (int) ($anterior['consumers'] ?? 0), $totalConsumers, substr((string) ($anterior['ts'] ?? '?'), 0, 16));
    }
    $antesOrfas = is_array($anterior['orfas'] ?? null) ? $anterior['orfas'] : null;
    foreach ($orfas as $topico => $v) {
        if ($antesOrfas === null || !array_key_exists($topico, $antesOrfas)) {
            $alarmes[] = sprintf('ALARME: orfao NOVO — %d mensagem(ns) de %s publicadas e SEM STATUS', $v['n'], $topico);
        } elseif ($v['n'] > (int) $antesOrfas[$topico]) {
            $alarmes[] = sprintf('ALARME: orfao CRESCEU — %s de %d para %d', $topico, (int) $antesOrfas[$topico], $v['n']);
        }
    }

    if ($totalCron > (int) ($anterior['cron'] ?? 0) + 5) {
        $alarmes[] = sprintf('ALARME: atraso de cron CRESCEU %d -> %d (antes %s)',
            (int) ($anterior['cron'] ?? 0), $totalCron, substr((string) ($anterior['ts'] ?? '?'), 0, 16));
    }
    foreach ($porFila as $fila => $v) {
        $antes = (int) ($anterior['por_fila'][$fila] ?? 0);
        if ($v['n'] > $antes + 10) { $alarmes[] = sprintf('ALARME: fila %s cresceu %d -> %d', $fila, $antes, $v['n']); }
    }
} else {
    $linhas[] = 'primeira execucao: estado inicial gravado (sem alarme)';
}

// ---------- saida ----------
echo '[' . date('d/m H:i') . "] monitor-backlog (acuMULO, nao liveness)\n";
foreach ($linhas as $l) { echo $l . "\n"; }
foreach ($alarmes as $a) { echo $a . "\n"; }
foreach ($falhas as $f) { $alarmes[] = 'NAO MEDIDO (falha de checagem): ' . $f; }
if ($alarmes === [] && $falhas === []) { echo "OK: sem acumulo anormal (sem pendente + sem processo = SAUDAVEL, nao alerta)\n"; }
if ($falhas !== []) { echo "REGRA: monitor que nao consegue medir NAO diz OK — corrija a checagem acima antes de confiar no silencio.\n"; }
@file_put_contents($stateFile, json_encode($atual));
exit(($alarmar && $alarmes !== []) || $falhas !== [] ? 1 : 0);
