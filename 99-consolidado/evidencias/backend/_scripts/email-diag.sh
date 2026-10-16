#!/bin/bash
# task-42 — regra de fallback do e-mail + entradas do log.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) createEmailTemplateFileRule ==="
sed -n '/function createEmailTemplateFileRule/,/^    }/p' vendor/magento/framework/View/Design/Fallback/RulePool.php

echo
echo "=== 2) ocorrencias no exception.log atual ==="
grep -n 'header' var/log/exception.log | head -20

echo
echo "=== 3) contexto da ultima ocorrencia (25 linhas antes / 30 depois) ==="
N=$(grep -n "is not found" var/log/exception.log | tail -1 | cut -d: -f1)
if [ -n "${N:-}" ]; then
  S=$((N-25)); [ $S -lt 1 ] && S=1
  sed -n "${S},$((N+30))p" var/log/exception.log
else
  echo "(nenhuma ocorrencia de 'is not found' no log atual)"
fi

echo
echo "=== 4) todos os logs rotacionados com o erro ==="
for f in var/log/exception.log*; do
  c=$(zgrep -c "is not found" "$f" 2>/dev/null || grep -c "is not found" "$f" 2>/dev/null || echo 0)
  echo "  $f -> $c"
done

echo
echo "=== 5) onde estao os arquivos de e-mail (todos) ==="
find vendor/magento/module-email/view app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Email app/code/GrupoAwamotos/SalesIntelligence/view -name '*.html' -path '*email*' 2>/dev/null
