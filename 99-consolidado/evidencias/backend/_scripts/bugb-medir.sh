#!/bin/bash
# BUG B — 0047 PR: toolbar anuncia 10, renderiza 9. Medicao + variacoes.
set -u
B=https://awamotos.com/catalogsearch/result
php /tmp/medir-busca.php "$B/?q=0047+PR"      "B1 0047 PR (espaco +)"
php /tmp/medir-busca.php "$B/?q=0047%20PR"    "B2 0047 PR (%20)"
php /tmp/medir-busca.php "$B/?q=0047"         "B3 so 0047"
php /tmp/medir-busca.php "$B/?q=PR"           "B4 so PR"
php /tmp/medir-busca.php "$B/?q=0047+PR&p=1"  "B5 0047 PR pagina 1 explicita"
php /tmp/medir-busca.php "$B/?q=0047+PR&cb=991" "B6 + cache-buster"
php /tmp/medir-busca.php "$B/?q=0047+PT"      "B7 0047 PT (controle)"
