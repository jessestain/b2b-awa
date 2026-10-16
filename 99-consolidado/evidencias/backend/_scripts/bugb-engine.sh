#!/bin/bash
# BUG B — o que o engine devolve x o que a grade renderiza.
set -u
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'
B=https://awamotos.com/catalogsearch/result

echo "=== 1) indices de produto no OpenSearch ==="
curl -s "http://127.0.0.1:9201/_cat/indices?h=index,docs.count" | grep -i product

echo
echo "=== 2) quantos docs o engine devolve para '0047 PR' ==="
for IDX in $(curl -s "http://127.0.0.1:9201/_cat/indices?h=index" | grep -E '^magento2_product_1_v'); do
  N=$(curl -s -XPOST "http://127.0.0.1:9201/$IDX/_search" -H 'Content-Type: application/json' \
      -d '{"size":0,"query":{"query_string":{"query":"0047 PR"}}}' | grep -o '"value":[0-9]*' | head -1)
  echo "  $IDX -> total $N"
done

echo
echo "=== 3) IDs de produto que a PAGINA renderiza (0047 PR) ==="
BODY=$(curl -sL -A "$UA" "$B/?q=0047+PR")
echo -n "  data-product-id encontrados: "
printf '%s' "$BODY" | grep -o 'data-product-id="[0-9]*"' | grep -o '[0-9]*' | sort -u | tr '\n' ' '
echo
echo -n "  hrefs de produto (url key): "
printf '%s' "$BODY" | grep -o 'class="product-item-link"[^>]*href="[^"]*"' | sed 's/.*href="//;s/"//' | sort -u | head -12 | tr '\n' ' '
echo
echo -n "  toolbar-amount: "
printf '%s' "$BODY" | grep -o '<span class="toolbar-amount">[^<]*' | head -1
echo
echo "  ocorrencias de 'item-product': $(printf '%s' "$BODY" | grep -o 'class="[^"]*item-product[^"]*"' | wc -l)"
echo "  ocorrencias de product-item-link: $(printf '%s' "$BODY" | grep -o 'class="product-item-link"' | wc -l)"
