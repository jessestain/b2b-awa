#!/bin/bash
# task-72 — como o unique constraint colide + o 3o caminho deduplica?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem grava metrica ==="
grep -rn 'function saveMetric\|saveMetrics\|setCreatedAt\|setLogType' app/code/GrupoAwamotos/LogMonitoring/Model/LogMetricsRepository.php app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/*.php 2>/dev/null | head -20

echo
echo "=== 2) LogMetricsRepository: gravacao ==="
grep -n -A 25 'public function saveMetrics\|public function save(' app/code/GrupoAwamotos/LogMonitoring/Model/LogMetricsRepository.php | head -40

echo
echo "=== 3) quantas linhas por (log_type, source_file, created_at) existem hoje ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
echo "  total de linhas: " . $pdo->query("SELECT COUNT(*) FROM awa_log_metrics")->fetchColumn() . "\n";
foreach ($pdo->query("SELECT log_type, source_file, COUNT(*) n, MIN(created_at) min_c, MAX(created_at) max_c
                      FROM awa_log_metrics GROUP BY log_type, source_file ORDER BY n DESC LIMIT 8") as $r) {
  echo "    {$r["log_type"]} | {$r["source_file"]} | n={$r["n"]} | {$r["min_c"]} .. {$r["max_c"]}\n";
}
echo "\n  gravacoes no MESMO segundo (o que a constraint proibe):\n";
foreach ($pdo->query("SELECT log_type, source_file, created_at, COUNT(*) n FROM awa_log_metrics
                      GROUP BY log_type, source_file, created_at HAVING n > 1 LIMIT 5") as $r) {
  echo "    {$r["log_type"]} | {$r["source_file"]} | {$r["created_at"]} | n={$r["n"]}\n";
}
echo "  (vazio = a constraint esta de pe; e justamente por isso os 5 INSERTs falharam)\n";
'

echo
echo "=== 4) DiagnosticReportService::findMatchingOpenAlert ==="
grep -n -A 30 'private function findMatchingOpenAlert' app/code/GrupoAwamotos/LogMonitoring/Service/AgentTermination/DiagnosticReportService.php | head -40
