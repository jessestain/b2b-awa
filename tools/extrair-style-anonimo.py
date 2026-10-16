#!/usr/bin/env python3
"""Extrai o <style> anonimo e nao-minificado e tenta identificar a origem pelo conteudo."""
import re, gzip, urllib.request, hashlib

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0.0.0'

def baixar(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Encoding': 'gzip'})
    with urllib.request.urlopen(req, timeout=45) as r:
        d = r.read()
        if r.headers.get('Content-Encoding') == 'gzip':
            d = gzip.decompress(d)
        return d.decode('utf-8', errors='ignore')

h = baixar('https://awamotos.com/retrovisores.html')
styles = re.findall(r'<style([^>]*)>(.*?)</style>', h, re.S)

alvo = None
for attrs, corpo in styles:
    if 'id=' in attrs:
        continue
    if len(corpo) > 50000:
        alvo = corpo
        break

if not alvo:
    print("nao achei o style anonimo grande")
    raise SystemExit(0)

print(f"  tamanho: {len(alvo)/1024:.1f} KB · {alvo.count(chr(10))} linhas")
print(f"  md5: {hashlib.md5(alvo.encode()).hexdigest()}")

print("\n  --- primeiras 12 linhas ---")
for l in alvo.split('\n')[:12]:
    print(f"    {l[:120]}")

print("\n  --- ultimas 6 linhas ---")
for l in alvo.split('\n')[-6:]:
    print(f"    {l[:120]}")

# seletores distintivos: os que mais aparecem
sel = re.findall(r'^([^{/\n][^{]*)\{', alvo, re.M)
print(f"\n  --- blocos de regra: {len(sel)} ---")
from collections import Counter
c = Counter(s.strip() for s in sel)
print("  --- 15 seletores mais frequentes ---")
for s, n in c.most_common(15):
    print(f"    {n:4d}x  {s[:96]}")

# pistas de origem
pistas = {
    'awa-b2b': alvo.count('awa-b2b'),
    'awa-plp': alvo.count('awa-plp'),
    'awa-home': alvo.count('awa-home'),
    'awa-footer': alvo.count('awa-footer'),
    'awa-align-grid': alvo.count('awa-align-grid'),
    '#html-body': alvo.count('#html-body'),
    '!important': alvo.count('!important'),
    '@media': alvo.count('@media'),
    'layer': alvo.count('@layer'),
}
print("\n  --- pistas ---")
for k, v in pistas.items():
    print(f"    {k:20s} {v}")

# e procura esse md5 nos arquivos do projeto
print("\n  --- procurando a origem nos arquivos ---")
import subprocess, os
os.chdir('/home/jessessh/htdocs/srv1113343.hstgr.cloud')
primeira = alvo.strip().split('\n')[0][:60]
segunda = alvo.strip().split('\n')[1][:60] if alvo.count('\n') > 1 else ''
print(f"    procurando por: {primeira[:60]!r}")
for pat in [primeira[:40], segunda[:40]]:
    if not pat.strip():
        continue
    r = subprocess.run(['grep', '-rl', '-F', pat, 'app/code/GrupoAwamotos/', 'app/design/'],
                       capture_output=True, text=True)
    for f in r.stdout.split()[:6]:
        if '.bak' in f: continue
        print(f"      {f}")
