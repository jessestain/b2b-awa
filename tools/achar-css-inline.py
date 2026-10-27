#!/usr/bin/env python3
"""De onde vem o CSS inline? Baixa a pagina e classifica cada <style>."""
import re, sys, urllib.request, gzip, io

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'

def baixar(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip'})
    with urllib.request.urlopen(req, timeout=45) as r:
        dados = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            dados = gzip.decompress(dados)
        return dados.decode('utf-8', errors='ignore')

for url, nome in [('https://awamotos.com/retrovisores.html', 'PLP'),
                  ('https://awamotos.com/', 'HOME')]:
    print(f"======== {nome} ========")
    try:
        h = baixar(url)
    except Exception as e:
        print(f"  *** falhou: {e} ***\n")
        continue

    print(f"  HTML: {len(h)/1024:.0f} KB")
    styles = re.findall(r'<style([^>]*)>(.*?)</style>', h, re.S)
    linhas = []
    for attrs, corpo in styles:
        sid = re.search(r'id="([^"]*)"', attrs)
        linhas.append((len(corpo) / 1024, sid.group(1) if sid else '(sem id)'))
    linhas.sort(reverse=True)
    total = sum(x[0] for x in linhas)
    print(f"  <style>: {len(linhas)} · *** inline total: {total:.1f} KB ({total/(len(h)/1024)*100:.0f}% do HTML) ***")
    print("  --- os 12 maiores ---")
    for kb, sid in linhas[:12]:
        print(f"    {kb:8.1f} KB  id={sid}")
    print(f"  <style> < 0,5 KB: {sum(1 for x in linhas if x[0] < 0.5)}")

    # quanto do inline e de fato REGRA vs comentario/espaco
    regras = 0
    for _, _ in linhas:
        pass
    print()

# e os arquivos que geram
print("======== quem gera (top) ========")
import subprocess, os
os.chdir('/home/jessessh/htdocs/srv1113343.hstgr.cloud')
for f in subprocess.run(['grep','-rl','<style','app/code/GrupoAwamotos/'], capture_output=True, text=True).stdout.split():
    if '.bak' in f: continue
    n = open(f, encoding='utf-8', errors='ignore').read().count('<style')
    tamanho = os.path.getsize(f)
    print(f"  {f.replace('app/code/GrupoAwamotos/',''):<62} {n:>4} styles · {tamanho/1024:.0f} KB")
