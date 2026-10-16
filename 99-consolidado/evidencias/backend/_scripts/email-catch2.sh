#!/bin/bash
# task-42 — QUAL catch rodou? (define o impacto)
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) mensagens 'B2B ... error' em debug.log ==="
grep -h "B2B .*error" var/log/debug.log 2>/dev/null | tail -10 | cut -c1-260

echo
echo "=== 2) mensagens 'B2B' em debug.log (contexto) ==="
grep -h "B2B" var/log/debug.log 2>/dev/null | grep -v '^#' | tail -10 | cut -c1-260

echo
echo "=== 3) o que esta em debug.log na janela do erro (22:16) ==="
grep -n "2026-10-05T22:1[0-9]" var/log/debug.log 2>/dev/null | tail -10 | cut -c1-260

echo
echo "=== 4) exception.log: a linha do tempo completa ==="
grep -o '^\[2026-[^]]*\] [a-zA-Z]*\.[A-Z]*: [^ ]* [^ ]*' var/log/exception.log | tail -5

echo
echo "=== 5) existe algum 'B2B CustomerRegisterObserver error'? ==="
grep -rc "B2B CustomerRegisterObserver error" var/log/*.log 2>/dev/null | grep -v ':0'
echo "(vazio = nao ocorreu)"
grep -rc "B2B notifyAdminNewCustomer error" var/log/*.log 2>/dev/null | grep -v ':0'
echo "(vazio = nao ocorreu)"

echo
echo "=== 6) registros de novo cliente B2B pendente (o observer chegou ao fim?) ==="
grep -rh "registrado como pendente" var/log/*.log 2>/dev/null | tail -5 | cut -c1-200

echo
echo "=== 7) imagem do disco x tempo de execucao (opcache) ==="
ls -la --time-style=full-iso app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php app/code/GrupoAwamotos/B2B/Observer/CustomerRegisterObserver.php
