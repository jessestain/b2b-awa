#!/bin/bash
# task-55 — aplica a correcao do produtor, valida e SO ENTAO reseta o opcache.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TARGET="$ROOT/app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php"

echo "=== APPLY ==="
php /tmp/fix-mapa.php --apply
BAK=$(ls -1dt "$ROOT"/_backups/erp-map-reconcile-* 2>/dev/null | head -1)
echo "backup: $BAK"

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT=$?
echo "lint_rc=$LINT"
if [ "$LINT" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cp -p "$BAK/OrderImportGate.php.orig" "$TARGET"
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
echo "metodo novo presente: $(grep -c 'private function syncOrderEntityMap' "$TARGET")"
echo "chamada presente: $(grep -c 'syncOrderEntityMap(\$importedOrderIds)' "$TARGET")"
