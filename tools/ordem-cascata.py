#!/usr/bin/env python3
"""Onde cada <style> fica na ordem da cascata? Qual copia do b2b-promo-shell vence?"""
import gzip, re, urllib.request
from html.parser import HTMLParser

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'


def baixar(u):
    r = urllib.request.Request(u, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip', 'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(r, timeout=45) as x:
        d = x.read()
        if x.headers.get('Content-Encoding') == 'gzip':
            d = gzip.decompress(d)
        return d.decode('utf-8', errors='ignore')


class P(HTMLParser):
    def __init__(s):
        super().__init__(convert_charrefs=False)
        s.ds = 0; s.styles = []; s.buf = []; s.cur = None; s.tipo = None; s.pos = 0

    def handle_starttag(s, t, a):
        if t == 'style':
            s.ds += 1; s.cur = dict(a).get('id', '(sem id)'); s.tipo = 's'; s.buf = []; s.pos = s.getpos()[1]
        if t == 'link':
            d = dict(a)
            if d.get('rel') == 'stylesheet':
                s.styles.append(('LINK:' + (d.get('href', '').split('/')[-1].split('?')[0]), 0, s.getpos()[1]))

    def handle_endtag(s, t):
        if t == 'style' and s.ds:
            s.ds -= 1; s.styles.append((s.cur, len(''.join(s.buf)), s.pos)); s.tipo = None

    def handle_data(s, d):
        if s.tipo == 's': s.buf.append(d)


h = baixar('https://awamotos.com/retrovisores.html')
head_end = h.find('</head>')
# posicao real no documento, nao linha/coluna
ordem = []
for m in re.finditer(r'<(style|link)\b([^>]*)>', h):
    tag, attrs = m.group(1), m.group(2)
    d = dict(re.findall(r'(\w[\w-]*)="([^"]*)"', attrs))
    if tag == 'link' and d.get('rel') != 'stylesheet':
        continue
    nome = d.get('id') or (d.get('href', '').split('/')[-1].split('?')[0] if tag == 'link' else '(sem id)')
    local = 'HEAD' if m.start() < head_end else 'BODY'
    # tamanho: so para style
    if tag == 'style':
        fim = h.find('</style>', m.end())
        tam = fim - m.end()
    else:
        tam = 0
    ordem.append((m.start(), local, nome, tam))

print(f"  '</head>' termina em: {head_end}")
print(f"  folhas de estilo no documento: {len(ordem)}")
print()
print("  --- ordem completa: HEAD primeiro, depois BODY ---")
for pos, local, nome, tam in ordem:
    marca = ''
    if 'b2b-promo-shell-r40c' in nome:
        marca = '  ***<<< DUPLICADO'
    if local == 'BODY' and not any(o[1] == 'BODY' for o in ordem[:ordem.index((pos, local, nome, tam))]):
        print("  ---- fim do HEAD, comeca o BODY ----")
    print(f"    [{local}] {pos:8d}  {nome[:58]:58s} {tam/1024:7.1f} KB{marca}")

print()
print("  --- quem vence: a copia do HEAD ou a do BODY? ---")
head_c = [o for o in ordem if 'b2b-promo-shell-r40c-head' in o[2]]
body_c = [o for o in ordem if o[2] == 'awa-b2b-promo-shell-r40c']
if head_c and body_c:
    print(f"    HEAD em posicao {head_c[0][0]} · BODY em posicao {body_c[0][0]}")
    depois = [o for o in ordem if o[0] > head_c[0][0] and o[0] < body_c[0][0]]
    print(f"    folhas ENTRE as duas: {len(depois)}")
    for o in depois[:12]:
        print(f"      {o[2][:70]}")
    print(f"    ⇒ *** a copia do BODY vem DEPOIS de {len(depois)} folha(s) ⇒ e a que VENCE ***")
