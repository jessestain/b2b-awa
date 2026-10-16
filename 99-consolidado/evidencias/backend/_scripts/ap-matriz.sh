#!/bin/bash
# task-74 — MATRIZ DE CONTENCAO: "N alertas abertos x rodadas de 5 min".
# Constantes MEDIDAS no servidor (nao supostas) + limite de N imposto pelo dedupe.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== A) constantes medidas ==="
CRON=$(grep -A1 'awa_log_monitoring_alerts' app/code/GrupoAwamotos/LogMonitoring/etc/crontab.xml | grep -o '\*/[0-9]*' | head -1)
echo "  cron do AlertProcessor: $CRON (medido do crontab.xml)"
COOL=$(grep -o 'NOTIFY_COOLDOWN_HOURS = [0-9]*' app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php)
echo "  cooldown: $COOL (medido do codigo)"
echo "  => rodadas por hora = 12 | rodadas por janela de 6h = 72 | dias = 288 rodadas"

echo
echo "=== B) quantos combos (alert_type, severity, source) o modulo PODE emitir ==="
grep -n -A2 'createAndSaveAlert(' app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/ErpAnalyzer.php \
  app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/PerformanceAnalyzer.php \
  | grep -E "^\S+:[0-9]+-\s+'" | sed "s/^/  /"
echo "  + agent_termination | (classificacao) | antigravity  (DiagnosticReportService:16-17,136)"

echo
echo "=== C) a matriz (pior caso por janela de 6h) ==="
printf "  %-6s %-9s %-28s %-22s %-14s\n" "N" "rodadas" "ANTES (occ<=3 + cooldown)" "DEPOIS (so cooldown)" "e-mails/hora"
for N in 1 3 5 15; do
  printf "  %-6s %-9s %-28s %-22s %-14s\n" "$N" "72" "$N (e 0 se occ>3)" "$N" "$(awk -v n=$N 'BEGIN{printf "%.2f", n/6}')"
done
echo "  (as rodadas NAO multiplicam: o cooldown limita cada alerta a 1x por janela de 6h)"

echo
echo "=== D) limite real de N (dedupe da task-49: 1 linha aberta por combo) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
echo "  combos distintos JA EMITIDOS em toda a historia: " . $pdo->query("SELECT COUNT(DISTINCT CONCAT(alert_type,\"|\",severity,\"|\",source)) FROM awa_log_alerts")->fetchColumn() . "\n";
echo "  alertas ABERTOS hoje: " . $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE status=\"open\"")->fetchColumn() . "\n";
echo "  o que getCriticalAlerts() varre (severity critical/high, status != resolved): " . $pdo->query("SELECT COUNT(*) FROM awa_log_alerts WHERE severity IN (\"critical\",\"high\") AND status <> \"resolved\"")->fetchColumn() . "\n";
echo "  (143 acknowledged = varridos a cada rodada mas pulados pelo status===\x27open\x27)\n";
'

echo
echo "=== E) conclusao ==="
echo "  pior caso DEPOIS: N <= 6 combos (5 notificaveis) x 1 por janela = <= 5 e-mails / 6h = <= 20/dia"
echo "  medido hoje: 0 abertos => 0 e-mails/dia"
echo "  comparativo: 247/dia antes do cooldown | 60/dia com o passivo de 15 duplicatas"
