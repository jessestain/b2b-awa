#!/usr/bin/env bash
# Por que os tokens do awa-tokens-base nao chegam no login B2B?
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "======== 1) o que o HTML do login referencia ========"
curl -sL -A Mozilla --max-time 30 'https://awamotos.com/b2b/account/login/' -o /tmp/lg.html 2>/dev/null
grep -oE '<link[^>]*rel="stylesheet"[^>]*>' /tmp/lg.html | grep -oE 'href="[^"]*"' | sed 's/href="//;s/"$//' | sed 's|.*/||' | sort -u | head -20 | sed 's/^/  /'
echo
echo "  --- e as linhas com awa-tokens-base ---"
grep -oE '[^>]*awa-tokens-base[^>]*' /tmp/lg.html | head -4 | cut -c1-200 | sed 's/^/  /'

echo
echo "======== 2) o arquivo publicado TEM os tokens? ========"
for L in pt_BR en_US; do
  F=pub/static/frontend/AWA_Custom/ayo_home5_child/$L/css/awa-tokens-base.css
  printf "  %-8s %s bytes · %s tokens · --awa-ink? %s\n" "$L" \
    "$(stat -c%s $F 2>/dev/null)" \
    "$(grep -oE -- '--awa-[a-z0-9-]+:' $F 2>/dev/null | sort -u | wc -l)" \
    "$(grep -c -- '--awa-ink:' $F 2>/dev/null)"
done

echo
echo "======== 3) o que o servidor ENTREGA no login? ========"
for URL in $(grep -oE 'href="[^"]*awa-tokens-base[^"]*"' /tmp/lg.html | sed 's/href="//;s/"$//' | sort -u); do
  case "$URL" in http*) FULL="$URL";; /*) FULL="https://awamotos.com$URL";; *) FULL="https://awamotos.com/$URL";; esac
  BODY=$(curl -sL -A Mozilla --max-time 25 "$FULL" 2>/dev/null)
  printf "  %s\n" "$(basename ${URL%%\?*})"
  printf "    bytes=%s · tokens=%s · --awa-ink? %s\n" "${#BODY}" \
    "$(echo "$BODY" | grep -oE -- '--awa-[a-z0-9-]+:' | sort -u | wc -l)" \
    "$(echo "$BODY" | grep -c -- '--awa-ink:')"
done

echo
echo "======== 4) quantos <style> INLINE o login tem (o design vai por la?) ========"
printf "  <style> no login: %s\n" "$(grep -oE '<style' /tmp/lg.html | wc -l)"
printf "  e na home: %s\n" "$(curl -sL -A Mozilla --max-time 30 'https://awamotos.com/' 2>/dev/null | grep -oE '<style' | wc -l)"
echo "  --- os ids dos <style> do login ---"
grep -oE '<style[^>]*id="[^"]*"' /tmp/lg.html | sed 's/^/    /' | head -12

echo
echo "======== 5) o b2b_auth_shell.xml remove o que? ========""
sed -n '1,40p' app/design/frontend/AWA_Custom/ayo_home5_child/GrupoAwamotos_B2B/layout/b2b_auth_shell.xml 2>/dev/null | sed 's/^/  /'
rm -f /tmp/lg.html
