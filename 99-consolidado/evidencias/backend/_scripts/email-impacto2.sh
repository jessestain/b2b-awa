#!/bin/bash
# task-42 (C) — impacto: quantas notificacoes falharam vs quantos cadastros ocorreram.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) cadastros B2B pendentes registrados (o observer chegou ao fim) ==="
grep -rh "registrado como pendente" var/log/debug.log 2>/dev/null | wc -l
grep -rh "registrado como pendente" var/log/debug.log 2>/dev/null | tail -3 | cut -c1-150

echo
echo "=== 2) falhas de header.html (log atual + rotacionados) ==="
for f in var/log/exception.log var/log/exception.log.1 var/log/exception.log.2.gz var/log/exception.log.3.gz; do
  [ -e "$f" ] || continue
  c=$(zgrep -c "header.html' is not found" "$f" 2>/dev/null || grep -c "header.html' is not found" "$f" 2>/dev/null || echo 0)
  echo "  $f -> $c"
done

echo
echo "=== 3) as 2 entradas do log atual, com horario ==="
grep -o '^\[2026-[^]]*\].\{0,120\}' var/log/exception.log

echo
echo "=== 4) o cadastro B2B em si quebrou? (erros do observer) ==="
grep -rh "B2B CustomerRegisterObserver error\|B2B Registration Error" var/log/debug.log 2>/dev/null | tail -3 | cut -c1-200

echo
echo "=== 5) o template usado pelo observador (declarado no XML) ==="
sed -n '10,17p' app/code/GrupoAwamotos/B2B/etc/email_templates.xml

echo
echo "=== 6) arquivo do template existe? ==="
ls -la app/code/GrupoAwamotos/B2B/view/frontend/email/admin_new_customer.html
