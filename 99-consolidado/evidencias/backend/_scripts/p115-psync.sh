#!/bin/bash
# task-115 — o SQL de produtos do ProductSync (o caminho comprovado do modulo).
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

F=$(grep -rl 'implements ProductSyncInterface' app/code/GrupoAwamotos --include=*.php | head -1)
echo "implementacao: $F"
echo
echo "=== SQL do sync de produtos (FROM/JOIN/WHERE) ==="
grep -n -i 'FROM \|JOIN \|WHERE \|SELECT ' "$F" | head -40

echo
echo "=== colunas de status/ativo/exclusao mencionadas ==="
grep -n -i 'status\|ativo\|inativo\|situacao\|exclu\|DESATIV\|BLOQUE' "$F" | head -25
