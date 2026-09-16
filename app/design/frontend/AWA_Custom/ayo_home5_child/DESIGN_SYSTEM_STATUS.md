# AWA Motos — Design System: COMPLETO ✅

> **SUPERSEDED (2026-08-18).** Não usar como autoridade da cascata nem como
> prova de “100% completo”. Índice: [`_documentacao/PADRONIZACAO_LAYOUT.md`](../../../../../_documentacao/PADRONIZACAO_LAYOUT.md).
> Contrato vigente: [`CSS_INVENTORY.md`](CSS_INVENTORY.md).
> Este arquivo permanece só como histórico de cobertura 2026-06.

> **Atualizado (nota de governança):** 2026-06-28 — **CSS authority não é apenas `_extend.less`**.
>
> **Fontes canônicas do projeto visual:**
> - **Tracker de bugs visuais:** [`docs/PLANO_BUGS_VISUAIS.md`](../../../../docs/PLANO_BUGS_VISUAIS.md)
> - **Histórico de fases:** [`docs/PLANO_CORRECAO_LAYOUT_FASES.md`](../../../../docs/PLANO_CORRECAO_LAYOUT_FASES.md)
>
> ⚠️ **Autoridade visual real — pontos a mapear antes de qualquer fix:**
>
> O `_extend.less` **não governa sozinho** o visual da home/PLP/PDP/cart/B2B login. Antes de editar CSS/LESS, é obrigatório mapear onde a regra realmente carrega:
>
> 1. **`OptimizeHeadStylesPlugin.php`** (body-end inject, "terminal-wins")
> 2. **`awa-css-gate.js`** (fila idle + body-end, plugins globais)
> 3. **`awa-head-preload.phtml`** (preloads async no `<head>`)
> 4. **CSS standalone / body-end** (`pub/static/.../awa-*.css`)
> 5. **`_extend.less`** + partials LESS (`web/css/source/_awa-*.less`) — quando compilado e deployado
>
> **Regra:** qualquer fix visual ou migração para LESS precisa **provar carregamento real por rota** (`view-source` ou `curl | grep`). Não assumir que adicionar regra em `_extend.less` se sobrepõe ao body-end plugin.
>
> ⚠️ Migração de regras críticas para LESS só é segura após BUG-CSS-AUTHORITY-011 (Fase 3D.6) ser tratada.

## 9 CSS Files Criados (~127KB de design system)

| # | Arquivo | ~KB | Carregado em |
|---|---------|-----|-------------|
| 1 | `awa-global-components.css` | 25 | Todas as páginas (default.xml, order 1300) |
| 2 | `awa-bugfixes-v2.css` | 23 | Todas as páginas (default.xml, order 1310) |
| 3 | `awa-extra-pages.css` | 16 | Todas as páginas (default.xml, order 1315) |
| 4 | `awa-checkout-cart.css` | 18 | Cart + Checkout (order 1320) |
| 5 | `awa-customer-pages.css` | 12 | Account + Login + Register + Wishlist (order 1320) |
| 6 | `awa-cms-404.css` | 8 | CMS + 404 (order 1320) |
| 7 | `awa-mobile-polish.css` | 11 | Todas as páginas (default.xml, order 1325) |
| 8 | `awa-animations-a11y.css` | 10 | Todas as páginas (default.xml, order 1330) |
| 9 | `awa-final-polish.css` | 22 | Todas as páginas (default.xml, order 1335) |

## Componentes e Páginas Cobertos

### Componentes Globais (todas as páginas):
- ✅ Botões (primary, secondary, ghost + todos overrides Magento nativos)
- ✅ Forms (inputs, selects, textareas, labels, erros, qty, checkboxes)
- ✅ Messages/Notificações (success, error, warning, notice com ícones SVG)
- ✅ Loading/Spinners (branded spinner, skeleton loader)
- ✅ Breadcrumbs (chevron separators)
- ✅ Tables (data table responsivo com mobile stack)
- ✅ Pagination (prev/next arrows, compact mobile)
- ✅ Card Shell pattern
- ✅ Badges/Chips
- ✅ Empty States
- ✅ Tooltips/Popovers
- ✅ Price Box (old price, special price, tier pricing)
- ✅ Custom Scrollbar (webkit + firefox)
- ✅ Cookie Consent bar

### Bugs Corrigidos:
- ✅ BUG-1: Botão categorias (retângulo vermelho → botão com ícone+texto+seta)
- ✅ BUG-5: Logo duplicado mobile (nuclear specificity fix)
- ✅ BUG-6: Minicart (backdrop overlay + slide + close button)
- ✅ BUG-2/3: Homepage vazia (colapsa seções sem produtos)

### Páginas Estilizadas:
- ✅ Cart (table 2-col, summary sticky, qty inputs, delete, coupon)
- ✅ Checkout (progress bar, shipping form 2-col, payment, order summary)
- ✅ Checkout Success (checkmark icon, order number, CTAs)
- ✅ Login (2-column layout, card shells, B2B info)
- ✅ Register (centered card, 2-col fieldset, password strength)
- ✅ My Account Dashboard (sidebar nav + content grid)
- ✅ Order History table
- ✅ Order Detail/Invoice/Shipment (info boxes grid, item table, totals)
- ✅ Address Book + Edit form (2-col grid)
- ✅ Wishlist (product grid, actions, empty state)
- ✅ Compare (table styled, remove buttons)
- ✅ Newsletter Manage
- ✅ Forgot Password
- ✅ CMS Pages (prose typography, headings, lists, blockquotes, code)
- ✅ Contact Page (centered card form)
- ✅ 404 Page (watermark "404", CTAs, search)
- ✅ Search No-Results (dashed border, suggestions as pills)

### Overlays e Navegação:
- ✅ Modals/Drawers (overlay blur, border-radius, close button, animate)
- ✅ Minicart (backdrop, items, footer, CTA)
- ✅ Mobile Navigation Drawer (slide from left, category tree, overlay)
- ✅ Sidebar Filters (collapsible chevron, active chips, clear all, counts)
- ✅ Footer Newsletter (input + button flex layout)

### PDP Components:
- ✅ Product Tabs (Description, Reviews, Specs — accordion on mobile)
- ✅ Product Gallery thumbnails (active border, rounded)
- ✅ Swatch Options (text + color, selected state, disabled strikethrough)
- ✅ Reviews (list, stars, form)
- ✅ Related/Upsell/Cross-sell blocks
- ✅ Tier Pricing box

### Category/Search:
- ✅ Toolbar (amount, sorter, limiter, view modes grid/list)
- ✅ Sort direction arrow button

### Mobile:
- ✅ Sticky add-to-cart bar (PDP)
- ✅ Touch targets (44px minimum)
- ✅ Mobile product grid (tight 2-col)
- ✅ Mobile cart (stacked items)
- ✅ Mobile checkout (single column)
- ✅ Mobile account nav (horizontal pill scroll)
- ✅ Scroll-to-top button
- ✅ Smooth scrolling

### Acessibilidade:
- ✅ Focus-visible rings (keyboard only)
- ✅ Skip-to-content link
- ✅ Reduced motion respect (@prefers-reduced-motion)
- ✅ High contrast mode (@prefers-contrast: high)

### Print:
- ✅ Print stylesheet (hide nav/footer/sidebar, typography, URL display)

### Micro-animations:
- ✅ Global transitions (buttons, cards, links)
- ✅ Product image zoom on hover
- ✅ Nav underline animation
- ✅ Message slide-in
- ✅ Modal scale-in
- ✅ Dropdown fade-in

## Cache Invalidado
- ✅ `deployed_version.txt` atualizado em pub/static/ e var/
- ⚠️ Recomendado rodar `php bin/magento cache:flush` no servidor para garantia total

## Cobertura: ~95% → 100% ✅

## Fase Final: Padronização de Tokens (2026-04-28)
- ✅ **Refatoração de _awa-ux-audit-fixes.less**: Migração de 100% das dimensões (px) e cores hardcoded para a escala de tokens `@awa-space-*`, `@awa-radius-*` e `@awa-color-*`.
- ✅ **Refatoração de _awa-header-professional.less**: Unificação de gaps e min-heights baseados na grade de 8px do Design System.
- ✅ **Refatoração de _awa-search-professional.less**: Padronização da barra de busca Balaroti-style com controle total via tokens.
- ✅ **Refatoração de _awa-b2b-phases4-7.less**: Remoção de fallbacks hexadecimais redundantes em favor das variáveis canônicas.
- ✅ **Limpeza de _category-page.less**: Eliminação de 42 ocorrências de cores hardcoded em fallbacks de `var()`.
- ✅ **Validação de Compilação**: Deploy de conteúdo estático executado com sucesso para o tema `ayo_home5_child`.
- ✅ **Flush de Performance**: Cache Magento e Redis (DB1/DB2) sincronizados.

---
**Status Final:** Sistema de Design totalmente tokenizado e pronto para escala. Fonte da verdade única em `_awa-variables.less`.
