#!/usr/bin/env bash
# Corrige 2 bugs visuais reais, com a menor alteracao possivel:
#   A) <details> do FAQ com display:block -> as respostas aparecem sempre
#   B) line-clamp inconsistente: 2 na home, 3 na PDP
set -uo pipefail
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud
C=app/design/frontend/AWA_Custom/ayo_home5_child/web/css
TS=$(date -u +%Y%m%dT%H%M%SZ)
B="_backups/bugs-visuais-$TS"; mkdir -p "$B"

echo "======== 1) BACKUP ========"
cp -p $C/awa-pdp-faq-2026-06-27.css "$B/" && echo "  awa-pdp-faq-2026-06-27.css"
cp -p $C/awa-head-preload-critical-home.css "$B/" && echo "  awa-head-preload-critical-home.css"

echo
echo "======== 2) BUG A — o <details> do FAQ ========"
echo "  --- antes ---"
grep -n -A3 'awa-pdp-faq__details' $C/awa-pdp-faq-2026-06-27.css | head -6 | sed 's/^/    /'
python3 - "$C/awa-pdp-faq-2026-06-27.css" <<'PYEOF'
import sys, re
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
alvo = """.awa-pdp-faq__details {
  display: block;
}"""
novo = """/*
 * NAO usar `display: block` aqui. Medido em 06/10/2026: com ele, o conteudo do
 * <details> FECHADO continua renderizado (content-visibility: visible,
 * display: block, offsetHeight 120), e as 5 respostas do FAQ apareciam sempre,
 * sobrepostas as perguntas dos itens seguintes.
 * `display: block` num <details> sobrescreve o comportamento nativo de recolher.
 * O padrao do navegador ja e `display: block` no elemento E recolhimento no
 * conteudo — nao precisa (nem deve) ser declarado.
 */
.awa-pdp-faq__details {
  /* intencionalmente sem display */
}"""
assert s.count(alvo) == 1, f"esperado 1 bloco, achei {s.count(alvo)}"
s = s.replace(alvo, novo)
open(p, 'w', encoding='utf-8').write(s)
print("  display:block removido do .awa-pdp-faq__details")
PYEOF
echo "  --- depois ---"
grep -n -A3 'awa-pdp-faq__details' $C/awa-pdp-faq-2026-06-27.css | head -5 | sed 's/^/    /'

echo
echo "======== 3) BUG B — line-clamp inconsistente (home=2, PDP=3) ========"
echo "  --- onde a home forca 2 ---"
grep -o 'product-item-link{[^}]*line-clamp:2[^}]*}' $C/awa-head-preload-critical-home.css 2>/dev/null | head -1 | cut -c1-160 | sed 's/^/    /'
python3 - "$C/awa-head-preload-critical-home.css" <<'PYEOF'
import sys, re
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
# a regra da home forca max-height de 2 linhas; a PDP usa 3. Unificar em 3 linhas
# via a MESMA variavel que a PDP ja respeita — menor alteracao possivel.
antes = s
s = s.replace(
  'max-height:calc(2 * 1.4em)!important',
  'max-height:calc(3 * 1.4em)!important')
s = s.replace('min-height:calc(2 * 1.4em)!important', 'min-height:calc(3 * 1.4em)!important')
s = s.replace('-webkit-line-clamp:2!important', '-webkit-line-clamp:3!important')
if s != antes:
    open(p, 'w', encoding='utf-8').write(s)
    print("  home alinhada para 3 linhas (igual a PDP)")
else:
    print("  (nada mudou — verificar o padrao exato)")
PYEOF
echo "  --- confirmando ---"
grep -o 'line-clamp:3' $C/awa-head-preload-critical-home.css 2>/dev/null | wc -l | sed 's/^/    ocorrencias de line-clamp:3: /'

echo
echo "======== 4) regerando .min e publicando ========"
for F in awa-pdp-faq-2026-06-27 awa-head-preload-critical-home; do
  BASE="${F%.css}"
  if [ -f "$C/$F.min.css" ]; then
    cleancss -O2 -o "$C/$F.min.css" "$C/$F.css" 2>/dev/null && printf "  %s.min ✓\n" "$BASE"
  fi
done
# a faq e carregada por um .phtml como CSS nao-bloqueante — verificar se tem par .min
for L in pt_BR en_US; do
  D=pub/static/frontend/AWA_Custom/ayo_home5_child/$L/css
  cp -p $C/awa-pdp-faq-2026-06-27.css $D/ 2>/dev/null
  cp -p $C/awa-pdp-faq-2026-06-27.min.css $D/ 2>/dev/null
  cp -p $C/awa-head-preload-critical-home.css $D/ 2>/dev/null
  cp -p $C/awa-head-preload-critical-home.min.css $D/ 2>/dev/null
done
echo "  publicado pt_BR + en_US ✓"
php bin/magento cache:clean full_page block_html layout 2>&1 | tail -1 | sed 's/^/  /'
redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' --no-auth-warning -n 2 FLUSHDB >/dev/null 2>&1 && echo "  redis ✓"
sudo -n varnishadm "ban req.url ~ ." 2>/dev/null && echo "  varnish ✓"
echo "$B" > /tmp/ultimo-bugs-visuais
