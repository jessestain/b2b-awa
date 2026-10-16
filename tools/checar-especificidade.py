#!/usr/bin/env python3
"""Mede se remover o #html-body repetido e SEGURO: ha empates de especificidade?

O RISCO: `#html-body#html-body` tem especificidade (2,0,0). Se um seletor vence
outro APENAS por repetir o id, reduzir para (1,0,0) inverteria a cascata.

O TESTE: para cada seletor normalizado (com repeticao colapsada), verificar se
existe OUTRO seletor com a mesma "cauda" (mesmas classes/atributos) e contagem de
ids diferente. Se nao existir, nao ha disputa e o colapso e neutro.

Uso: python3 tools/checar-especificidade.py [--json]
"""
import gzip, re, sys, json, urllib.request
from collections import defaultdict
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


def colapsar(sel: str) -> str:
    """Colapsa `#html-body#html-body#html-body` -> `#html-body`, preservando o resto."""
    # so mexe em repeticoes do MESMO id coladas (com ou sem espaco entre)
    def repl(m):
        return m.group(1)
    return re.sub(r'(#html-body)(?:\s*#html-body)+', repl, sel)


def conta_ids(sel: str) -> int:
    return len(re.findall(r'#[\w-]+', sel))


url_plp = 'https://awamotos.com/retrovisores.html'
h = baixar(url_plp)
p = P()
p.feed(h)

# 1) quanto peso esta nas repeticoes do id
bytes_id = 0
bytes_id_colapsado = 0
seletores_afetados = 0
todos_sel = []

for sid, css in p.styles:
    for m in re.finditer(r'([^{}@][^{}]*)\{([^{}]*)\}', css):
        sel = re.sub(r'\s+', ' ', m.group(1).strip())
        if not sel:
            continue
        regra_original = sel + '{' + m.group(2) + '}'
        sel_col = colapsar(sel)
        regra_col = sel_col + '{' + m.group(2) + '}'
        bytes_id += len(regra_original)
        bytes_id_colapsado += len(regra_col)
        if sel != sel_col:
            seletores_afetados += 1
        todos_sel.append((sel, sel_col, sid, m.group(2)))

economia = bytes_id - bytes_id_colapsado
print("======== 1) peso das repeticoes de #html-body ========")
print(f"  regras analisadas ............ {len(todos_sel)}")
print(f"  *** regras com id repetido ... {seletores_afetados} ***")
print(f"  bytes hoje ................... {bytes_id/1024:8.1f} KB")
print(f"  bytes colapsado .............. {bytes_id_colapsado/1024:8.1f} KB")
print(f"  *** ECONOMIA ................. {economia/1024:8.1f} KB ***")

# 2) ha disputa de especificidade? compara "cauda" (tudo menos os ids)
print()
print("======== 2) risco de inversao de cascata ========")
porCauda = defaultdict(list)
for sel, sel_col, sid, corpo in todos_sel:
    cauda = re.sub(r'#[\w-]+', '#X', sel)          # normaliza QUALQUER id para #X
    porCauda[cauda].append((sel, conta_ids(sel), sid))

disputas = []
for cauda, lst in porCauda.items():
    if len(lst) < 2:
        continue
    ids = sorted({n for _, n, _ in lst})
    if len(ids) > 1:
        disputas.append((cauda, len(lst), ids, [s for s, _, _ in lst][:3]))

print(f"  caudas com mais de uma regra e contagem de id DIFERENTE: *** {len(disputas)} ***")
if disputas:
    print("  --- as 8 maiores (aqui o colapso PODE mexer na cascata) ---")
    for cauda, n, ids, ex in sorted(disputas, key=lambda x: -x[1])[:8]:
        print(f"    {n:3d} regras · ids={ids} · {cauda[:72]}")
else:
    print("  (nenhuma — nao ha seletor vencendo outro por repetir o id)")

if '--json' in sys.argv:
    print(json.dumps({'economia_kb': round(economia/1024, 1),
                      'regras_afetadas': seletores_afetados,
                      'disputas': len(disputas)}, indent=2))
