# Plano de Correção de Layout por Fases — Home AWA

> **SUPERSEDED (2026-08-18).** Plano mestre de fases: [`_documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md`](../../../../../_documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md).
> Índice: [`_documentacao/PADRONIZACAO_LAYOUT.md`](../../../../../_documentacao/PADRONIZACAO_LAYOUT.md).
> Este arquivo permanece só como histórico das fases 1–5 da home (2026-06).

> **Atualizado:** 2026-06-23 · Tema `AWA_Custom/ayo_home5_child`
> **Viewports QA:** 390 · 768 · 1366 · 1920

---

## Status geral

| Fase | Escopo | Status |
|------|--------|--------|
| 1 | Tokens corporativos + density grid | ✅ Concluída |
| 2 | Header rail 16px (logo ↔ conteúdo) | ✅ Concluída |
| 3 | Sticky wrapper shell padding | ✅ Concluída |
| 4 | Footer newsletter (gutter duplo) | ✅ Concluída |
| 5 | `awa-css-gate` post-audit + minify + pub/static | ✅ Concluída (2026-06-23) |
| 6 | Migrate-and-retire bundles legados → LESS | 🔄 Em andamento |

---

## Fase 1 — Tokens e density grid

**Problema:** `awa-home-density-grid-20260611.css` sobrescrevia tokens corporativos (`gutter: 10px`, `pad-compact: 4px`).

**Correção:**
- Tokens alinhados: `--awa-home-shell-gutter: 16px`, `--awa-home-pad-compact: 8px`
- Containers internos com `padding-inline: 0` (gutter só no shell)
- Carrossel categorias mobile: viewport sem `padding-inline: 8px` extra

**Arquivos:** `awa-home-density-grid-20260611.css`, `_awa-home-corporate-grid-2026-06-18.less`

---

## Fase 2 — Header rail 16px

**Problema:** Logo @ 32px vs conteúdo @ 16px (padding empilhado em `.header.awa-main-header`).

**Correção:**
- Zero-chain: `padding: 0` em shells intermediários
- Rail único: `padding-inline: 16px` só em `.awa-main-header__inner`
- `homeHeaderRailTerminalRules()` em `HeaderImpeccableCascadeLockCss.php`

**Arquivos:** `awa-align-grid-terminal-2026-06-11.css`, `awa-commerce-impeccable-refine.css`, `HeaderImpeccableCascadeLockCss.php`

---

## Fase 3 — Sticky wrapper

**Problema:** `homeHeaderRailTerminalRules()` zerava **todos** `.header-wrapper-sticky`, inclusive `.is-sticky`.

**Correção:**
- Zero-chain usa `.header-wrapper-sticky:not(.is-sticky)`
- `.is-sticky` recebe shell padding: `max(16px, calc((100% - 1280px) / 2))` + `padding-block-start: 4px`

**Evidência runtime @1440:** `logoDiff: 0`, `stickyWrap.padL: 80px`

---

## Fase 4 — Footer newsletter

**Problema:** Gutter duplo — `.footer-container` (16px) + `.awa-footer-newsletter` (+16px) → conteúdo @ 112px.

**Correção:** `padding-inline: 0` em `.awa-footer-newsletter` (gutter só no container pai).

**Evidência runtime @1440:** `footerNewsContentDiff: 0`

---

## Fase 5 — CSS Gate (`awa-css-gate.js`)

**Problema:**
1. `injectPostAuditVisualTerminal` reaplicava `padding: 0 16px` em `.header.awa-main-header` **depois** do post-gate (regressão de rail)
2. `.header-wrapper-sticky` sem `:not(.is-sticky)` zerava shell padding do sticky
3. `pub/static/.../awa-css-gate.min.js` servia o fonte completo (101 KB) em vez do minificado

**Correção:**
- Post-audit: `:not(.is-sticky)` + regra `.is-sticky` com shell padding
- `.header.awa-main-header` → `padding: 0`
- Regenerar `.min.js` via terser + `setup:static-content:deploy`

**Deploy checklist:**
```bash
cd app/design/frontend/AWA_Custom/ayo_home5_child/web/js
npx terser awa-css-gate.js -c -m -o awa-css-gate.min.js
sudo -u www-data php bin/magento setup:static-content:deploy pt_BR -f --theme AWA_Custom/ayo_home5_child
sudo -u www-data php bin/magento cache:flush
redis-cli -h ::1 -a '***' -n 2 FLUSHDB
sudo systemctl reload php8.4-fpm
```

---

## Fase 6 — Migrate-and-retire (próximo)

Seguir playbook em `DESIGN_SYSTEM_STATUS.md` § migrate-and-retire:
1. Migrar regras críticas → LESS (`_extend.less`)
2. Gate no `awa-head-preload.phtml`
3. `stripStylesheetFragments` no plugin
4. Body-end inject documentado
5. Remover da fila `awa-css-gate.js`
6. E2E: `visual-audit-awa`, `site-grid-alignment`

---

## Validação por viewport

| Viewport | Checks |
|----------|--------|
| **390** | Header mobile 96px grid, carrossel categorias alinhado, sem overflow-x |
| **768** | Tablet header 56px row, rail 16px |
| **1366** | Logo/contentLeft diff ≤ 2px, sticky shell padding ativo após scroll |
| **1920** | Shell max 1280px centrado, gutters simétricos |

**Comando auditoria:**
```bash
./scripts/awa-css-best-practices-audit.sh
cd tests/e2e && npx playwright test specs/site-grid-alignment.spec.ts --workers=1
```
