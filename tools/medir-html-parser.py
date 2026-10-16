#!/usr/bin/env python3
"""Re-mede a composicao do HTML com um parser de verdade, nao com regex.

POR QUE: a medicao anterior usou `<style([^>]*)>(.*?)</style>` nao-guloso, e um
`</style>` dentro de um <script> fazia o regex capturar JavaScript como se fosse
CSS. Resultado: "128,8 KB de CSS nao minificado" que na verdade era JS + CSS
misturados. O numero estava inflado e ia gerar uma otimizacao inexistente.
"""
import gzip, urllib.request
from html.parser import HTMLParser

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'


def baixar(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip'})
    with urllib.request.urlopen(req, timeout=45) as r:
        d = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            d = gzip.decompress(d)
        return d.decode('utf-8', errors='ignore')


class Analisador(HTMLParser):
    """Conta bytes dentro de style/script de verdade, respeitando aninhamento."""

    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.profundidade_style = 0
        self.profundidade_script = 0
        self.byte_style = 0
        self.byte_script = 0
        self.styles = []      # (id, tamanho)
        self.scripts = []     # (id, tipo, tamanho)
        self.atual = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'style':
            self.profundidade_style += 1
            self.atual = a.get('id', '(sem id)')
            self._ini = self.byte_style
        elif tag == 'script':
            self.profundidade_script += 1
            self.atual = a.get('id', a.get('type', '(sem id)'))
            self._ini = self.byte_script

    def handle_endtag(self, tag):
        if tag == 'style' and self.profundidade_style:
            self.profundidade_style -= 1
            self.styles.append((self.atual, self.byte_style - getattr(self, '_ini', 0)))
        elif tag == 'script' and self.profundidade_script:
            self.profundidade_script -= 1
            self.scripts.append((self.atual, self.byte_script - getattr(self, '_ini', 0)))

    def handle_data(self, data):
        if self.profundidade_style:
            self.byte_style += len(data)
        if self.profundidade_script:
            self.byte_script += len(data)


for url, nome in [('https://awamotos.com/retrovisores.html', 'PLP'),
                  ('https://awamotos.com/', 'HOME'),
                  ('https://awamotos.com/bauleto-awa-modelo-proos-34-litros-dourado-340-dr.html', 'PDP')]:
    print(f"======== {nome} ========")
    try:
        h = baixar(url)
    except Exception as e:
        print(f"  *** falhou: {e} ***\n"); continue

    p = Analisador()
    p.feed(h)

    tot = len(h)
    cs, cj = p.byte_style, p.byte_script
    print(f"  HTML total .......... {tot/1024:8.1f} KB")
    print(f"  <style> ({len(p.styles):2d}) ........ {cs/1024:8.1f} KB  ({cs/tot*100:4.1f}%)")
    print(f"  <script> ({len(p.scripts):2d}) ....... {cj/1024:8.1f} KB  ({cj/tot*100:4.1f}%)")
    print(f"  *** marcação real ... {(tot-cs-cj)/1024:8.1f} KB  ({(tot-cs-cj)/tot*100:4.1f}%) ***")

    p.styles.sort(key=lambda x: -x[1])
    print("  --- os 10 maiores <style> (tamanho REAL) ---")
    for sid, n in p.styles[:10]:
        print(f"    {n/1024:7.1f} KB  id={sid[:62]}")

    # quanto de CSS nao esta minificado, por densidade de quebra de linha
    print()
