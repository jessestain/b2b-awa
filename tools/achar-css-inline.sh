#!/usr/bin/env bash
# De onde vem os 54 <style> inline da PLP (637 KB)?
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

for PAG in "https://awamotos.com/retrovisores.html:PLP" "https://awamotos.com/:HOME"; do
  URL="${PAG%%:*}"; NOME="${PAG##*:}"
  echo "======== $NOME ========"
  curl -sL -A Mozilla --max-time 40 "$URL" -o /tmp/pg.html 2>/dev/null
  printf "  HTML total: %s KB\n" "$(( $(stat -c%s /tmp/pg.html) / 1024 ))"

  python3 - <<'PYEOF'
import re
h = open('/tmp/pg.html', encoding='utf-8', errors='ignore').read()
styles = re.findall(r'<style([^>]*)>(.*?)</style>', h, re.S)
print(f"  <style> encontrados: {len(styles)}")
tot = 0
linhas = []
for attrs, corpo in styles:
    kb = len(corpo) / 1024
    tot += kb
    sid = re.search(r'id="([^"]*)"', attrs)
    linhas.append((kb, sid.group(1) if sid else '(sem id)', len(corpo)))
linhas.sort(reverse=True)
print(f"  *** total inline: {tot:.1f} KB ***")
print("  --- os 12 maiores ---")
for kb, sid, n in linhas[:12]:
    print(f"    {kb:8.1f} KB  id={sid}")
# quantos sao so de uma linha minuscula (ruido)
pequenos = [x for x in linhas if x[0] < 0.5]
print(f"  <style> menores que 0,5 KB: {len(pequenos)}")
PYEOF
  echo
done
rm -f /tmp/pg.html

echo "======== quem INJETA style inline no PHP ========"
grep -rln "'<style'\|'<style>\|\"<style" app/code/GrupoAwamotos/ 2>/dev/null | grep -v "\.bak" | head -12 | sed 's/^/  /'
echo
echo "  --- e quantas vezes cada um injeta ---"
for F in $(grep -rln "'<style\|\"<style" app/code/GrupoAwamotos/ 2>/dev/null | grep -v "\.bak" | head -12); do
  N=$(grep -o "<style" "$F" 2>/dev/null | wc -l)
  printf "    %-72s %s\n" "$(echo $F | sed 's|app/code/GrupoAwamotos/||')" "$N"
done

echo
echo "======== e nos .phtml ========"
grep -rln "<style" app/design/frontend/AWA_Custom/ayo_home5_child/ --include=*.phtml 2>/dev/null | head -10 | sed 's|.*/ayo_home5_child/|  |'
printf "  total de .phtml com <style>: %s\n" "$(grep -rln '<style' app/design/frontend/AWA_Custom/ayo_home5_child/ --include=*.phtml 2>/dev/null | wc -l)"
