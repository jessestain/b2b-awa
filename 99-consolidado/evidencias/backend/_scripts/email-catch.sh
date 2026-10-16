#!/bin/bash
# task-42 — o catch rodou? quem logou o CRITICAL? e o contexto real de area/tema.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) a mensagem do catch aparece em ALGUM log? ==="
grep -rl 'notifyAdminNewCustomer' var/log/ 2>/dev/null | head
echo "-- ocorrencias --"
grep -rh 'notifyAdminNewCustomer' var/log/*.log 2>/dev/null | tail -5
echo "(vazio = o catch NAO registrou nada)"

echo
echo "=== 2) CustomerRegisterObserver 85-120 ==="
sed -n '85,120p' app/code/GrupoAwamotos/B2B/Observer/CustomerRegisterObserver.php

echo
echo "=== 3) InvokerDefault: o que faz com excecao de observer ==="
sed -n '70,110p' vendor/magento/framework/Event/Invoker/InvokerDefault.php

echo
echo "=== 4) todos os eventos do log atual (o que mais aconteceu em 22:16) ==="
grep -c 'CRITICAL\|ERROR\|WARNING' var/log/exception.log
head -1 var/log/exception.log | cut -c1-160

echo
echo "=== 5) o log de 03/10: mesmo chamador? ==="
zgrep -h 'app/code/GrupoAwamotos' var/log/exception.log.2.gz 2>/dev/null | grep -o 'app/code/GrupoAwamotos[^(]*' | sort | uniq -c
