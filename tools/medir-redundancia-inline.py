#!/usr/bin/env python3
"""Mede redundancia no CSS inline: o mesmo seletor redefinido varias vezes.

Um "lock"/"terminal" que reescreve o mesmo seletor que outro ja escreveu e peso
morto: o navegador aplica o ultimo e descarta o resto. Se o mesmo seletor aparece
N vezes, as N-1 primeiras sao candidatas a remocao.

NAO altera nada. So mede e lista.
"""
import gzip, re, urllib.request
from collections import defaultdict, Counter
from html.parser import HTMLParser

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'


def baixar(u):
    r = urllib.request.Request(u, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip'})
    with urllib.request.urlopen(r, timeout=45) as x:
        d = x.read()
        if x.headers.get('Content-Encoding') == 'gzip':
            d = gzip.decompress(d)
        return d.decode('utf-8', errors='ignore')


class P(HTMLParser):
    def __init__(s):
        super().__init__(convert_charrefs=False)
        s.ds = 0
        s.styles = []
        s.buf = []
        s.cur = None
        s.tipo = None

    def handle_starttag(s, t, a):
        if t == 'style':
            s.ds += 1
            s.cur = dict(a).get('id', '(sem id)')
            s.tipo = 's'
            s.buf = []

    def handle_endtag(s, t):
        if t == 'style' and s.ds:
            s.ds -= 1
            s.styles.append((s.cur, ''.join(s.buf)))
            s.tipo = None

    def handle_data(s, data):
        if s.tipo == 's':
            s.buf.append(data)


# byte de um seletor => lista de (bloco_id, bytes_aproximados_da_regra)
porSeletor = defaultdict(list)
blocos = []

for url, nome in [('https://awamotos.com/retrovisores.html', 'PLP'),
                  ('https://awamotos.com/bauleto-awa-modelo-proos-34-litros-dourado-340-dr.html', 'PDP')]:
    h = baixar(url)
    p = P()
    p.feed(h)
    print(f"======== {nome} ========")
    print(f"  blocos <style>: {len(p.styles)}")

    porSeletor.clear()
    total = 0
    for sid, css in p.styles:
        total += len(css)
        # remove @media/@layer externos para achar as regras internas
        for m in re.finditer(r'([^{}@][^{}]*)\{([^{}]*)\}', css):
            sel = m.group(1).strip()
            if not sel or sel.startswith('@'):
                continue
            # normaliza espaco para agrupar o mesmo seletor escrito diferente
            chave = re.sub(r'\s+', ' ', sel)
            porSeletor[chave].append((sid, len(m.group(0))))

    print(f"  CSS total: {total/1024:.1f} KB")
    print(f"  seletores DISTINTOS: {len(porSeletor)}")

    repetidos = {k: v for k, v in porSeletor.items() if len(v) > 1}
    bytes_rep = sum(sum(b for _, b in v[1:]) for v in repetidos.values())
    print(f"  *** seletores repetidos: {len(repetidos)} ***")
    print(f"  *** bytes em repeticoes (2a ocorrencia em diante): {bytes_rep/1024:.1f} KB ***")
    print(f"      ({bytes_rep/total*100:.0f}% do CSS inline)")

    print("  --- os 12 seletores mais repetidos ---")
    for k, v in sorted(repetidos.items(), key=lambda x: -len(x[1]))[:12]:
        ids = Counter(sid for sid, _ in v)
        print(f"    {len(v):3d}x  {k[:66]}")
        print(f"          blocos: {', '.join(f'{s}({n})' for s, n in ids.most_common(4))}")
    print()
