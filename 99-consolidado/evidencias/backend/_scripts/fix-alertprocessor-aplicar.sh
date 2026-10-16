#!/bin/bash
# task-74 — aplica, valida e SO ENTAO reseta o opcache.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TARGET="$ROOT/app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php"

echo "=== APPLY ==="
php /tmp/fix-alertprocessor.php --apply 2>&1 | tail -8
BAK=$(ls -1dt "$ROOT"/_backups/alert-window-* 2>/dev/null | head -1)
echo "backup: $BAK"

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT=$?
echo "lint_rc=$LINT"
if [ "$LINT" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cp -p "$BAK/AlertProcessor.php.orig" "$TARGET"
  sha256sum "$TARGET"
  exit 9
fi

echo
echo "=== opcache-reset (so agora) ==="
bash scripts/opcache-reset.sh

echo
echo "=== estado final ==="
sha256sum "$TARGET"
echo "construtores: $(grep -c 'function __construct' "$TARGET")"
echo "guarda occurrences: $(grep -c 'getOccurrences() <= 3' "$TARGET") (esperado 0)"
echo "cooldown intacto: $(grep -c 'NOTIFY_COOLDOWN_HOURS = 6' "$TARGET") (esperado 1)"
echo "isWithinNotificationCooldown: $(grep -c 'isWithinNotificationCooldown' "$TARGET") (esperado 2)"
echo "linha da guarda nova:"; grep -n "getStatus() === 'open'" "$TARGET"
