#!/bin/bash
# task-115 — como o PROPRIO modulo le produtos do ERP (o caminho comprovado).
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) SQL com PRODUTO no modulo ==="
grep -rn 'PRODUTO' app/code/GrupoAwamotos/ERPIntegration --include=*.php | grep -iv 'codproduto_produto' | head -25

echo
echo "=== 2) arquivos que falam de produto ==="
grep -rln 'PRODUTO\|CODPRODUTO' app/code/GrupoAwamotos/ERPIntegration --include=*.php | head -12
