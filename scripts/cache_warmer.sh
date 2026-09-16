#!/usr/bin/env bash
# AWA Cache Warmer v3.0 — pré-aquece páginas críticas após cache flush
# Uso: bash scripts/cache_warmer.sh [concurrency]
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# Pausar warm-up enquanto Cursor IDE está ativo (dev remoto)
if [[ -f "$ROOT_DIR/var/tmp/cursor-dev-active" && "${AWA_FORCE_CACHE_WARM:-0}" != "1" ]]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cache warmer adiado — Cursor dev ativo" >> var/log/cache_warmer.log
    exit 0
fi

CONCURRENCY="${1:-5}"
# Limitar paralelismo para não saturar CPU com Cursor aberto
if pgrep -u deploy -f 'cursor-server|extensionHost' >/dev/null 2>&1; then
    CONCURRENCY=1
fi
LOG="var/log/cache_warmer.log"
UA='AwaMotos-CacheWarmer/3.0'

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cache warmer iniciado (concurrency=$CONCURRENCY)" >> "$LOG"

# ── FASE 0: Pre-warm merged CSS via backend direto ───────────────────────────
# Força geração do merged CSS (1.2s cold) ANTES de aquecer o Varnish.
# Sem isso, a primeira requisição Varnish de cada URL ainda demora 1.2s.
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Fase 0: pre-warm merged CSS (backend direto)..." >> "$LOG"
PRE_WARM_URLS=(
    "http://127.0.0.1:8088/"
    "http://127.0.0.1:8088/retrovisores.html"
    "http://127.0.0.1:8088/bauletos.html"
)
for purl in "${PRE_WARM_URLS[@]}"; do
    curl -sk -o /dev/null --max-time 30 \
        -H "Host: awamotos.com" \
        -H "X-Forwarded-Proto: https" \
        -H "User-Agent: $UA-PreWarm" \
        "$purl" 2>/dev/null || true
done
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Fase 0: pre-warm concluído" >> "$LOG"

# ── Pós Fase 0: pré-comprimir brotli do merged CSS recém-gerado ───────────────
# Sem -newer: comprime todos .min.css sem .br (idempotente, sem dependência de stamp)
if command -v brotli &>/dev/null; then
    find "$ROOT_DIR/pub/static/_cache/merged" -name "*.min.css" ! -name "*.br" 2>/dev/null | while read -r css; do
        brotli -q 6 -f "$css" -o "${css}.br" 2>/dev/null || true
    done || true

    # Tema AWA: regenerar .br stale (min.css mais novo OU tamanho descomprimido diverge)
    STATIC_CSS="$ROOT_DIR/pub/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/css"
    if [[ -d "$STATIC_CSS" ]]; then
        find "$STATIC_CSS" -maxdepth 1 -name '*.min.css' 2>/dev/null | while read -r css; do
            br="${css}.br"
            need=0
            if [[ ! -f "$br" ]]; then
                need=1
            elif [[ "$css" -nt "$br" ]]; then
                need=1
            else
                orig_size=$(wc -c < "$css" | tr -d ' ')
                br_size=$(brotli -d -c "$br" 2>/dev/null | wc -c | tr -d ' ' || echo 0)
                if [[ "$orig_size" != "$br_size" ]]; then
                    need=1
                fi
            fi
            if [[ "$need" -eq 1 ]]; then
                brotli -q 6 -f "$css" -o "$br" 2>/dev/null || true
                chown www-data:www-data "$br" 2>/dev/null || true
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Brotli regen: $(basename "$css")" >> "$LOG"
            fi
        done || true
    fi
fi
# ─────────────────────────────────────────────────────────────────────────────

# Páginas fixas prioritárias
STATIC_URLS=(
    "https://awamotos.com/"
    "https://awamotos.com/retrovisores.html"
    "https://awamotos.com/bagageiros.html"
    "https://awamotos.com/bauletos.html"
    "https://awamotos.com/guidoes.html"
    "https://awamotos.com/piscas.html"
    "https://awamotos.com/lentes.html"
    "https://awamotos.com/carcacas.html"
    "https://awamotos.com/estribos.html"
    "https://awamotos.com/barras-de-guidao.html"
    "https://awamotos.com/retrovisores/linha-original.html"
    "https://awamotos.com/retrovisores/cromados.html"
    "https://awamotos.com/bauletos/bauletos-34-l.html"
    "https://awamotos.com/bauletos/bauletos-41-l.html"
    "https://awamotos.com/catalogsearch/result/?q=bagageiro"
    "https://awamotos.com/catalogsearch/result/?q=retrovisor"
    "https://awamotos.com/catalogsearch/result/?q=titan+160"
    "https://awamotos.com/catalogsearch/result/?q=cg+160"
    "https://awamotos.com/catalogsearch/result/?q=bauleto"
    "https://awamotos.com/catalogsearch/result/?q=guidao"
    "https://awamotos.com/catalogsearch/result/?q=pisca"
    "https://awamotos.com/catalogsearch/result/?q=lente"
    "https://awamotos.com/catalogsearch/result/?q=carcaca"
)

# URLs dinâmicas do banco
DB_USER=$(grep -oP "(?<='username' => ')[^']+" app/etc/env.php | head -1 2>/dev/null || echo "magento")
DB_PASS=$(grep -oP "(?<='password' => ')[^']+" app/etc/env.php | head -1 2>/dev/null || echo "")
DB_NAME=$(grep -oP "(?<='dbname' => ')[^']+" app/etc/env.php | head -1 2>/dev/null || echo "magento")

DB_URLS=$(mysql -u "$DB_USER" -p"$DB_PASS" \
    --socket=/var/run/mysqld/mysqld.sock "$DB_NAME" -sNe "
    -- TODAS as categorias ativas (até 2 níveis de profundidade)
    (SELECT CONCAT('https://awamotos.com/', ur.request_path)
     FROM url_rewrite ur
     JOIN catalog_category_entity cce ON cce.entity_id = ur.entity_id AND cce.level >= 2
     JOIN catalog_category_entity_int cce_active ON cce_active.entity_id = cce.entity_id AND cce_active.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='is_active' AND entity_type_id=3 LIMIT 1) AND cce_active.store_id = 0 AND cce_active.value = 1
     LEFT JOIN catalog_category_entity_int cce_store_active ON cce_store_active.entity_id = cce.entity_id AND cce_store_active.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='is_active' AND entity_type_id=3 LIMIT 1) AND cce_store_active.store_id = 1
     WHERE ur.entity_type='category'
     AND ur.store_id=1 AND ur.redirect_type=0
     AND (cce_store_active.value IS NULL OR cce_store_active.value = 1)
     AND ur.request_path NOT LIKE '%-erp-%'
     AND (LENGTH(ur.request_path) - LENGTH(REPLACE(ur.request_path, '/', ''))) <= 1
     ORDER BY cce.level ASC, ur.request_path ASC LIMIT 80)
    UNION ALL
    -- Top 50 produtos ativos COM estoque
    (SELECT CONCAT('https://awamotos.com/', ur.request_path)
     FROM url_rewrite ur
     JOIN catalog_product_entity_int cpes ON cpes.entity_id = ur.entity_id
         AND cpes.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
         AND cpes.store_id = 0 AND cpes.value = 1
     JOIN cataloginventory_stock_status css ON css.product_id = ur.entity_id AND css.stock_status = 1
     WHERE ur.entity_type='product'
     AND ur.store_id=1 AND ur.redirect_type=0
     AND ur.request_path NOT LIKE '%-erp-%'
     ORDER BY ur.entity_id DESC LIMIT 50);" 2>/dev/null || true)

TMPF=$(mktemp)
printf '%s\n' "${STATIC_URLS[@]}" > "$TMPF"
echo "$DB_URLS" >> "$TMPF"
# Remover linhas vazias e duplicatas
sort -u "$TMPF" -o "$TMPF"
# Remover linhas vazias
grep -v '^[[:space:]]*$' "$TMPF" > "${TMPF}.clean" && mv "${TMPF}.clean" "$TMPF"

TOTAL=$(wc -l < "$TMPF")

warm_one() {
    local url="$1"
    local code
    code=$(curl -sk -L -o /dev/null -w '%{http_code}' "$url" \
        --max-time 20 --user-agent "$UA" 2>/dev/null || echo "000")
    if [[ "$code" == "200" || "$code" == "301" || "$code" == "302" ]]; then
        echo "[OK] $url" >> "$LOG"
    else
        echo "[FAIL $code] $url" >> "$LOG"
    fi
    echo "$code"
}
export -f warm_one
export UA LOG

RESULTS=$(cat "$TMPF" | xargs -P "$CONCURRENCY" -I{} bash -c 'warm_one "$@"' _ {})
rm -f "$TMPF"

OK=$(echo "$RESULTS" | grep -cE '^(200|301|302)$' || true)
FAIL=$(( TOTAL - OK ))

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Warmer concluido: ${OK}/${TOTAL} OK, ${FAIL} falhas" >> "$LOG"
echo "Warmer: ${OK}/${TOTAL} OK, ${FAIL} falhas"
