#!/bin/bash
# BUG B — onde exatamente o 1324 desaparece?
set -u
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'
B=https://awamotos.com

tenta () { # $1 label $2 url
  local BODY
  BODY=$(curl -sL -A "$UA" "$2")
  local n; n=$(printf '%s' "$BODY" | grep -o 'data-product-id="[0-9]*"' | sort -u | wc -l)
  local tem; printf '%s' "$BODY" | grep -q 'data-product-id="1324"' && tem=SIM || tem=NAO
  local amt; amt=$(printf '%s' "$BODY" | grep -o 'toolbar-amount[^>]*>[^<]*' | head -1 | sed 's/.*>//' | tr -d '\n')
  echo "  [$1] cards=$n | contem 1324? $tem | toolbar='$amt'"
  printf '%s' "$BODY" | grep -o 'data-product-id="[0-9]*"' | sort -u | tr '\n' ' ' | sed 's/^/      ids: /'
  echo
}

echo "=== onde o 1324 aparece / desaparece ==="
tenta "busca 0047 PR"                 "$B/ catalogsearch/result/?q=0047+PR" 2>/dev/null || true
tenta "busca 0047 PR"                 "$B/catalogsearch/result/?q=0047+PR"
tenta "busca 340 PR (sku dele)"       "$B/catalogsearch/result/?q=340+PR"
tenta "busca BAULETO PROOS 34"        "$B/catalogsearch/result/?q=BAULETO+PROOS+34"
tenta "busca 340"                     "$B/catalogsearch/result/?q=340"
tenta "categoria bauletos (PLP)"      "$B/bauletos.html"
tenta "0047 PR limite 5 p=1"          "$B/catalogsearch/result/?q=0047+PR&product_list_limit=5&p=1"
tenta "0047 PR limite 5 p=2"          "$B/catalogsearch/result/?q=0047+PR&product_list_limit=5&p=2"
tenta "0047 PR limite 3 p=4"          "$B/catalogsearch/result/?q=0047+PR&product_list_limit=3&p=4"
