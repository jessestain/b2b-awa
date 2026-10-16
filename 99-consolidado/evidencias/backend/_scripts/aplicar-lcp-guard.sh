#!/bin/bash
# Aplica o guard e valida. Se o php -l falhar, RESTAURA do backup e para.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
TARGET="$ROOT/app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php"
BAK_DRYRUN="$ROOT/_backups/cls-lcp-guard-20261005T201658Z/OptimizeHeadStylesPlugin.php.orig"

echo "=== sha256 ANTES ==="
sha256sum "$TARGET"

echo
echo "=== APPLY ==="
php /tmp/apply-lcp-guard.php --apply
APPLY_RC=$?

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT_RC=$?
echo "lint_rc=$LINT_RC"

if [ "$LINT_RC" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cat "$BAK_DRYRUN" > "$TARGET"
  sha256sum "$TARGET"
  echo "RESTAURADO. Abortando."
  exit 9
fi

echo
echo "=== sha256 DEPOIS ==="
sha256sum "$TARGET"
ls -la "$TARGET"
echo
echo "=== OS ULTIMOS BACKUPS ==="
ls -1dt "$ROOT"/_backups/cls-lcp-guard-* | head -5
echo
echo "apply_rc=$APPLY_RC lint_rc=$LINT_RC"
