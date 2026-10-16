#!/bin/bash
# Gera a evidencia bruta do guard CLS/LCP (lead, opcao B).
set -u
ROOT=/home/jessessh/htdocs/srv1113343.hstgr.cloud
OUT="$ROOT/99-consolidado/evidencias/backend/EV-LCP-guard.txt"
TARGET="$ROOT/app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php"

{
echo "############ GUARD CLS/LCP — evidencia bruta (05/10/2026) ############"
echo
echo "=== 1) ARQUIVO IMPLANTADO ==="
sha256sum "$TARGET"
ls -la "$TARGET"
echo
echo "--- linhas 7358-7366 (o guard) ---"
sed -n '7358,7366p' "$TARGET"
echo
echo "=== 2) BACKUP ==="
for d in "$ROOT"/_backups/cls-lcp-guard-*; do
  echo "-- $d"
  ls -la "$d"
  cat "$d/ANTES.txt" 2>/dev/null
  sha256sum "$d/OptimizeHeadStylesPlugin.php.orig" 2>/dev/null
done
echo
echo "=== 3) 'design-system' esta no homeGateFragments? ==="
sed -n "/'homeGate' => \[/,/\],/p" "$ROOT/app/code/GrupoAwamotos/Theme/Model/CascadeAssetManifest.php" | grep -n 'design-system' || echo "(nao esta no homeGate — o design-system nao passa pelo gateHomeLargeStylesheets)"
echo
echo "=== 4) PASSO 5 DO PROTOCOLO: curl -sI https://awamotos.com/ ==="
curl -sI https://awamotos.com/
echo
echo "=== 4b) tag <link id=\"awa-design-system-home\"> no HTML SERVIDO (3 fetchs) ==="
for n in 1 2 3; do
  BODY=$(curl -sL "https://awamotos.com/?cb=$RANDOM$n")
  TAG=$(printf '%s' "$BODY" | grep -o '<link[^>]*awa-design-system-home[^>]*>' | head -1)
  echo "--- fetch $n ---"
  echo "$TAG"
  printf '  media=? '; printf '%s' "$TAG" | grep -q 'media=' && echo -n 'SIM' || echo -n 'NAO'
  printf ' | onload=? '; printf '%s' "$TAG" | grep -q 'onload' && echo -n 'SIM' || echo -n 'NAO'
  printf ' | data-awa-defer no tag = '; printf '%s' "$TAG" | grep -o 'data-awa-defer' | wc -l
  sleep 2
done
echo
echo "=== 5) TESTE UNITARIO DO GUARD (extraido do arquivo implantado) ==="
php /tmp/test-lcp-guard.php
echo
echo "=== 6) TOTAL de data-awa-defer na home (o deferidor continua ativo?) ==="
BODY=$(curl -sL "https://awamotos.com/?cb=$RANDOM")
echo "data-awa-defer=\"catalog-noncritical\" = $(printf '%s' "$BODY" | grep -o 'data-awa-defer="catalog-noncritical"' | wc -l)"
echo "data-awa-defer=\"home-lcp\"            = $(printf '%s' "$BODY" | grep -o 'data-awa-defer="home-lcp"' | wc -l)"
echo "media=\"print\"                         = $(printf '%s' "$BODY" | grep -o 'media="print"' | wc -l)"
} 2>&1 | tee "$OUT"

echo
echo "SALVO EM: $OUT"
