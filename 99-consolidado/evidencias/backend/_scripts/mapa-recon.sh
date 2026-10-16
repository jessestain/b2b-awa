#!/bin/bash
# task-55 — reconhecimento: quem grava 'imported' e quem grava o mapa.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem escreve sectra_import_status / imported ==="
grep -rn "setSectraImportStatus\|sectra_import_status" app/code/GrupoAwamotos/ERPIntegration app/code/GrupoAwamotos/B2B --include=*.php | grep -v '/Test/' | head -30

echo
echo "=== 2) onde a string 'imported' aparece ==="
grep -rn "'imported'" app/code/GrupoAwamotos/ERPIntegration app/code/GrupoAwamotos/B2B --include=*.php | grep -v '/Test/' | head -30

echo
echo "=== 3) setEntityMap: definicao e chamadas ==="
grep -rn "function setEntityMap" app/code/GrupoAwamotos/ERPIntegration --include=*.php | head
grep -rn "setEntityMap(" app/code/GrupoAwamotos/ERPIntegration app/code/GrupoAwamotos/B2B --include=*.php | grep -v '/Test/' | head -20

echo
echo "=== 4) arquivos candidatos ==="
ls -la app/code/GrupoAwamotos/ERPIntegration/Model/Api/ 2>/dev/null | head -20
