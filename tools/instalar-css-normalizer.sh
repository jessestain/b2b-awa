#!/usr/bin/env bash
# Instala o CssSpecificityNormalizerPlugin com validacao antes/depois.
set -uo pipefail
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud
D=app/code/GrupoAwamotos/Theme/Plugin/Response
DI=app/code/GrupoAwamotos/Theme/etc/frontend/di.xml
TS=$(date -u +%Y%m%dT%H%M%SZ)
B="_backups/css-normalizer-$TS"; mkdir -p "$B"

echo "======== 1) ANTES: tamanho do HTML e contagem de seletores ========"
python3 - <<'PYEOF' | tee "$B/antes.txt"
import gzip, re, urllib.request
from html.parser import HTMLParser
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'
def baixar(u):
    r=urllib.request.Request(u,headers={'User-Agent':UA,'Accept-Encoding':'gzip'})
    with urllib.request.urlopen(r,timeout=45) as x:
        d=x.read()
        if x.headers.get('Content-Encoding')=='gzip': d=gzip.decompress(d)
        return d.decode('utf-8',errors='ignore')
class P(HTMLParser):
    def __init__(s):
        super().__init__(convert_charrefs=False); s.ds=0; s.styles=[]; s.buf=[]; s.tipo=None
    def handle_starttag(s,t,a):
        if t=='style': s.ds+=1; s.tipo='s'; s.buf=[]
    def handle_endtag(s,t):
        if t=='style' and s.ds: s.ds-=1; s.styles.append(''.join(s.buf)); s.tipo=None
    def handle_data(s,d):
        if s.tipo=='s': s.buf.append(d)
for u,n in [('https://awamotos.com/retrovisores.html','PLP'),('https://awamotos.com/','HOME')]:
    h=baixar(u); p=P(); p.feed(h)
    css=''.join(p.styles)
    rep=len(re.findall(r'#html-body(?:\s*#html-body)+', css))
    print(f"  {n}: HTML={len(h)/1024:8.1f} KB · CSS inline={len(css)/1024:7.1f} KB · seletores com id repetido={rep}")
PYEOF

echo
echo "======== 2) instalando o plugin ========"
cp -p "$D/HtmlMinifyPlugin.php" "$B/" 2>/dev/null
cp -p "$DI" "$B/"
ls -la "$D/CssSpecificityNormalizerPlugin.php" 2>/dev/null | awk '{printf "  plugin presente: %s bytes\n", $5}'
php -l "$D/CssSpecificityNormalizerPlugin.php" 2>&1 | sed 's/^/  /'

echo
echo "======== 3) registrando no frontend/di.xml ========"
grep -q 'CssSpecificityNormalizerPlugin' "$DI" && echo "  (ja registrado)" || python3 - "$DI" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
alvo = '    </config>'
assert s.count(alvo) == 1, f"esperado 1 </config>, achei {s.count(alvo)}"
novo = '''    <!--
      Normaliza a especificidade do CSS inline: colapsa `#html-body` repetido.
      Medido em 06/10/2026: 1.218 de 1.596 regras (76%) repetiam o id, ate 6 vezes,
      custando 98,8 KB de HTML por pagina sem nenhum efeito na cascata
      (tools/checar-especificidade.py prova: 0 disputas por contagem de id).
      sortOrder 999 roda depois do OptimizeHeadStylesPlugin (998).
    -->
    <type name="Magento\\Framework\\HTTP\\PhpEnvironment\\Response">
        <plugin name="grupoawamotos_theme_css_specificity_normalizer"
                type="GrupoAwamotos\\Theme\\Plugin\\Response\\CssSpecificityNormalizerPlugin"
                sortOrder="999"/>
    </type>
''' + alvo
s = s.replace(alvo, novo, 1)
open(p, 'w', encoding='utf-8').write(s)
print("  registrado em frontend/di.xml")
PYEOF
python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse('$DI'); print('  di.xml valido ✓')" 2>&1 | sed 's/^/  /'

echo
echo "======== 4) di:compile + OPcache + caches ========"
php bin/magento setup:di:compile 2>&1 | tail -3 | sed 's/^/  /'
bash scripts/opcache-reset.sh 2>&1 | tail -1 | sed 's/^/  /'
php bin/magento cache:clean config full_page block_html layout 2>&1 | tail -2 | sed 's/^/  /'
redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' --no-auth-warning -n 2 FLUSHDB >/dev/null 2>&1 && echo "  redis ✓"
sudo -n varnishadm "ban req.url ~ ." 2>/dev/null && echo "  varnish ✓"
echo
echo "$B" > /tmp/ultimo-normalizer
echo "backup: $B"
