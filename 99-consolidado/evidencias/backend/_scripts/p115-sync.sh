#!/bin/bash
# task-115 — onde o codigo fala de CODPRODUTO / do sync de produtos.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) CODPRODUTO em app/code ==="
grep -rn 'CODPRODUTO' app/code --include=*.php | grep -v '/Test/' | head -20

echo
echo "=== 2) o cron SyncProducts: em quem delega ==="
ls app/code/GrupoAwamotos/ERPIntegration/Cron/ | head -20
echo "---"
cat app/code/GrupoAwamotos/ERPIntegration/Cron/SyncProducts.php 2>/dev/null | head -40

echo
echo "=== 3) classes de sync de produto ==="
grep -rln 'class.*ProductSync\|SyncProducts' app/code/GrupoAwamotos --include=*.php | grep -v '/Test/' | head
