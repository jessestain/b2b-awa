#!/bin/bash
# task-115 — a reativacao existe no codigo mas alguem chama?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) qualquer 'eactiv' em app/code (metodos, chamadas, CLI) ==="
grep -rn 'eactivate\|eactivation\|reativ' app/code/GrupoAwamotos --include=*.php | grep -v '/Test/' | head -30

echo
echo "=== 2) a definicao do metodo no ProductSync ==="
grep -n 'function reactivate\|function reconcile\|function syncDelta\|function ' app/code/GrupoAwamotos/ERPIntegration/Model/ProductSync.php | grep -i 'reactiv\|reconcil\|delta' | head

echo
echo "=== 3) productSync->: quem chama o que ==="
grep -rn 'productSync->' app/code/GrupoAwamotos --include=*.php | grep -v '/Test/' | head -15

echo
echo "=== 4) o tools/julgar-reativacao.php existe? ==="
ls -la tools/julgar-reativacao.php 2>/dev/null || echo "  (nao existe em tools/)"
ls tools/ 2>/dev/null | head -20

echo
echo "=== 5) logs: a reativacao ja rodou alguma vez? ==="
grep -rh 'eactiv' var/log/*.log 2>/dev/null | tail -8 | cut -c1-200
echo "--- contagem ---"
for f in var/log/erp_integration.log var/log/debug.log var/log/system.log; do
  [ -e "$f" ] || continue
  echo "  $f: $(grep -c 'eactiv' "$f" 2>/dev/null || echo 0)"
done
