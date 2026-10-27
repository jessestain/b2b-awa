#!/usr/bin/env bash
# Mede violacoes de boas praticas de LAYOUT do Magento 2 no tema.
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud
T=app/design/frontend/AWA_Custom/ayo_home5_child
MOD=app/code/GrupoAwamotos

echo "======== 1) cacheable=\"false\" — mata o FPC da PAGINA INTEIRA ========"
echo "  (boas praticas: só onde o conteudo e realmente por-sessao; cada um"
echo "   desliga o cache de pagina inteira para TODOS os visitantes)"
N=0
grep -rhoE '<block[^>]*cacheable="false"[^>]*>' $T $MOD --include=*.xml 2>/dev/null | while read -r L; do
  N=$((N+1))
  NAME=$(echo "$L" | grep -oE 'name="[^"]*"' | head -1)
  printf "    %s\n" "$NAME"
done
printf "  *** total: %s ***\n" "$(grep -rho '<block[^>]*cacheable="false"' $T $MOD --include=*.xml 2>/dev/null | wc -l)"

echo
echo "======== 2) <remove> vs <referenceBlock> — o padrao remover-e-repor ========"
printf "  <remove> no tema ................ %s\n" "$(grep -rho '<remove ' $T --include=*.xml 2>/dev/null | wc -l)"
printf "  <referenceBlock> no tema ........ %s\n" "$(grep -rho '<referenceBlock ' $T --include=*.xml 2>/dev/null | wc -l)"
printf "  <referenceBlock remove=\"true\" .. %s\n" "$(grep -rho '<referenceBlock[^>]*remove="true"' $T --include=*.xml 2>/dev/null | wc -l)"
echo "  --- por arquivo (top 10) ---"
grep -rc '<remove ' $T --include=*.xml 2>/dev/null | grep -v ':0' | sort -t: -k2 -rn | head -10 | sed 's|.*/|    |'

echo
echo "======== 3) CSS/JS declarados por layout ========"
printf "  <css> no tema ....... %s\n" "$(grep -rho '<css ' $T --include=*.xml 2>/dev/null | wc -l)"
printf "  <script> no tema .... %s\n" "$(grep -rho '<script ' $T --include=*.xml 2>/dev/null | wc -l)"
echo "  --- por arquivo (top 8) ---"
for k in css script; do
  grep -rc "<$k " $T --include=*.xml 2>/dev/null | grep -v ':0' | sort -t: -k2 -rn | head -4 | sed "s|.*/|    [$k] |"
done

echo
echo "======== 4) layout em lugar ERRADO (modulo vs tema) ========"
echo "  --- layout de modulo dentro do TEMA (deveria estar no modulo) ---"
for d in $(ls $T 2>/dev/null | grep -E '^[A-Z][A-Za-z]+_'); do
  N=$(ls $T/$d/layout/*.xml 2>/dev/null | wc -l)
  [ "$N" -gt 0 ] && printf "    %-34s %s arquivo(s)\n" "$d" "$N"
done

echo
echo "======== 5) blocos com ttl / cache_lifetime ========"
printf "  <block ttl=... ......... %s\n" "$(grep -rho '<block[^>]*ttl=' $T $MOD --include=*.xml 2>/dev/null | wc -l)"

echo
echo "======== 6) XML invalido ou sem cabecalho ========"
NOK=0
for F in $(find $T $MOD -name "*.xml" -path "*/layout/*" 2>/dev/null); do
  xmllint --noout "$F" 2>/dev/null || { printf "    *** XML INVALIDO: %s ***\n" "$(echo $F | sed 's|.*/||')"; NOK=$((NOK+1)); }
done
printf "  arquivos de layout: %s · invalidos: %s\n" "$(find $T $MOD -name '*.xml' -path '*/layout/*' 2>/dev/null | wc -l)" "$NOK"
