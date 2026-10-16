#!/bin/bash
# task-42 — inicio do stack trace (quem dispara o e-mail).
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== primeiros 7000 caracteres da entrada 1 do exception.log ==="
head -1 var/log/exception.log | cut -c1-7000

echo
echo
echo "=== entrada antiga (03/10) em exception.log.2.gz ==="
zgrep -h "is not found" var/log/exception.log.2.gz | head -1 | cut -c1-3000

echo
echo
echo "=== quantas entradas de log distintas (linhas) com o erro ==="
grep -c "is not found" var/log/exception.log
zgrep -c "is not found" var/log/exception.log.2.gz
