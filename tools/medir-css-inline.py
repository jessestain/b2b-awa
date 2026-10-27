#!/usr/bin/env python3
"""Quanto do CSS inline esta REALMENTE minificado? Espaço recuperavel sem risco."""
import re, gzip, urllib.request

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'

def baixar(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip'})
    with urllib.request.urlopen(req, timeout=45) as r:
        d = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            d = gzip.decompress(d)
        return d.decode('utf-8', errors='ignore')

for url, nome in [('https://awamotos.com/retrovisores.html', 'PLP'),
                  ('https://awamotos.com/bauleto-awa-modelo-proos-34-litros-dourado-340-dr.html', 'PDP')]:
    print(f"======== {nome} ========")
    try:
        h = baixar(url)
    except Exception as e:
        print(f"  *** falhou: {e} ***\n"); continue

    styles = re.findall(r'<style([^>]*)>(.*?)</style>', h, re.S)
    total = 0
    minificados = 0
    nao_min = []
    for attrs, corpo in styles:
        total += len(corpo)
        sid = re.search(r'id="([^"]*)"', attrs)
        sid = sid.group(1) if sid else '(sem id)'
        # heuristica de minificacao: CSS minificado tem poucas quebras por KB
        nl = corpo.count('\n')
        kb = len(corpo) / 1024
        densidade = nl / max(kb, 0.01)   # quebras por KB
        if densidade < 15:
            minificados += 1
        else:
            # estima o tamanho minificado
            est = len(re.sub(r'\s+', ' ', re.sub(r'/\*.*?\*/', '', corpo, flags=re.S)).strip())
            nao_min.append((kb, sid, nl, est / 1024, kb - est / 1024))

    print(f"  <style>: {len(styles)} · inline {total/1024:.1f} KB")
    print(f"  minificados: {minificados} · NAO minificados: {len(nao_min)}")
    ganho = sum(x[4] for x in nao_min)
    if nao_min:
        print(f"  *** ganho estimado ao minificar: {ganho:.1f} KB ***")
        nao_min.sort(reverse=True)
        print("  --- os 10 maiores nao-minificados ---")
        for kb, sid, nl, est, g in nao_min[:10]:
            print(f"    {kb:7.1f} KB -> {est:6.1f} KB (economia {g:5.1f}) · {nl:6d} linhas · {sid}")
    print()

# e o HTML em si: quanto e marcacao vs style/script?
print("======== composicao do HTML da PLP ========")
h = baixar('https://awamotos.com/retrovisores.html')
tot = len(h)
css = sum(len(c) for _, c in re.findall(r'<style([^>]*)>(.*?)</style>', h, re.S))
js = sum(len(c) for _, c in re.findall(r'<script([^>]*)>(.*?)</script>', h, re.S))
print(f"  HTML total ....... {tot/1024:8.1f} KB")
print(f"  dentro de <style>  {css/1024:8.1f} KB  ({css/tot*100:.0f}%)")
print(f"  dentro de <script> {js/1024:8.1f} KB  ({js/tot*100:.0f}%)")
print(f"  *** marcacao real  {(tot-css-js)/1024:8.1f} KB  ({(tot-css-js)/tot*100:.0f}%) ***")
