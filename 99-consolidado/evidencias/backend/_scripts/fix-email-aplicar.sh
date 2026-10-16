#!/bin/bash
# task-42 — aplica, valida com php -l e SO ENTAO reseta o opcache.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TARGET="$ROOT/app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php"

echo "=== APPLY ==="
php /tmp/fix-email-area.php --apply
BAK=$(php -r '$d=glob("/home/jessessh/htdocs/srv1113343.hstgr.cloud/_backups/email-header-*/CustomerApproval.php.orig"); rsort($d); echo $d[0] ?? "";')
echo "backup usado: $BAK"

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT=$?
echo "lint_rc=$LINT"
if [ "$LINT" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cat "$BAK" > "$TARGET"
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
grep -n -B1 -A1 "AREA_FRONTEND, // task-42" "$TARGET"

echo
echo "=== ainda existe AREA_ADMINHTML no arquivo? ==="
grep -c 'AREA_ADMINHTML' "$TARGET" || true
