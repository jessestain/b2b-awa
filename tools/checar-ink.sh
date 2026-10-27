#!/usr/bin/env bash
# Investigacao: --awa-ink tem duas verdades. Qual e a correta, e alinhar muda a tela?
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud
C=app/design/frontend/AWA_Custom/ayo_home5_child/web/css
F=$C/awa-impeccable-layout-2026-06-16.css

echo "======== 1) o que o arquivo define (extraindo so os tokens) ========"
python3 - "$F" <<'PYEOF'
import sys, re
s = open(sys.argv[1], encoding='utf-8', errors='ignore').read()
for t in ['--awa-ink', '--awa-ink-soft', '--awa-text', '--awa-primary', '--awa-primary-hover',
          '--awa-border', '--awa-border-subtle', '--awa-bg', '--awa-bg-soft', '--awa-error',
          '--awa-success', '--awa-warning', '--awa-info', '--awa-radius-sm', '--awa-ease',
          '--awa-dur-base', '--awa-container-content']:
    for m in re.finditer(re.escape(t) + r'\s*:\s*([^;}]+)', s):
        print(f"  {t:26s} {m.group(1).strip()}")
        break
PYEOF

echo
echo "======== 2) o design system define os MESMOS tokens com que valor? ========"
python3 - "$C/awa-design-system.css" <<'PYEOF'
import sys, re
s = open(sys.argv[1], encoding='utf-8', errors='ignore').read()
for t in ['--awa-ink', '--awa-ink-soft', '--awa-line', '--awa-surface', '--awa-surface-alt',
          '--awa-brand', '--awa-brand-dark', '--awa-text', '--awa-radius-s', '--awa-radius-m']:
    for m in re.finditer(re.escape(t) + r'\s*:\s*([^;}]+)', s):
        print(f"  {t:26s} {m.group(1).strip()[:60]}")
        break
PYEOF

echo
echo "======== 3) onde cada um CARREGA (por pagina) ========"
for U in / /retrovisores.html /bauletos.html /checkout/cart/ /b2b/account/login/ /faq; do
  R=$(curl -sL -A Mozilla --max-time 25 "https://awamotos.com$U" 2>/dev/null)
  A=$(echo "$R" | grep -c 'awa-impeccable-layout')
  B=$(echo "$R" | grep -c 'awa-design-system')
  printf "  %-24s impeccable-layout=%s  design-system=%s\n" "$U" "$A" "$B"
done

echo
echo "======== 4) e o VALOR que o CSS servido declara para --awa-ink ========"
curl -sL -A Mozilla --max-time 30 'https://awamotos.com/' -o /tmp/h1.html 2>/dev/null
for URL in $(grep -oE 'href="[^"]*\.css[^"]*"' /tmp/h1.html | sed 's/href="//;s/"$//' | sort -u); do
  case "$URL" in http*) FULL="$URL";; /*) FULL="https://awamotos.com$URL";; *) FULL="https://awamotos.com/$URL";; esac
  BODY=$(curl -sL -A Mozilla --max-time 25 "$FULL" 2>/dev/null)
  D=$(echo "$BODY" | grep -oE -- '--awa-ink:[^;}]{1,20}' | sort -u | tr '\n' ' ')
  [ -n "$D" ] && printf "  %-46s %s\n" "$(basename ${URL%%\?*})" "$D"
done
rm -f /tmp/h1.html

echo
echo "======== 5) e os tokens REPETIDOS como proprio fallback ========"
python3 - <<'PYEOF'
import re, glob, os
C = 'app/design/frontend/AWA_Custom/ayo_home5_child/web/css'
n = 0
for f in sorted(glob.glob(C + '/awa-*.css')):
    if '.bak' in f: continue
    s = open(f, encoding='utf-8', errors='ignore').read()
    for m in re.finditer(r'(--[a-z0-9-]+)\s*:\s*var\(\s*(--[a-z0-9-]+)\s*,\s*(--[a-z0-9-]+)\s*\)', s):
        if m.group(1) == m.group(2) == m.group(3):
            n += 1
            if n <= 8:
                print(f"  *** {m.group(1)}: var({m.group(2)}, {m.group(3)}) *** em {os.path.basename(f)}")
print(f"  total: {n}")
PYEOF
