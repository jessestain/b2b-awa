#!/bin/bash
# BUG A — o que o cliente REALMENTE ve em cada estado. Somente leitura.
set -u
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'
B=https://awamotos.com

show () { # $1=label $2=url
  local label="$1" url="$2"
  echo "########## $label"
  echo "URL: $url"
  local body; body=$(curl -sL -A "$UA" "$url")
  local bytes; bytes=$(printf '%s' "$body" | wc -c)
  local cards; cards=$(printf '%s' "$body" | grep -o 'class="product-item-link"' | wc -l)
  local amount; amount=$(printf '%s' "$body" | grep -o 'toolbar-amount[^<]*' | head -1)
  echo "bytes=$bytes | product-item-link=$cards | $amount"
  echo "-- frases-chave --"
  printf '%s' "$body" | grep -o -i -E 'nenhum resultado[^<]{0,90}|não retornou[^<]{0,90}|nao retornou[^<]{0,90}|quis dizer[^<]{0,90}|resultados para[^<]{0,90}|sua busca[^<]{0,120}|resultado para[^<]{0,90}' | sort -u | head -12
  echo "-- ha link de sugestao (o= ou f=)? --"
  printf '%s' "$body" | grep -o -E 'catalogsearch/result/[^"'"'"']*[?&][of]=[^"'"'"'&]*[^"'"'"']*' | sort -u | head -6
  echo
}

show "1) 0 resultado SEM correcao (zzzznaoexiste)"        "$B/catalogsearch/result/?q=zzzznaoexiste"
show "2) 0 resultado com ?o= (params de misspell na mao)" "$B/catalogsearch/result/?q=zzzznaoexiste&o=zzzznaoexiste"
show "3) 0 resultado com ?f= (params de fallback na mao)" "$B/catalogsearch/result/?q=zzzznaoexiste&f=zzzznaoexiste"
show "4) pagina REDIRECIONADA de pneu (o que o cliente ve hoje)" "$B/catalogsearch/result/?_q=pneu&o=pneu&q=pret"
show "5) pneu cru (sem redirect) = 0 resultados"          "$B/catalogsearch/result/?q=pneu&_q=pneu"
