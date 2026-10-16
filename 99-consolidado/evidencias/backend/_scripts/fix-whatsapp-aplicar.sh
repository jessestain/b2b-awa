#!/bin/bash
# task-67 — aplica a correcao do consumer, valida e SO ENTAO reseta o opcache.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TARGET="$ROOT/app/code/GrupoAwamotos/B2B/Model/Notification/WhatsAppConsumer.php"

echo "=== APPLY ==="
php /tmp/fix-whatsapp.php --apply
BAK=$(ls -1dt "$ROOT"/_backups/whatsapp-success-* 2>/dev/null | head -1)
echo "backup: $BAK"

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT=$?
echo "lint_rc=$LINT"
if [ "$LINT" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cp -p "$BAK/WhatsAppConsumer.php.orig" "$TARGET"
  sha256sum "$TARGET"
  exit 9
fi

echo
echo "=== opcache-reset (so agora) ==="
bash scripts/opcache-reset.sh

echo
echo "=== estado final ==="
sha256sum "$TARGET"
ls -la "$TARGET"
echo "construtores: $(grep -c 'function __construct' "$TARGET")"
echo "sendOrFail definido: $(grep -c 'private function sendOrFail' "$TARGET")"
echo "sendOrFail chamado: $(grep -c 'this->sendOrFail(' "$TARGET")"
echo "contarEnvios definido: $(grep -c 'private function contarEnvios' "$TARGET")"
echo "throw no catch: $(grep -c 'throw \$e instanceof' "$TARGET")"
echo
echo "=== a fila NAO foi tocada (28 COMPLETE) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT qms.status, COUNT(*) n, SUM(qms.number_of_trials) t FROM queue_message_status qms JOIN queue q ON q.id=qms.queue_id WHERE q.name=\"grupoawamotos.b2b.whatsapp.send\" GROUP BY qms.status") as $r) {
  echo "  status={$r["status"]} n={$r["n"]} trials={$r["t"]}\n";
}
'
