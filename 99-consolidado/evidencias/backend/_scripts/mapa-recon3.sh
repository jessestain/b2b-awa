#!/bin/bash
# task-55 — os metodos que marcam imported sem o mapa.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud
F=app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php

echo "=== 1) isOrderAlreadyImported ==="
sed -n '/private function isOrderAlreadyImported/,/^    }/p' "$F"

echo
echo "=== 2) setOrderImportStatus ==="
sed -n '/private function setOrderImportStatus/,/^    }/p' "$F"

echo
echo "=== 3) quem chama backfillOrderImportStatus ==="
grep -rn 'backfillOrderImportStatus' app/code --include=*.php | grep -v '/Test/'

echo
echo "=== 4) quem chama isOrderAlreadyImported ==="
grep -n 'isOrderAlreadyImported' "$F"

echo
echo "=== 5) o resto do reconcile (450-520) ==="
sed -n '450,520p' "$F"

echo
echo "=== 6) lista de metodos do arquivo (com linha) ==="
grep -n 'function ' "$F"
