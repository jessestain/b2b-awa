#!/usr/bin/env python3
"""Os blocos `b2b-promo-shell-r40c` e `-head` sao o mesmo CSS injetado duas vezes?"""
import gzip, re, hashlib, urllib.request
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
        s.ds = 0; s.styles = []; s.buf = []; s.cur = None; s.tipo = None

    def handle_starttag(s, t, a):
        if t == 'style':
            s.ds += 1; s.cur = dict(a).get('id', '(sem id)'); s.tipo = 's'; s.buf = []

    def handle_endtag(s, t):
        if t == 'style' and s.ds:
            s.ds -= 1; s.styles.append((s.cur, ''.join(s.buf))); s.tipo = None

    def handle_data(s, d):
        if s.tipo == 's': s.buf.append(d)


h = baixar('https://awamotos.com/retrovisores.html')
p = P(); p.feed(h)

porid = {}
for sid, css in p.styles:
    porid.setdefault(sid, []).append(css)

print("======== blocos <style> com o mesmo id repetido ========")
dup_id = {k: v for k, v in porid.items() if len(v) > 1}
if not dup_id:
    print("  (nenhum id aparece mais de uma vez)")
for sid, lst in dup_id.items():
    md5s = {hashlib.md5(c.encode()).hexdigest()[:12] for c in lst}
    tam = [len(c) for c in lst]
    iguais = len(md5s) == 1
    print(f"  id={sid}")
    print(f"    {len(lst)}x · tamanhos={tam} · md5 distintos={len(md5s)} · *** {'IDÊNTICOS' if iguais else 'DIFERENTES'} ***")

print()
print("======== os dois b2b-promo-shell-r40c ========")
cand = {k: v for k, v in porid.items() if 'b2b-promo-shell-r40c' in k}
for sid, lst in sorted(cand.items()):
    tot = sum(len(c) for c in lst)
    print(f"  {sid:44s} {len(lst)}x · {tot/1024:6.1f} KB total")

# compara o CONTEUDO dos dois blocos distintos
nomes = sorted(cand.keys())
if len(nomes) >= 2:
    a = cand[nomes[0]][0]
    b = cand[nomes[1]][0]
    print()
    print(f"  A = {nomes[0]} → {len(a)/1024:.1f} KB")
    print(f"  B = {nomes[1]} → {len(b)/1024:.1f} KB")
    print(f"  md5 A={hashlib.md5(a.encode()).hexdigest()[:12]} · md5 B={hashlib.md5(b.encode()).hexdigest()[:12]}")
    if a == b:
        print("  *** CONTEUDO IDENTICO — duplicacao pura ***")
    else:
        so_a = set(re.findall(r'([^{}]+)\{', a)) - set(re.findall(r'([^{}]+)\{', b))
        so_b = set(re.findall(r'([^{}]+)\{', b)) - set(re.findall(r'([^{}]+)\{', a))
        print(f"  seletores so em A: {len(so_a)} · so em B: {len(so_b)}")
        print("  --- 5 so em A ---")
        for x in list(so_a)[:5]: print(f"    {x.strip()[:90]}")
        print("  --- 5 so em B ---")
        for x in list(so_b)[:5]: print(f"    {x.strip()[:90]}")

print()
print("======== e no HTML: aparecem em <head> ou no <body>? ========")
for m in re.finditer(r'<style[^>]*id="(awa-b2b-promo-shell-r40c[^"]*)"', h):
    antes = h[:m.start()]
    head_end = h.find('</head>')
    local = 'HEAD' if m.start() < head_end else 'BODY'
    print(f"  {m.group(1):44s} posicao={m.start():7d} → *** {local} ***")
