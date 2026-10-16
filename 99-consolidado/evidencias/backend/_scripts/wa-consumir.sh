#!/bin/bash
# task-67 — PARTE 2: consumir a mensagem de teste (id 6650) e provar status ERROR (6) em vez de COMPLETE (4).
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

ler_status () {
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
$r = $pdo->query("SELECT qms.status, qms.number_of_trials FROM queue_message_status qms WHERE qms.id = 6586")->fetch(PDO::FETCH_ASSOC);
$n = $pdo->query("SELECT qms.status, COUNT(*) c FROM queue_message_status qms JOIN queue q ON q.id=qms.queue_id WHERE q.name=\"grupoawamotos.b2b.whatsapp.send\" GROUP BY qms.status")->fetchAll(PDO::FETCH_ASSOC);
echo "  status da mensagem 6650: " . ($r ? "status={$r["status"]} trials={$r["number_of_trials"]}" : "(nao encontrada)") . "\n";
echo "  distribuicao da fila: ";
foreach ($n as $x) { echo "status={$x["status"]}:{$x["c"]} "; }
echo "\n";
'
}

echo "=== ANTES de consumir ==="
ler_status

T0=$(stat -c %s var/log/debug.log 2>/dev/null || echo 0)
echo "  debug.log = $T0 bytes"

echo
echo "=== consumindo (queue:consumers:start --max-messages=1) ==="
timeout 90 php bin/magento queue:consumers:start grupoawamotos.b2b.whatsapp.consumer --max-messages=1 2>&1 | tail -5
echo "rc=$?"

echo
echo "=== DEPOIS de consumir ==="
ler_status

echo
echo "=== log NOVO relevante ==="
T1=$(stat -c %s var/log/debug.log 2>/dev/null || echo 0)
echo "  debug.log: $T0 -> $T1"
if [ "$T1" -gt "$T0" ]; then
  tail -c $((T1 - T0)) var/log/debug.log | grep -i 'whatsapp\|rejected\|recusado\|Invalid phone' | head -10 | cut -c1-220
fi
