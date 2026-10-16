#!/bin/bash
# BUG B — o produto 1324 esta no HTML servido? e o que mais difere?
set -u
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'
B=https://awamotos.com/catalogsearch/result

echo "=== A) o HTML servido contem o produto 1324? ==="
BODY=$(curl -sL -A "$UA" "$B/?q=0047+PR")
for pat in '1324' '340-pr' '340 PR' 'BAULETO AWA MODELO PROOS 34'; do
  echo -n "  '$pat' -> "
  printf '%s' "$BODY" | grep -c -- "$pat" || true
done

echo
echo "  ocorrencias de data-product-id:"
printf '%s' "$BODY" | grep -o 'data-product-id="[0-9]*"' | sort | uniq -c | sed 's/^/    /'

echo
echo "  hrefs que contem '-pr.html':"
printf '%s' "$BODY" | grep -o 'href="[^"]*-pr\.html"' | sort -u | sed 's/^/    /'

echo
echo "=== B) o item 1324 aparece como <li class=...item-product> ? ==="
printf '%s' "$BODY" | grep -o '<li[^>]*item-product[^>]*>' | sed 's/^/  /' | head -15

echo
echo "=== C) contagem por marcador ==="
for m in 'class="item-product' 'product-item-link' 'product-image-container' 'data-product-id'; do
  echo "  $m = $(printf '%s' "$BODY" | grep -o "$m" | wc -l)"
done

echo
echo "=== D) ultimas linhas do bugb-diff (attribute_set e int por produto) ==="
php /tmp/bugb-diff.php 2>&1 | tail -30
