#!/bin/bash
# BUG A — medicao limpa. Uso: bash buga-medir.sh
set -u
B=https://awamotos.com/catalogsearch/result
php /tmp/medir-busca.php "$B/?q=pneu"                          "A1 pneu CRU"
php /tmp/medir-busca.php "$B/?q=pneu&cb=7781"                  "A2 pneu + cache-buster"
php /tmp/medir-busca.php "$B/?q=pret"                          "A3 pret (destino do redirect)"
php /tmp/medir-busca.php "$B/?q=relacao"                       "A4 relacao CRU"
php /tmp/medir-busca.php "$B/?q=relacao&cb=7782"               "A5 relacao + cache-buster"
php /tmp/medir-busca.php "$B/?q=remocao"                       "A6 remocao (destino)"
php /tmp/medir-busca.php "$B/?q=zzzznaoexiste"                 "A7 0 resultado SEM sugestao"
php /tmp/medir-busca.php "$B/?q=zzzznaoexiste&cb=7783"         "A8 0 resultado + cache-buster"
php /tmp/medir-busca.php "$B/?q=relacao-de-transmissao"        "A9 relacao-de-transmissao"
