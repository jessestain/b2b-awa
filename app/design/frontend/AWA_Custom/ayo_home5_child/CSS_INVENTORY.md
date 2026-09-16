# CSS_INVENTORY.md — Inventário de Arquivos CSS
**AWA Motos · ayo_home5_child · web/css/**  |  **Última atualização:** 2026-08-19 (A7 fatia 11 — Magento carrinho)

> **Índice de padronização:** [`_documentacao/PADRONIZACAO_LAYOUT.md`](../../../../../_documentacao/PADRONIZACAO_LAYOUT.md).
> Este arquivo é o **contrato vigente** (o que carrega hoje). Plano operacional: `_documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md`.

**Legenda:** `SYNC` carregamento síncrono | `ASYNC` via fila `__awaCssQ` (media=print→all) | `GATE` retido pelo paint gate até interação/timeout | `XML` via layout XML | `LOADER` via `awa-align-grid-terminal-loader.phtml`

---

## Contrato SSOT de cascata (padronização 2026-08-10)

Autoridade por camada (uma autoridade por superfície; última camada vence):

| Camada | Papel | Fonte canônica |
|---|---|---|
| 1 Tokens | Cor, espaço, raio, tipografia | **Fonte LESS:** `_awa-variables.less` + `_tokens.less`. **Runtime:** ver [Autoridade de token por rota](#autoridade-de-token-por-rota-a7). Não assumir que o LESS pinta a home. |
| 2 Fundação | Magento compilado | `styles-m` / `styles-l` (via head-preload; removidos do merge XML). Home **não** os carrega como `<link>`; PLP/PDP/cart carregam só `styles-l.min.css`. |
| 3 Global AWA | Base storefront | `awa-super-global` → layout-bundle → refine → carousel/head-tail |
| 4 Shell de família | Uma folha por superfície | ver tabela abaixo |
| 5 Delta de rota | Só regras exclusivas da página | `pages-*.css`, `b2b/*.css`, loaders críticos |
| 6 Terminal | Grid/locks finais | `awa-align-grid-terminal-2026-06-11` + `awa-m2-visual-ssot` |
| 7 Design system | Override visual unificado | `awa-design-system.css` (último `<css>` do XML; shell/terminal podem vir depois via loader) |

### Shell único por superfície (não criar paralelo)

| Superfície | Shell SSOT | Não usar como 2ª autoridade |
|---|---|---|
| Global / header | locks em `HeaderImpeccableCascadeLockCss` + design-system | patches datados novos |
| PLP | styles-l + refine + align-grid + ssot | `awa-plp-distill` aposentado em `awa-head-preload.phtml` + no-op/strip no plugin (aplicar com reload php-fpm em produção) |
| PDP | styles-l + pdp shell/refine + align-grid + ssot | segundo distill lock sem necessidade |
| Cart | `awa-cart-stack` | folhas premium só como delta |
| Checkout | `awa-checkout-shell-final` | loader não deve duplicar o mesmo href |
| B2B auth | `awa-b2b-auth-shell-final` | declarar polish/vfix só no shell, não repetir no register |
| B2B account | `awa-b2b-account-shell-final` | `pages-core` + `pages-*` como delta |
| CMS | `awa-cms-shell-final` | institucional pai só via herança |

### Política de tokens / `!important`

- Cor/espaço/radius: sempre `var(--awa-*)` em **código novo** (fallback hex só temporário). Runtime hoje: ver [Autoridade de token por rota](#autoridade-de-token-por-rota-a7) — home/auth/PLP/PDP/cart/conta alias `--awa-brand` no vencedor.
- `!important` apenas em desarme de legado, com comentário de motivo.
- Layout page attribute auth B2B: sempre `empty` (login/register/forgot/claim/success).
- Conta B2B: `2columns-left` + handle `customer_account`.

---

## Cascata vigente (ordem de prioridade, última ganha)

| # | Arquivo | Via | Modo |
|---|---|---|---|
| 1 | `styles-m.css` / `styles-l.css` | merge Magento | SYNC |
| 2 | `themes.css` (merge herdado do tema pai) | merge Magento | SYNC |
| 3 | `awa-super-global-20260611m.min.css` | `awa-head-preload.phtml` (`SUPER_GLOBAL_CSS_FILE`) | SYNC |
| 4 | `awa-defer-global-bundle.min.css` | `awa-head-preload.phtml` | ASYNC |
| 5 | `awa-third-party-bundle.min.css` | `awa-head-preload.phtml` | GATE |
| 6 | `awa-layout-bundle-20260611m.min.css` | `awa-head-preload.phtml` | ASYNC |
| 7 | `awa-commerce-impeccable-refine.min.css` | `awa-head-preload.phtml` (`REFINE_CSS_FILE`) | ASYNC |
| 8 | `awa-carousel-bundle.min.css`, `awa-head-tail-bundle.min.css` | `awa-head-preload.phtml` | ASYNC |
| 9 | `awa-align-grid-terminal-2026-06-11.min.css` | LOADER (7 layouts) + head-preload (PDP/checkout) | SYNC |
| 10 | `awa-m2-visual-ssot.min.css` | LOADER — SSOT final (rating/shelf/carousel/cards/badge) | SYNC |
| 11 | `css/awa-design-system.css` | `default_head_blocks.xml:210` — último `<css>` do XML; **não** é o último `<link>` nas rotas (preload/loaders entram depois) | SYNC |

> Os antigos bundles `awa-bundle-core/category/phases/refinements` **não existem mais** (fundidos/migrados). `awa-bundle-site.css` é um shim vazio proposital (437 bytes) — **não referenciar** em layout XML/PHTML novo.

> A tabela acima é a **ordem pretendida**. A ordem **publicada** (curl 2026-08-18): home = 17 `<link>` sem `styles-m`/`styles-l`; PLP/PDP/cart = `styles-l.min.css` presente, `styles-m` ausente; auth B2B = sem design-system (A6).

## Autoridade de token por rota (A7)

Medição live 2026-08-19 (fatias 3–6). Contrato = **declaração vencedora** no HTML, não o arquivo LESS.

| Rota | `styles-m` | `styles-l` | `design-system` | Último arquivo que declara `--awa-primary` | Declaração |
|---|---|---|---|---|---|
| Home | 0 | 0 (só menção na fila) | 1 (cedo, 2º `<link>`) | `awa-home-deferred-stack.min.css` | `var(--awa-brand,#b73337)` |
| PLP / busca | 0 | 1 | 1 (cedo) | `awa-impeccable-layout-2026-06-16.min.css` | `var(--awa-brand,#b73337)` |
| PDP | 0 | 1 | 1 (cedo) | `awa-impeccable-layout-2026-06-16.min.css` | idem PLP |
| Carrinho | 0 | 1 | 1 (cedo) | `awa-impeccable-layout-2026-06-16.min.css` | `var(--awa-brand,#b73337)` (`styles-l` ainda declara OKLCH **antes**) |
| CMS / contato / 404 | 0 | 1 (típico) | 1 | `awa-cms-shell-final.min.css` (loader após impeccable-layout) | `var(--awa-brand, #b73337)` |
| Auth B2B | 0 | 0 | 0 | `awa-b2b-auth-shell-final` | `--awa-primary: var(--awa-brand)` |
| Conta B2B | n/a guest | 1 (típico) | 1 | `awa-b2b-account-shell-final` (loader após align-grid) | `--awa-primary: var(--awa-brand, #b73337)` |
| Checkout OPC | n/a guest (auth wall → login B2B) | 1 (logado) | 1 | `awa-checkout-shell-final` (loader; HTML logado não medido) | `--awa-primary: var(--awa-brand, #b73337)` |

**Fonte LESS (não é runtime sozinha):** `_awa-variables.less` (151 `@awa-*`, primitivo `#b73337`) → `_tokens.less` (233 `--awa-*`, 5 `:root`) → `_extend.less` (OKLCH). Só entra no HTML via `styles-m`/`styles-l` após SCD. Home não baixa esses arquivos.

**O que a home realmente baixa para a marca:** `awa-design-system.min.css` (`--awa-brand: #b73337`; `--awa-primary: var(--awa-brand)`), depois `themes.min.css` (`var(--awa-oklch-primary)`), depois o deferred-stack declara `--awa-primary: var(--awa-brand, #b73337)` (A7 fatia 2 — deixa de sobrescrever com hex). SHA live do min = fonte do tema `f60770df…` (133 209 B). O `awa-design-system.css` não minificado em `pub/static` (186 876 B, 2026-08-10) está **stale** e não é o arquivo servido.

**Auth B2B (empty layout, Magento):** `awa-design-system` é removido no `b2b_auth_shell.xml`. SSOT de marca = lock em `awa-b2b-auth-shell-final` (`--awa-brand` hex + `--awa-primary: var(--awa-brand)`). `login.css` `:root` mantém literais hex (Chromium: `var(--x, #hex)` dentro de `color-mix()` invalida o resto do `:root`). `cta-lock-20260815.css` **não** redeclara tokens — só pinta CTAs. Crítico inline (`awa-b2b-auth-critical-css.phtml`) continua com hex no 1º paint. Superfície slate `#111827` **não** é `--awa-ink` `#333`.

**Catálogo Magento + B2B (fatia 3):** o vencedor live em PLP/PDP/carrinho/busca (`catalogsearch`, query sem redirect para PLP) é `awa-impeccable-layout-2026-06-16` (`var(--awa-brand,#b73337)`), não o super-global nem o `styles-l` OKLCH. O super-global deixou de apontar `--awa-primary` ↔ `--awa-sg-c26` em ciclo. `login-to-cart.css` (módulo B2B) não usa mais o token quebrado `--aw-primary` nem o hex `#A33B3B`. Conta logada: lock no `awa-b2b-account-shell-final` (padrão Magento `2columns-left`).

**CMS + checkout + leftovers B2B (fatia 4):** `/contact` e `/politica-de-privacidade` vencem em `awa-cms-shell-final`. Checkout guest redireciona para `/b2b/account/login/` — evidência do token = CSS publicado (`awa-checkout-shell-final.min.css`) + handle `.checkout-onepage-success` no mesmo lock. Módulo B2B: fallback `#A33B3B` / `--aw-primary` removidos de `premium-blocks`, `dashboard-combined`, `register`, `landing`, `suggestion`, `b2b-marketing`; `.min.css` de `login-to-cart` sincronizado com a fonte da fatia 3.

**Módulos Magento storefront + B2B (fatia 5):** `CookieConsent` (`--cookie-c2` → `--awa-brand`), `SocialProof`, `ProductIntelligence` e `FakePurchase` deixam `--aw-primary` / `#A33B3B`. Gráfico da conta B2B (`performance-charts.js`) usa `#b73337`. Magento `/customer/account/create/` redireciona para `/b2b/register/` (auth-shell). 404 Magento vence em `awa-cms-shell-final`. `FakePurchase` CSS não está em `pub/static` (widget não aparece no HTML guest).

**Higiene Magento nativo + cookie runtime (fatia 6):** `Magento_Customer/.../register.phtml` no tema filho não vaza mais `:root { --cust-reg-c1: #A33B3B }` (a rota nativa 302 → `/b2b/register/`). Login/forgot/create Magento e wishlist redirecionam para o auth-shell B2B. `default_head_blocks.xml` **remove** `cookie-consent.css` do head; hover live do Permitir = `awa-cookie-consent-fix` (`--awa-primary-hover`). Cópia inlined em `awa-interaction-widgets` (CMS/conta, não home/PLP) e fonte do módulo usam `--cookie-c2/--cookie-c5` → `--awa-brand` / `--awa-brand-dark`. E-mail AbandonedCart: preço `#b73337` (e-mail não resolve CSS vars). `FakePurchase` continua desligado em `config.php`.

**E-mails Magento + B2B (fatia 7):** `design/email/header_template` = `design_email_header_template` (arquivo do tema, não ID no banco). Chrome Magento `Magento_Email/email/header.html` + `footer.html`: `#D52F33` → `#b73337`. Os 10 templates no banco (pedido/fatura/envio/senha/newsletter/contato) **não** trazem hex de marca no corpo — usam o header/footer. `email.min.css` já pintava links `#b73337`. CTAs B2B guest/cliente que ainda estavam em azul Material (`#2563eb` / `#1a73e8`) passaram à marca. Cabeçalhos semânticos (aprovado teal, proposta verde, pendência amber, perigo) **não** foram recoloridos. Adminhtml e e-mails ERP **não** tocados. Sem SCD.

**Checkout/header B2B Magento (fatia 8):** `suggestion.css` / `b2b-marketing.css` deixam o fallback Luma/Material (`#1979c3` / `#1976d2` / `rgb(25 118 210)`) — `--b2b-mkt-sug-c14` / `--b2b-mkt-c14` apontam `--awa-brand`. Badge **Atacado** no painel do header (`StatusPanel` + `B2bPanel`) usa `#b73337`; VIP roxo, pendente amber e revendedor verde permanecem semânticos. `suggestion.css` entra via handle Magento `b2b_marketing_styles` (checkout success / create; **não** na home — o head-preload omite). Sem SCD.

**Header B2B Magento + anel de foco (fatia 9):** o módulo `status-panel.css` (carrega via `awa-header-contact-links.phtml` em PLP/PDP/CMS/conta; **não** na home — `cms_index_index.xml` remove e o HTML guest não tem o href) deixa hover `#c53d42` / `#9a2c2f` / `#6d1d1f` — `--b2b-primary*` consome `--awa-primary` / `--awa-brand` / `--awa-brand-dark`. Overlay do tema `awa-b2b-status-panel.css` já usava `--awa-primary`. Anel live continua o inline `awa-focus-visible-inline` + guard do plugin (`var(--awa-primary,#b73337)`); `awa-focus-visible.phtml` e o `.min.css` publicado alinham o fallback circular. Skip-link: **outline** de marca; fundo slate `#111827` e **cor** `#2563eb` em `awa-visual-fixes` (exceção a11y) **não** mudam. Sem SCD; sem `cache:clean`.

**PHTML Magento mortos — anel de foco (fatia 10):** templates fora do layout XML (`newsletter_popup` e `awa.dark.mode.toggle` com `remove="true"`; `awa-pdp-qa` / `awa-quick-order` / `no-route.phtml` sem handle; `awa-whatsapp-microcopy` não é o FAB live — o botão é `GrupoAwamotos_Theme::core/whatsapp-btn.phtml`) passam `#2563eb` do `:focus` / `:focus-visible` a `var(--awa-primary, #b73337)`. Verde WhatsApp `#25d366` permanece. `trust-stack.phtml` **não** alterado: `#2563eb` no SVG é paleta semântica (junto com verde/âmbar/vermelho). HTML guest: zero `#2563eb`. Sem SCD; sem `cache:clean`.

**Carrinho Magento (fatia 11):** `checkout_cart_index` carrega `awa-cart-stack.min.css` (SSOT do grid/chrome). O stack **não** declara `--awa-primary` (vencedor continua `awa-impeccable-layout-2026-06-16`). Chrome da barra mobile / skip-summary / foco deixou `--awa-red` / `--awa-red-dark` (vencedor live = OKLCH do `styles-l`) e consome `--awa-brand` / `--awa-brand-dark`. Preço `.col.price` / `.col.subtotal`: fallback circular `var(--awa-primary, var(--awa-primary))` → `var(--awa-primary, #b73337)`. Shim legado `awa-cart-shell-final` (órfão no layout; HTML guest **sem** href; ainda 200 em `pub/static` para URLs stale) alinha os 13 fallbacks circulares. Sem SCD; sem `cache:clean`.

**Proibido nesta fase**

- Novo `:root { --awa-primary: … }` em bundle, critical ou plugin.
- Assumir que editar `_extend.less` + SCD altera a home.
- Recarregar `styles-m` na home só para “ativar o LESS” (LCP/TBT).
- Tratar o `.css` não minificado de `pub/static` como fonte do design-system.

**Arquivos LESS que também emitem `:root` (dívida A7+):** `_design-system.less` (7), `_tokens.less` (5), `_awa-color-system.less`, `_awa-effects-system.less`, `_awa-typography-system.less`. 214 tokens `--awa-*` redefinidos em ≥3 folhas compiladas. Comentário falso: `@awa-color-dark` é `#333333`, textos ainda dizem `#1a1a1a`.

## Via `default_head_blocks.xml` (globais)

| Linha | Arquivo |
|---|---|
| 181 | `css/awa-visual-fixes-2026-06-29-final.css` (desde 2026-08-10 inclui ex-`visual-noise` + ex-`cookie-fab-collision` — seções MERGED) |
| 210 | `css/awa-design-system.css` (último) |

> Consolidação P2 (2026-08-10): `awa-visual-noise-2026-07-15-r2.css` e `awa-cookie-fab-collision-fix-2026-07-08.min.css` fundidos em `awa-visual-fixes-2026-06-29-final.css` e removidos deste XML. Fontes preservadas em `web/css/` (referência do `build-awa-home-deferred-stack.sh`, que mantém cópias inline no stack da home).

O mesmo XML remove ~20 CSS legados (`themes5.css`, `styles-m/l` originais, `awa-core.css`, `awa-grid-unified.css`, etc.).

## Condicionais por rota (via `awa-head-preload.phtml`)

| Rota | Folhas extras |
|---|---|
| Home | `awa-head-preload-critical-home.min.css`, `awa-home-polish-critical.min.css`, `awa-home-launches-toggle-fix.min.css` + CSS crítico inline anti-FOUC |
| PLP/busca | `awa-plp-critical-fixes.min.css` (media=all) |
| PDP/checkout | `awa-align-grid-terminal-2026-06-11.min.css` (também via LOADER) |
| Checkout/cart/B2B-auth | `$__skipLegacyFlowCss` — pulam CSS legado de fluxo |

O loader `awa-align-grid-terminal-loader.phtml` é referenciado em: `cms_page_view.xml`, `onepagecheckout_index_index.xml`, `catalog_product_view.xml`, `customer_account.xml` (B2B), `checkout_index_index.xml`, `checkout_onepage_success.xml`, `checkout_cart_index.xml`.

## Dependências de nome (⚠️ não renomear sem grep prévio)

`OptimizeHeadStylesPlugin.php` (`app/code/GrupoAwamotos/Theme/Plugin/Response/`), `HeaderImpeccableCascadeLockCss.php` e `HomeCssGateParity.php` fazem match por **nome de arquivo via regex** para injeção/dedup/paint-gate (~15 pontos hardcoded). `awa-align-grid-terminal-2026-06-11` é o nome mais acoplado. O helper `__emitDeferredCss` do head-preload **omite silenciosamente** links de arquivos ausentes em disco — rename/movimentação falha sem erro.

## Tokens

Ver [Autoridade de token por rota (A7)](#autoridade-de-token-por-rota-a7). Resumo:

- Primitivos LESS: `source/_awa-variables.less` (`@awa-color-primary: #b73337`).
- Custom properties no compile Magento: `source/_tokens.less` — **não** é o que a home aplica por último.
- Runtime home: última declaração `var(--awa-brand,#b73337)` em `awa-home-deferred-stack.min.css`.
- Runtime PLP/PDP/cart: última declaração `var(--awa-brand,#b73337)` em `awa-impeccable-layout-2026-06-16.min.css`.
- ⚠️ `--awa-*` duplicados em `:root` de dezenas de bundles (`awa-super-global` ~1100 defs, critical-home, head-tail, impeccable-layout, design-system…).

## LESS sources

- `source/_extend.less`: ~2147 linhas; **31** `@import` ativos + blocos `#html-body` inline (§36–§55); ~101 imports comentados.
- ~141 arquivos `.less` em `source/` **órfãos** do grafo do `_extend.less` — candidatos a `_disabled/` (C4, não A7 fatia 1).
- `source/_awa-flex-grid-flow.less` é fonte órfã; o compilado `awa-flex-grid-flow.min.css` **não é carregado** (quarentena 2026-08-13)

## Estado do diretório (2026-08-13)

- `web/css/`: fonte do tema; Magento publica o diretório inteiro no static deploy
- Subdirs: `b2b/`, `layers/`, `source/`, `_deprecated/`, `pub/`
- Regeneração de pares min: `scripts/build-awa-css-min-pair.sh`; sidecars: `scripts/check-awa-static-sidecars.sh`
- Limpeza Fase 11 (2026-08-13): 11 arquivos mortos saíram de `web/css/` para `_quarantine/css-dead-2026-08-13/` (backup `/home/deploy/backups/awa-css-dead-2026-08-13/`). `pub/static` não foi alterado. Sem `cache:flush` / SCD nesta onda.
- 2026-08-13 GO JS: injetor `injectHomeStandardizeTerminal` removido; `awa-css-gate.min.js` minificado (terser) e publicado; query `20260813-retire-standardize`; `awa-home-standardize-terminal-wins-2026-06-09.min.css` quarentenado.

## Dívida conhecida (ordem de ataque)

0. **A7:** home/auth/PLP/PDP/cart/busca/CMS/404/conta apontam `--awa-primary` a `--awa-brand` no vencedor live; checkout via shell publicado (guest = auth wall). Chrome do carrinho (`awa-cart-stack`) consome `--awa-brand` (não o `--awa-red` OKLCH do `styles-l`). Módulos storefront (cookie, social proof, rexis) consomem `--awa-brand`. `styles-l` ainda **declara** OKLCH no meio da cascata. LESS compilado não pinta a home; `pub/static` `awa-design-system.css` diverge do `.min.css` servido.
1. ~10 bundles datados concentram ~44% dos ~100k `!important` do tema (`awa-align-grid-terminal`, `awa-defer-global-bundle`, `awa-home-deferred-stack`, `awa-layout-bundle-20260611m`, `awa-commerce-impeccable-refine`…)
2. ~~4 pares `.css`+`.min.css` referenciados simultaneamente~~ **verificado 2026-08-10**: home = padrão intencional `media="print"`+`<noscript>` fallback; achado real = `styles-l.min.css` duplicado na PLP/PDP (um com `media="(min-width:768px)"`, outro **sem media query** → CSS desktop aplicado no mobile; origem provável: `OptimizeHeadStylesPlugin.php` — corrigir na P2 junto com o plugin)
3. 130 blocos `<style>` injetados via PHP + 89 em PHTML (`awa-head-preload.phtml` tem 16)
4. ~8,8k hex fora de tokens; ~200 LESS órfãos
5. Risco operacional: `bin/deploy-static` faz `rm -rf pub/static/frontend` antes de redeployar → janela de minutos com HTML cacheado apontando para `version{N}` apagado (site sem CSS para novos acessos até o flush final). Melhoria: deploy em novo version dir + troca atômica do symlink
6. Flaky conhecidos na suíte visual-core: Menu (clip de largura variável) e Cards (offset vertical ~20px oscilante por altura dinâmica acima do clip) — estabilizar clip/máscara na P1; PDP tem contador "N pessoas visualizaram" dinâmico dentro do clip (mascarar)
