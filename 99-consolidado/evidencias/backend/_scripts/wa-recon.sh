#!/bin/bash
# task-67 — quem consome a fila do WhatsApp e o que acontece quando o consumer lanca.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem chama WhatsAppConsumer ==="
grep -rn 'WhatsAppConsumer' app/code --include=*.php | grep -v '/Test/'

echo
echo "=== 2) queue config do B2B ==="
for f in app/code/GrupoAwamotos/B2B/etc/queue_consumer.xml app/code/GrupoAwamotos/B2B/etc/queue_topology.xml app/code/GrupoAwamotos/B2B/etc/communication.xml; do
  echo "-- $f"
  cat "$f" 2>/dev/null | head -40
done

echo
echo "=== 3) onde esta WhatsAppMessageInterface / o consumer do MQ ==="
grep -rln 'WhatsAppMessageInterface' app/code/GrupoAwamotos --include=*.php | head

echo
echo "=== 4) o consumer do MQ (classe que o Magento roda) ==="
grep -rn 'consumer\|Concurrency' app/code/GrupoAwamotos/B2B/etc/queue_consumer.xml 2>/dev/null

echo
echo "=== 5) env.php: consumers configurados ==="
grep -n -A 3 'whatsapp' app/etc/env.php | head -30
