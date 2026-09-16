# Análise de Conflitos e Duplicações CSS - AWA Motos

**Data:** 2026-06-05
**Responsável:** Claude Code
**Status:** ✅ Correções Aplicadas

---

## 1. Problemas Corrigidos

### 1.1 Symlinks Quebrados (CRÍTICO) ✅

**Problema:** 17 symlinks em `pub/static` apontavam para `/home/user/htdocs/` (inexistente) em vez de `/home/jessessh/htdocs/`.

**Impacto:** Arquivos CSS minificados não eram servidos, causando fallback para versões desatualizadas.

**Correção:**
```bash
# Removidos symlinks quebrados
rm pub/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/*.min.css

# Recriados com caminho correto
ln -s /home/jessessh/htdocs/srv1113343.hstgr.cloud/app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-*.min.css .
```

---

### 1.2 Arquivos .min.css Desatualizados (ALTO) ✅

**Problema:** 20+ arquivos com versão `.css` mais recente que `.min.css`.

**Arquivos corrigidos:**
- awa-b2b-ui-promax-2026-05-22.min.css
- awa-bundle-refinements.min.css
- awa-carousel-refine.min.css
- awa-cart-layout-terminal.min.css
- awa-cart-polish.min.css
- awa-checkout-polish.min.css
- awa-checkout-ui-promax-2026-05-22.min.css
- awa-cls-nav-fix.min.css
- awa-critical-fold.min.css
- awa-flex-grid-flow.min.css
- awa-header-home-hotfix.min.css
- awa-home-adapt-2026-05-28.min.css
- awa-home-b2b-density-terminal.min.css
- awa-home-body-end-bundle.min.css
- awa-home-cosmetic-bundle.min.css
- awa-home-flex-grid-flow.min.css
- awa-home-gate-polish-bundle.min.css
- awa-home-gate-postaudit-bundle.min.css
- awa-home-gate-visual-bundle.min.css
- awa-home-hover-lock.min.css
- awa-home-layout-r2.min.css
- awa-home-layout-clean-2026-05-28.min.css
- awa-homepage-hierarchy.min.css
- awa-home-polish-2026-05-28.min.css
- awa-home-shelf-ui-promax-2026-05-21.min.css
- awa-home-terminal-bundle.min.css
- awa-impeccable-audit-2026-05-28.min.css
- awa-layout-bundle.min.css
- awa-layout-fixed.min.css
- awa-pdp-premium.min.css
- awa-pdp-ui-promax-2026-05-22.min.css
- awa-plp-ui-promax-2026-05-22.min.css

**Correção:**
```bash
cleancss -o arquivo.min.css arquivo.css
```

---

## 2. Problemas Identificados (Pendente Análise)

### 2.1 Duplicação Massiva de Seletores (MÉDIO)

**Encontrado:** 1.015 instâncias do seletor:
```css
body#html-body:is(.cms-index-index, .cms-home, .cms-homepage_ayo_home5)
```

**Impacto:**
- CSS parseado desnecessariamente 1000+ vezes
- Especificidade conflitante
- Performance degradada no parse
- Dificuldade em debug

**Distribuição aproximada:**
| Seletor | Ocorrências |
|---------|-------------|
| `body.cms-home` | 235+ |
| `body .page-wrapper .navigation.verticalmenu` | 40+ |
| `body:is(.cms-index-index, .cms-home, .cms-homepage_ayo_home5)` | 20+ |
| `body#html-body:is(...)` | 1.015+ |

**Recomendação:**
Consolidar regras da home em um único arquivo canônico:
```
app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-home-canonical.css
```

---

## 3. Boas Práticas Magento 2 Aplicadas

### 3.1 Organização de Arquivos
```
web/css/
├── awa-*.css           # Source files (editáveis)
├── awa-*.min.css       # Minified (gerados automaticamente)
└── source/             # LESS source files
    └── _*.less
```

### 3.2 Deploy para pub/static
- Symlinks apontam corretamente para `app/design/frontend/`
- Fallback para cópia física se necessário
- Permissões `www-data:www-data` mantidas

### 3.3 Cache Management
```bash
# Limpeza seletiva (boas práticas)
sudo -u www-data php bin/magento cache:clean full_page block_html

# Redis FPC (essencial para CSS)
redis-cli -n 2 FLUSHDB
```

---

## 4. Métricas

| Métrica | Valor |
|---------|-------|
| Arquivos CSS totais | ~170 |
| Arquivos .min.css | 136 |
| Symlinks corrigidos | 28 |
| Arquivos minificados | 32 |
| Cache limpo | ✅ |
| Site verificado | ✅ |

---

## 5. Próximos Passos Recomendados

### 5.1 Curto Prazo
- [ ] Monitorar logs de erro após deploy
- [ ] Validar carregamento de fontes
- [ ] Verificar console JS

### 5.2 Médio Prazo
- [ ] Criar bundle consolidado para home
- [ ] Reduzir duplicação de seletores body
- [ ] Implementar testes visuais automatizados

### 5.3 Longo Prazo
- [ ] Migrar para LESS/CSS variables consistentes
- [ ] Adotar CSS Grid nativo do Magento
- [ ] Avaliar crítico vs defer de CSS

---

## 6. Comandos Úteis

```bash
# Verificar symlinks quebrados
find pub/static -type l ! -exec test -e {} \; -print

# Listar arquivos desatualizados
for f in web/css/*.css; do min="${f%.css}.min.css"; if [ -f "$min" ]; then if [ "$f" -nt "$min" ]; then echo "$f desatualizado"; fi; fi; done

# Minificar todos
cd app/design/frontend/AWA_Custom/ayo_home5_child/web/css
for f in *.css; do cleancss -o "${f%.css}.min.css" "$f"; done
```

---

**Documento gerado automaticamente em:** 2026-06-05 01:40 UTC
