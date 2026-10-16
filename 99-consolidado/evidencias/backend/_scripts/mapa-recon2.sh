#!/bin/bash
# task-55 — localizar o caminho de RECONCILIACAO.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) arquivos OrderImportGate ==="
find app/code -name 'OrderImportGate*.php' 2>/dev/null

echo
echo "=== 2) reconcileOrdersAlreadyInErp ==="
grep -rn 'reconcileOrdersAlreadyInErp' app/code --include=*.php 2>/dev/null

echo
echo "=== 3) quem grava IMPORTED / 'imported' no Model ==="
grep -rn "IMPORTED" app/code/GrupoAwamotos/ERPIntegration/Model --include=*.php 2>/dev/null | head -20

echo
echo "=== 4) SyncLog::setEntityMap (definicao) ==="
sed -n '80,140p' app/code/GrupoAwamotos/ERPIntegration/Model/ResourceModel/SyncLog.php

echo
echo "=== 5) OrderPullManagement 195-250 (o caminho do ACK que grava o mapa) ==="
sed -n '195,250p' app/code/GrupoAwamotos/ERPIntegration/Model/Api/OrderPullManagement.php
