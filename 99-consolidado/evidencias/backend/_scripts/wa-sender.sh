#!/bin/bash
# task-67 — como o sender Z-API monta o retorno ['success' => ...].
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem implementa WhatsappSenderInterface ==="
grep -rln 'WhatsappSenderInterface' app/code/GrupoAwamotos/SmartSuggestions --include=*.php | head
grep -rn 'preference for="GrupoAwamotos\\SmartSuggestions\\Api\\WhatsappSenderInterface"' app/code --include=di.xml | head

echo
echo "=== 2) o adapter Z-API: metodo sendMessage ==="
F=$(grep -rln 'class.*implements WhatsappSenderInterface\|WhatsappSenderInterface$' app/code/GrupoAwamotos/SmartSuggestions --include=*.php | head -1)
echo "arquivo: $F"
grep -n "function sendMessage" -A 45 "$F" | head -60
