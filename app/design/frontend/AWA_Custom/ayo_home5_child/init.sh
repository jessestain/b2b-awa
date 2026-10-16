#!/bin/bash
# =============================================================================
# init.sh — bootstrap/resume do trabalho de design system (AWA Motos · produção)
# Uso:  bash init.sh            → mostra estado + comandos
#       bash init.sh doctor     → checa ferramentas e caminhos
# =============================================================================
set -u
PROD=/home/user/htdocs/srv1113343.hstgr.cloud
THEME="$PROD/app/design/frontend/AWA_Custom/ayo_home5_child"
CACHETOOL=/home/deploy/awa-fixes-20261014/cachetool.phar
FPM_FCGI=127.0.0.1:19002

cat <<'EOF'
=== AWA Motos — design system (tema AWA_Custom/ayo_home5_child) ===============
Contexto: produção sem staging. Ler claude-progress.txt e feature_list.json.
Protocolo: backup → editar → deploy → caches → smoke → validação → commit por arquivo.

REGRAS CRÍTICAS
- Layouts XML são de jessessh:www-data e alguns têm chattr +i:
    sudo -n -u jessessh <cmd>        # editar como dono
    sudo -n chattr -i <arquivo>      # antes de editar se imutável
    sudo -n chattr +i <arquivo>      # restaurar depois
- NUNCA `git add` geral (repo tem nomes corrompidos/sensíveis). Sempre lista explícita.
- Hook pre-commit (bin/fix-xml-case.sh) normaliza XML — inofensivo.
- NUNCA sobrescrever _module.less, NUNCA @layer novo, NUNCA !important novo.
- .min.css são PARES PRÉ-CONSTRUÍDOS (podem divergir do fonte .css): ao consolidar,
  concatenar os BYTES SERVIDOS (.min se existir).

COMANDOS
  # deploy do tema
  cd $PROD && mv pub/static/frontend/AWA_Custom/ayo_home5_child <backup> && \
    php -d opcache.enable_cli=0 -d opcache.enable=0 bin/magento \
    setup:static-content:deploy -f pt_BR --theme AWA_Custom/ayo_home5_child
  # caches (os 3!)
  cd $PROD && php bin/magento cache:flush
  php $CACHETOOL opcache:reset --fcgi=$FPM_FCGI
  sudo -n varnishadm ban 'req.url ~ .'
  # smoke backend (evita hairpin)
  curl -s -o /dev/null -w '%{http_code}' -H 'Host: awamotos.com' http://127.0.0.1:8088/<path>
  # regressão (exige /tmp/awa-cdp/node_modules/ws + /tmp/cdp_audit.js)
  bash $PROD/scripts/ui-ds-regression.sh

BACKUPS: /home/deploy/awa-audit-backups/  (ds-spacing-*, ds-pagination2-*, ds-bundles-*)
QUARENTENA: $THEME/_quarantine/css-dead-YYYY-MM-DD/
EOF

if [ "${1:-}" = "doctor" ]; then
  echo "=== doctor ==="
  for f in "$PROD/bin/magento" "$THEME/web/css/source/_awa-variables.less" "$CACHETOOL" "/tmp/cdp_audit.js"; do
    [ -e "$f" ] && echo "OK   $f" || echo "FALTA $f"
  done
  node -v 2>/dev/null | sed 's/^/node /'
  ls /tmp/awa-cdp/node_modules/ws >/dev/null 2>&1 && echo "OK   /tmp/awa-cdp/ws" || echo "FALTA /tmp/awa-cdp (npm install ws)"
fi

