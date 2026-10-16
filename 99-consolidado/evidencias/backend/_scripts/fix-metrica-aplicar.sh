#!/bin/bash
# task-55 — aplica a correcao da metrica, valida e SO ENTAO reseta o opcache.
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cd "$ROOT"
TARGET="$ROOT/app/code/GrupoAwamotos/ERPIntegration/Model/Api/CustomerPullManagement.php"

echo "=== APPLY ==="
php /tmp/fix-metrica.php --apply
BAK=$(ls -1dt "$ROOT"/_backups/erp-map-reconcile-* 2>/dev/null | head -1)
echo "backup: $BAK"

echo
echo "=== php -l NO ALVO ==="
php -l "$TARGET"
LINT=$?
echo "lint_rc=$LINT"
if [ "$LINT" -ne 0 ]; then
  echo "!!! LINT FALHOU — RESTAURANDO (sem opcache reset) !!!"
  cp -p "$BAK/CustomerPullManagement.php.orig" "$TARGET"
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
echo "mapa ainda usado nesta query? $(grep -c 'grupoawamotos_erp_entity_map' "$TARGET")"
echo
echo "=== os 2 arquivos alterados na task-55 ==="
sha256sum "$ROOT/app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php" "$TARGET"
echo
echo "=== backups ==="
ls -1dt "$ROOT"/_backups/erp-map-reconcile-* | head -3
