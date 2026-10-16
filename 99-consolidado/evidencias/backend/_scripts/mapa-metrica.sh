#!/bin/bash
# task-55 — quem usa o mapa como metrica de "pedido no ERP".
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) usos de grupoawamotos_erp_entity_map no app/code ==="
grep -rn 'grupoawamotos_erp_entity_map' app/code --include=*.php | grep -v '/Test/' | head -30

echo
echo "=== 2) usos com entity_type order ==="
grep -rn "entity_type.*=.*'order'\|entity_type = 'order'" app/code --include=*.php | grep -v '/Test/' | head -20

echo
echo "=== 3) a view vw_sectra_pedidos_sincronizados e usada no codigo? ==="
grep -rn 'vw_sectra_pedidos_sincronizados' app/code --include=*.php | head -10
echo "(fim)"

echo
echo "=== 4) relatorios/exportadores que contam pedidos ==="
grep -rln 'SectraKpiProvider\|OrderKpiProvider\|ExecutiveDashboard' app/code --include=*.php | head -10

echo
echo "=== 5) ConnectionInterface ==="
grep -n 'function\|interface ' app/code/GrupoAwamotos/ERPIntegration/Api/ConnectionInterface.php

echo
echo "=== 6) getErpCodeByMagentoId / getMagentoIdByErpCode (SyncLog) ==="
grep -n 'function get' app/code/GrupoAwamotos/ERPIntegration/Model/ResourceModel/SyncLog.php
