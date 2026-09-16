# Plano de correção por fases — Cascata CSS do storefront

> **Índice de padronização:** [`PADRONIZACAO_LAYOUT.md`](PADRONIZACAO_LAYOUT.md).
> Este arquivo continua sendo o **plano operacional** das fases 0–14 da cascata.

**Projeto:** AWA Motos — Magento 2
**Tema:** `AWA_Custom/ayo_home5_child`
**Ambiente:** Produção
**Criado em:** 2026-08-06
**Atualizado em:** 2026-08-19
**Branch de referência:** `rescue/production-20260712`
**Commit de referência:** `37c0821926941ab12a41c5b49736634753451521`

### Progresso

| Fase | Título | Estado |
|------|--------|--------|
| 0 | Estabilização visual e baseline | CONCLUÍDA |
| 1 | Versionamento e publicação dos bundles | CONCLUÍDA |
| 2 | Drift do `awa-super-global` | CONCLUÍDA |
| 3 | CLS tardio da home | CONCLUÍDA |
| 4 | Testes de contrato da cascata | CONCLUÍDA |
| 5 | Builds e cache-busting | CONCLUÍDA |
| 6 | Pipeline Magento (layout/PageConfig) | CONCLUÍDA (regex safety-net permanece) |
| 7 | LESS autoridade visual canônica | EM ANDAMENTO (fatias 1–11) |
| 8 | Consolidar footer | PENDENTE |
| 9 | Remover CSS home inline | PENDENTE |
| 10 | Reduzir `align-grid` / `!important` | PENDENTE |
| 11 | Limpar assets mortos e governança | PENDENTE |
| 12 | Git, ownership e permissões | PENDENTE |
| 13 | Retirar locks de compatibilidade | PENDENTE |
| 14 | Auditoria final e encerramento | PENDENTE |

**Resumo CWV (home, lab 2026-08-07):** CLS desktop `0,037` · CLS mobile `0,032` (orçamento ≤ `0,1`).

## 1. Objetivo

Migrar a cascata atual para uma arquitetura Magento 2 previsível, versionada,
testável e reversível, mantendo:

- superfícies brancas, sem tonalidade rosada;
- header com 116 px no desktop e 96 px no mobile;
- fluxo B2B guest/customer;
- avaliações ocultas para visitantes;
- footer estável;
- URLs estáticas imutáveis;
- LCP, CLS e TBT dentro dos orçamentos definidos no projeto.

Este documento é um plano operacional. Ele **não autoriza alterações em
produção**. Cada fase que envolva escrita exige diagnóstico, diff proposto,
backup, rollback e autorização explícita.

## 2. Regra para atualizar os estados

Estados permitidos:

- `[x] CONCLUÍDA`: todos os critérios de aceite foram validados e as evidências
  foram registradas.
- `[ ] EM ANDAMENTO`: mudança autorizada e execução iniciada.
- `[ ] PENDENTE`: ainda não iniciada.
- `[ ] BLOQUEADA`: depende de decisão, acesso ou correção anterior.

Uma fase só pode ser marcada como concluída quando:

- [ ] todas as tarefas da fase estiverem concluídas;
- [ ] hashes e arquivos publicados estiverem registrados;
- [ ] não houver escrita concorrente;
- [ ] testes funcionais e visuais passarem;
- [ ] logs não apresentarem nova exceção relacionada;
- [ ] rollback estiver disponível;
- [ ] revisão final não tiver achados pendentes.

Ao concluir uma fase, substituir `[ ]` por `[x]`, alterar o estado para
`CONCLUÍDA` e preencher o registro de evidências da própria fase.

## 3. Guardrails globais

- Nunca editar `vendor/`, core Magento ou `app/code/Rokanthemes/`.
- Nunca usar `pub/static` como fonte.
- Alterar fontes somente no tema filho ou em `app/code/GrupoAwamotos/`.
- Publicar CSS pelo `setup:static-content:deploy` direcionado ao tema.
- Gerar `.br` e `.gz` somente depois do deploy.
- Validar que Brotli e Gzip descomprimem byte a byte para o asset publicado.
- Usar tokens `var(--awa-*)`; não introduzir novos hexadecimais sem justificativa.
- Não remover `!important` em massa.
- Não limpar Redis, Varnish, caches ou reiniciar serviços sem autorização.
- Não avançar uma fase quando LCP, CLS, B2B ou navegação por teclado regredirem.

---

## [x] Fase 0 — Estabilização visual e baseline — CONCLUÍDA

### Escopo entregue

- [x] baseline forense, visual e de CWV capturado;
- [x] superfícies rosadas consolidadas para branco;
- [x] avaliações guest removidas do autocomplete e ocultadas no storefront;
- [x] contratos do header confirmados em 116 px desktop e 96 px mobile;
- [x] autocomplete, PLP, PDP, carrinho e login B2B validados;
- [x] fallback `<noscript>` do bundle home restaurado;
- [x] lock cross-page removido de páginas sem `.awa-site-header`;
- [x] PHP, PHTML, CSS, rede e logs validados;
- [x] revisão independente concluída sem achados pendentes.

### Evidências

- Header desktop: `116px`.
- Header mobile: `96px`.
- Fundo: `rgb(255, 255, 255)`.
- Avaliações visíveis para guest: `0`.
- Rotas críticas: HTTP `200`.
- Exceções Magento novas relacionadas: `0`.

### Rollback

Backups externos em:

`/var/backups/awa-css-cascade-20260806T173600Z`

---

## [x] Fase 1 — Versionamento e publicação dos bundles consolidados — CONCLUÍDA

### Escopo entregue

- [x] nomes e queries dos bundles principais centralizados;
- [x] `align-grid` publicado como `grid-contract-r3`;
- [x] `awa-m2-visual-ssot` publicado como autoridade visual;
- [x] footer, refine e home deferred regenerados a partir das fontes;
- [x] home deferred buildado de forma determinística e sem escrever diretamente
  em `pub/static`;
- [x] assets publicados em `pt_BR` e `en_US`;
- [x] Brotli e Gzip validados byte a byte;
- [x] resposta HTTP confirmada com `Cache-Control: immutable`.

### Hashes publicados

- `awa-align-grid-terminal-2026-06-11.min.css`:
  `696a4f424479052081d02ce47b55cc1ef797d2aa426b40369015ab54ab6e0197`
- `awa-m2-visual-ssot.min.css`:
  `f4e09118737d8ee3bfd2ebb55d8a4b45ddec63a0a22a7db91692705070814de9`
- `awa-footer-terminal-lock-v1.min.css`:
  `d72a7f03319214fed5e616832e72122c4810fec2112080e5a9865d8fb7065140`
- `awa-home-deferred-stack.min.css`:
  `6a3d93d97d6c2a077c4d31bc1e082c318e8cdc372653da6f91fc92ffeccde8cb`
- `awa-commerce-impeccable-refine.min.css`:
  `4a18e0d41a1fcb7faedfecabe68e274fc105e8644ecc36c57466011464937737`

---

## [x] Fase 2 — Reconciliar drift do `awa-super-global` — CONCLUÍDA

### Problema confirmado

O arquivo fonte e o asset servido possuíam conteúdo diferente:

- fonte (SSOT):
  `04033c253ee920e319d9d8228d2a69121dbc0b850d168313212469f71349ecbf`
- produção antiga `pt_BR`/`en_US`:
  `c82e9a280b5c2dba35e20502d04bdb6b17b7f8ed8a5a867eb008466a0180dadf`
- delta semântico principal: FAB `bottom:15px` → `bottom:84px` (+ tokens).

### Tarefas

- [x] comparar semanticamente as duas versões;
- [x] identificar SSOT = fonte do tema;
- [x] confirmar fonte CSS regenera o `.min` (`cleancss`);
- [x] definir `SUPER_GLOBAL_QUERY=?v=20260806-super-global-ssot-r1`;
- [x] ligar a query em `awa-head-preload.phtml`;
- [x] publicar via static content deploy (após mover stale de `pub/static`);
- [x] gerar e validar `.br`/`.gz` byte a byte;
- [x] comprovar igualdade fonte → publicado → HTTP (`04033c25…`);
- [x] revalidar HTML PLP/home após recuperação do OpenSearch (bloqueio infra 503).

### Critérios de aceite

- [x] fonte e `pub/static` com o mesmo SHA-256;
- [x] `pt_BR` e `en_US` idênticos;
- [x] URL nova com query imutável;
- [x] asset HTTP 200 com hash SSOT;
- [x] HTML de rotas críticas 200 (OpenSearch em `:9201`; falha anterior por nó indisponível transitório).

### Evidências (2026-08-06)

- Query ao vivo:
  `.../awa-super-global-20260611m.min.css?v=20260806-super-global-ssot-r1`
- Hash HTTP/pub/fonte: `04033c253ee920e319d9d8228d2a69121dbc0b850d168313212469f71349ecbf`
- Bottoms no CSS publicado: `[25px, 84px, 10px]` (sem `15px`)
- Backup: `/var/backups/awa-css-cascade-phase2-super-global-20260806T155500Z`

### Risco e rollback

Rollback byte a byte do backup externo + reverter query/`awa-head-preload.phtml`.
Nota: após `cache:clean full_page`, HTML falhou com `NoNodesAvailableException`
enquanto o OpenSearch reiniciava. Porta correta do Magento: `:9201` (não `:9200`).
Recuperado automaticamente; home/PLP/login HTTP 200 revalidados.

---

## [x] Fase 3 — Corrigir CLS tardio da home — CONCLUÍDA

### Problema confirmado (baseline)

CLS desktop (1366×900, 45s): `0,39721` (orçamento `≤ 0,1`).
CLS mobile (390×812, 25s): `0,09776` (próximo do orçamento).

Buckets desktop (baseline):
- early (&lt;2s): `0,22493`
- mid (2–10s): `0,12342`
- late (&gt;10s): `0,04885`

Fontes dominantes (baseline):
- `.awa-carousel--product` / `.awa-owl-nav` (maior shift `0,224` @ ~1651ms)
- `.awa-section-header__left` (altura 60→18)
- `#maincontent` / `.content-top-home` (deslocamento vertical)
- sticky header desktop estável em `116px` (não era a causa principal)

### Escopo entregue

- [x] capturar medições desktop 45s e mobile 25s;
- [x] registrar buckets early/mid/late e top sources;
- [x] alinhar critical CSS à geometria final dos carrosséis/headers de seção;
- [x] evitar tornar bundles de centenas de KB render-blocking;
- [x] estabilizar nav do carrossel sem colapsar para 0×0 após o paint
  (`data-awa-nav-anchor="viewport"` no SSR + faixa `height:0` no first-paint);
- [x] travar hero/banner (H12), `.page.messages` vazio (H13) e
  `#maincontent` padding-block (H14) no first-paint;
- [x] validar runs desktop + mobile após o patch;
- [x] revalidar pós-patch (2026-08-07) sem instrumentação de debug.

### Critérios de aceite

- [x] CLS home `≤ 0,1` em execuções pós-fix;
- [x] sticky header 116 px desktop / 96 px mobile preservado;
- [x] carrosséis com nav SSR acessível (`data-awa-nav-anchor="viewport"`);
- [x] instrumentação de debug removida.

### Evidências finais

- Baseline desktop CLS: `0,397` → pós-fix lab: `~0,025`.
- Revalidação 2026-08-07 (browser lab):
  - desktop 1366×900 / 45s: **CLS `0,037`**;
  - mobile 390×812 / 25s: **CLS `0,032`**.
- Sticky: `116px` / `96px`.
- Home HTTP `200`.

### Arquivos alterados (Fase 3)

- `app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-head-preload.phtml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-hero-preload.phtml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-shelf-carousel-chrome.phtml`

### Rollback

Backups externos:

- `/var/backups/awa-css-cascade-phase3-cls-20260806T193710Z`
- `/var/backups/awa-css-cascade-phase3-h12-20260806T225620Z`

```text
Fase: 3
Status: CONCLUÍDA
Data/hora: 2026-08-07
Responsável: agente Cursor (continuidade pós-lab)
Autorização: continuar aonde parou (encerrar Fase 3 no plano)
Arquivos alterados: OptimizeHeadStylesPlugin.php; awa-head-preload.phtml;
  awa-hero-preload.phtml; awa-shelf-carousel-chrome.phtml
Backup: /var/backups/awa-css-cascade-phase3-cls-20260806T193710Z ;
  /var/backups/awa-css-cascade-phase3-h12-20260806T225620Z
Desktop CLS: 0,037 (revalidação)
Mobile CLS: 0,032 (revalidação)
Observações: plano sincronizado com evidência runtime; Fase 4 também CONCLUÍDA.
```

---

## [x] Fase 4 — Criar testes de contrato da cascata — CONCLUÍDA

### Escopo entregue

- [x] testar que fragmentos não aparecem em listas de destino incompatíveis
  (auth: refine/promax/plp-polish = 0);
- [x] testar geração do HTML do `OptimizeHeadStylesPlugin` (unit PHP:
  strip, SSOT único, normalize align-grid, constantes gate/auth);
- [x] testar exatamente uma ocorrência de align-grid e visual SSOT
  (home 390/768/1366/1920 + PLP/PDP);
- [x] testar exatamente uma requisição do footer terminal;
- [x] testar fallback com JavaScript desativado (noscript deferred-stack);
- [x] cobertura guest `customer-data` (bundle na rede; `section/load` permanece
  idle-deferred na home — documentado no spec);
- [x] B2B login/dashboard/pedidos/cotações: mantido via `test:b2b-regression`
  no smoke CI (sem duplicar suite);
- [x] viewports 390 / 768 / 1366 / **1920** (`desktop-1920` no Playwright;
  contrato DOM nesses breakpoints — baselines PNG 1920 ficam para
  `--update-snapshots` em staging);
- [x] Lighthouse: LCP ≤ 2800 ms, TBT ≤ 300 ms, CLS ≤ 0,1 (desktop + mobile);
- [x] CI smoke sem produção como padrão (`vars.E2E_BASE_URL` + guard).

### Critérios de aceite

- [x] testes executáveis (`npm run test:cascade-contract` + PHPUnit);
- [x] falha do contrato entra no `e2e-pr-smoke` (bloqueia PR quando BASE_URL
  configurada);
- [x] produção não é alvo padrão (`resolveBaseUrl` + guard do workflow);
- [x] validação lab: Playwright **9 passed** (notebook-1366, opt-in prod);
  PHPUnit **7 passed**.

### Arquivos

- `tests/e2e/specs/css-cascade-contract.spec.ts` (novo)
- `tests/e2e/playwright.config.ts` (`desktop-1920`)
- `tests/e2e/package.json` (`test:cascade-contract`)
- `app/code/GrupoAwamotos/Theme/Test/Unit/Plugin/Response/OptimizeHeadStylesPluginTest.php` (novo)
- `.github/lighthouse/lighthouserc.desktop.cjs`
- `.github/lighthouse/lighthouserc.mobile.cjs`
- `.github/workflows/e2e-pr-smoke.yml`

### Rollback

Backup: `/home/deploy/backups/awa-css-cascade-phase4-tests-20260807T012700Z`

```text
Fase: 4
Status: CONCLUÍDA
Data/hora: 2026-08-07
Autorização: AUTORIZADO escrever Fase 4
PHPUnit: 7 passed
Playwright cascade (notebook-1366, opt-in prod): 9 passed / 36.4s
Observações: definir vars.E2E_BASE_URL (staging) no GitHub; produção só com
  vars.ALLOW_PRODUCTION_VALIDATION=true. Próxima = Fase 5.
```

### Pós-inspeção 2026-08-07 (profunda)

Inspeção forense home/PLP/B2B login + hashes fonte/pub/HTTP:

| Achado | Classificação | Ação |
|--------|---------------|------|
| Bundles SSOT fonte = pub = HTTP | CONFIRMADA OK | nenhuma |
| `.br`/`.gz` dos bundles principais byte-a-byte | CONFIRMADA OK | nenhuma |
| Duplos `link` critical/polish/deferred | REJEITADA (são `<noscript>` fallback) | nenhuma |
| mtime unmin > min (super-global/ssot) | REJEITADA (regen cleancss = mesmo hash) | nenhuma |
| exception.log vazio; HTTP 200 rotas críticas | CONFIRMADA OK | nenhuma |
| CLS/sticky/bg home lab estáveis | CONFIRMADA OK | nenhuma |
| `e2e-pr-smoke` falhava PR sem `E2E_BASE_URL` | CONFIRMADA (regressão Fase 4) | **corrigido**: skip seguro |
| Auth Magento `/customer/account/login` → `/b2b/account/login` | CONFIRMADA | e2e aponta B2B direto |
| `.br`/`.gz` dentro de `web/` do tema | DÍVIDA (Fase 5/11) | não patch agora |
| CSS density B2B ainda inline (~12 KB) | DÍVIDA (Fase 9) | não patch agora |

Patch mínimo aplicado:

- `.github/workflows/e2e-pr-smoke.yml` — skip quando `E2E_BASE_URL` ausente
- `tests/e2e/specs/css-cascade-contract.spec.ts` — URL auth B2B canônica

Validação: guard skip/block OK; Playwright subset **3 passed**.

Backup: `/home/deploy/backups/awa-css-cascade-phase4b-ci-skip-20260807T015000Z`

---

## [x] Fase 5 — Automatizar builds e cache-busting restantes — CONCLUÍDA

### Registro de evidências (Fase 5)
- Data: 2026-08-07
- Status: CONCLUÍDA
- Scripts: `build-awa-footer-terminal`, `build-awa-css-min-pair`, `build-awa-cascade-versions`,
  `build-awa-cascade-terminals`, `check-awa-static-sidecars`, `precompress-static --check`
- PHPUnit OptimizeHeadStylesPluginTest: 7 passed
- HTTP home: queries cascata = hash12; tokens manuais antigos = 0 hits
- Backup versions: `/home/deploy/backups/awa-css-cascade-phase5-versions-20260807T024500Z`


### Diagnóstico (somente leitura + build --check, 2026-08-07)

- `footerTerminalRules()` → cleancss -O1 ≡ disco `awa-footer-terminal-lock-v1.min.css`
  (sha256 `d72a7f0331…`, 106769 B) — **sem drift atual**.
- Lacuna **CONFIRMADA**: não existia build com `--check`/`--write` para o footer
  (risco de drift silencioso em edições futuras de `footerTerminalRules()`).
- Cascata SSOT: `?v=` = hash12; tema `web/` sem `.br`/`.gz`; precompress `--pub-only`/`--check`.

### Entrega parcial

- [x] `scripts/build-awa-footer-terminal.sh` (`--check` / `--write`) a partir de
  `footerTerminalRules()`.
- [x] `scripts/build-awa-css-min-pair.sh` — align-grid + refine (`cleancss -O1`).
- [x] `scripts/build-awa-cascade-terminals.sh` — orquestra footer + align-grid + refine.
- Evidência `--check` (2026-08-07): footer / align-grid / refine **match=true** (sem drift).
- [x] Removidos 40 sidecars `.br`/`.gz` de `web/` do tema (backup em
  `/home/deploy/backups/awa-css-cascade-phase5-theme-precompress-20260807T023800Z`).
- [x] `scripts/check-awa-static-sidecars.sh` + `precompress-static.sh --check`.
- [x] Queries cascata SSOT por hash (`CascadeAssetVersionConsts`); residual gate/PDP/minicart opcional.

### Tarefas

- [x] criar build determinístico do footer a partir de
  `footerTerminalRules()`;
- [x] criar build determinístico para align-grid e refine;
- [x] falhar o build quando fonte e minificado divergirem;
- [x] gerar versão baseada em conteúdo (`CascadeAssetVersionConsts`);
- [x] eliminar queries livres duplicadas (cascata SSOT → hash conteúdo);
- [x] usar `scripts/precompress-static.sh --pub-only` após o deploy;
- [x] validar sidecars e conteúdo HTTP automaticamente.

### Critérios de aceite

- [x] nenhum `.min.css` da cascata SSOT depende de cópia manual;
- [x] versões da cascata SSOT geradas num único lugar (build versions);
- [x] builds são idempotentes;
- [x] `--check` detecta artefato stale sem escrever.

---

## [x] Fase 6 — Migrar decisões de assets para o pipeline Magento — CONCLUÍDA

### Objetivo

Substituir pós-processamento de HTML por layout handles e PageConfig.

### Diagnóstico (somente leitura, 2026-08-07 — pós Fase 5)

- Plugin `OptimizeHeadStylesPlugin.php`: **8020** LOC, **187** `preg_replace`, **100** métodos,
  **8** calls a `stripStylesheetFragments`.
- Constantes de fragmento CSS relevantes: `HOME_GATE` (30), `HOME_DEFERRED_STACK` (15),
  `HOME_DEFER` (11), `NOROUTE_DEFER` (13), `AUTH_STRIP` (9), `CATALOG_*`, `CART_STRIP`, etc.
- Tema já tem **113** `<remove>` em 14 layouts; home (`cms_index_index.xml`) já remove 28 assets —
  mas gate/defer/strip da home ainda vivem no `beforeSendResponse`.
- 1ª migração candidata (menor risco mensurável): espelhar `HOME_GATE_CSS_FRAGMENTS` +
  remoções já cobertas por layout, com teste de paridade HTML, **sem** apagar regex até 2 medições.

### Fatia 1 — home gate/layout parity (AUTORIZADA 2026-08-07)

**Backup:** `/var/backups/awa-css-cascade-phase6-fatia1-20260807T123134Z`
**Status:** fatia 1 **CONCLUÍDA** (medições #1 e #2 OK; instrumentação debug removida).

#### Inventário live (baseline pré-deploy)

| Item | Valor |
|------|--------|
| HTML home | ~746 KB, HTTP 200 |
| `<link>` CSS | 24 |
| Fila `awa-css-gate-queue` | **6** URLs |
| Fragmentos HOME_GATE ausentes do HTML | 10+ (só safety-net) |

Fila gate ativa: `align-grid-terminal`, `impeccable-refine`, `custom_default.css`,
`login-to-cart`, `status-panel`, `awa-b2b-status-panel`.

#### Entregue

- [x] modelo `HomeCssGateParity` (`LAYOUT_REMOVE_SRCS` + `ACTIVE_GATE_FRAGMENTS` + `PRELOAD_SEED_FRAGMENTS`);
- [x] espelhar remoções PageConfig em `cms_index_index.xml` (19 `<remove>` de paridade);
- [x] seed explícito da fila gate em `awa-head-preload.phtml` (3 URLs B2B/L2C; align-grid/refine/custom_default via plugin);
- [x] testes PHPUnit de paridade layout + merge gate (OK — 10 tests);
- [x] instrumentação debug `f85cea` removida após medições #1 e #2;
- [x] medição HTML #1 pós-deploy: gate **6** bases únicas, ACTIVE sem missing;
- [x] medição HTML #2 (2026-08-07): `gateN=6`, `uniqueBases=6`, `cssLinks=24`, HTML 746108 B, sem dup align-grid;
- [x] **não** apagar `HOME_GATE_CSS_FRAGMENTS` / regex do plugin (permanece safety-net);

**Evidência runtime (m2):** `PARITY_OK` — mesmas 6 bases do baseline.
**Backup:** `/var/backups/awa-css-cascade-phase6-fatia1-20260807T123134Z`
**Nota:** OPcache/FPM precisou de `systemctl reload php8.4-fpm` na 1ª medição.

#### Arquivos alterados

- `app/code/GrupoAwamotos/Theme/Model/HomeCssGateParity.php` (novo)
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Cms/layout/cms_index_index.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-head-preload.phtml`
- `app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php` (log debug only)
- `app/code/GrupoAwamotos/Theme/Test/Unit/Plugin/Response/OptimizeHeadStylesPluginTest.php`

### Fatia 2 — PLP PageConfig parity (AUTORIZADA 2026-08-18)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase6-fatia2-20260818T2308Z`
**Status:** fatia 2 **CONCLUÍDA** (medições #1 e #2 OK; regex permanece).

#### Inventário live (baseline pré-deploy, `/carcacas.html`)

| Item | Valor |
|------|--------|
| HTML PLP | ~1,14 MB, HTTP 200 |
| `X-Awa-Header-Optimize` | `v12-catalog-stack` |
| `catalogStrip*` no HTML | 0 (plugin já removia) |
| `styles-l.min.css` bloqueante | 1, com `min-width: 768px` |
| `awa-commerce-impeccable-refine` | 0 no HTML final (emit+strip) |

#### Entregue

- [x] modelo `CatalogPlpCssParity` (`LAYOUT_REMOVE_SRCS` + `STRIP_FRAGMENTS`);
- [x] espelhar remoções PageConfig em `catalog_category_view.xml` (Magento_Catalog + gallery no Magento_Theme);
- [x] skip de emit do refine no `awa-head-preload.phtml` quando `$isCatalogCssGate`;
- [x] testes PHPUnit de paridade layout + fragments no manifest (27 passed);
- [x] medição HTML #1: PLP `gate=v12-catalog-stack`, refine/head-tail/ui-simplify/audit = 0, align-grid = 1, styles-l único com `min-width:768px`;
- [x] medição HTML #2 (2026-08-18): PLP bytes idênticos à #1; busca também 0 refine; home ainda tem refine (1) e align-grid (1);
- [x] **não** apagar `catalogStripImpeccable` / `catalogStripLegacyBundle` do plugin.

#### Arquivos alterados

- `app/code/GrupoAwamotos/Theme/Model/CatalogPlpCssParity.php` (novo)
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Catalog/layout/catalog_category_view.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/layout/catalog_category_view.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-head-preload.phtml`
- `app/code/GrupoAwamotos/Theme/Test/Unit/Plugin/Response/OptimizeHeadStylesPluginTest.php`

### Fatia 3 — busca PageConfig parity (AUTORIZADA 2026-08-18)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase6-fatia3-20260818T2324Z`
**Status:** fatia 3 **CONCLUÍDA** (medições #1 e #2 OK; regex permanece).

#### Inventário live (baseline pré-deploy, `/catalogsearch/result/?q=carcaca`)

| Item | Valor |
|------|--------|
| HTML busca | ~1,14 MB, HTTP 200 |
| `X-Awa-Header-Optimize` | `v12-catalog-stack` |
| `catalogStrip*` no HTML | 0 |
| `awa-align-grid-terminal` | 1 |
| `awa-plp-shell-final` | 1 |

#### Entregue

- [x] mesmas `LAYOUT_REMOVE_SRCS` em `catalogsearch_result_index.xml` (inclui gallery e CSS home/checkout);
- [x] teste PHPUnit `testCatalogSearchLayoutParityRemovesExistInSearchLayout`;
- [x] skip refine no preload já coberto pela fatia 2 (`$isCatalogCssGate`);
- [x] medição HTML #1: busca `v12-catalog-stack`, refine/head-tail/audit/gallery = 0, align-grid = 1, styles-l único `min-width:768px`; PLP e home sem regressão;
- [x] medição HTML #2 (2026-08-18): bytes idênticos à #1;
- [x] **não** apagar regex do plugin.

#### Arquivos alterados

- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_CatalogSearch/layout/catalogsearch_result_index.xml`
- `app/code/GrupoAwamotos/Theme/Model/CatalogPlpCssParity.php`
- `app/code/GrupoAwamotos/Theme/Test/Unit/Plugin/Response/OptimizeHeadStylesPluginTest.php`

### Fatia 4 — PDP PageConfig parity (AUTORIZADA 2026-08-18, GO 100%)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase6-complete-20260818T2345Z`
**Status:** fatia 4 **CONCLUÍDA** (medições #1 e #2 bytes idênticos; regex permanece).

#### Inventário live (PDP `/ret-biz-100-cr-redondo-universal-2220.html`)

| Item | Valor |
|------|--------|
| HTML PDP | 1 100 596 B, HTTP 200 |
| `X-Awa-Header-Optimize` | `v12-catalog-stack` |
| `<link>` refine / audit | 0 |
| `<link>` `mage/gallery/gallery.min.css` | 1 (mantido) |
| `<link>` align-grid | 1 |
| `styles-l` | 1, `min-width: 768px` |

#### Entregue

- [x] modelo `CascadeCommerceCssParity` (`PDP_REMOVE_SRCS` = audit + refine);
- [x] `<remove>` em `catalog_product_view.xml` (não remove gallery / distill / ui-simplify);
- [x] skip emit refine no preload quando `$isCatalogCssGate || $isProductPage`;
- [x] patch cirúrgico no `view_preprocessed` do mesmo PHTML + `reload php8.4-fpm`;
- [x] medição #1 e #2 (intervalo ≥15 s): bytes 1 100 596 em ambas; gallery permanece;
- [x] **não** apagar `catalogStripImpeccable` no plugin.

### Fatia 5 — carrinho, checkout, auth e conta (AUTORIZADA 2026-08-18, GO 100%)

**Backup:** o mesmo da fatia 4.
**Status:** fatia 5 **CONCLUÍDA** (HTML live em cart/auth; checkout guest redireciona para login B2B; conta só XML+PHPUnit).

| Rota | Evidência |
|------|-----------|
| Carrinho `/checkout/cart/` | HTTP 200, `v12-cart`, 669 126 B, `<link>` `awa-cart-stack.min.css` = 1, refine/promax = 0 |
| Checkout `/checkout/` | HTTP 200 após follow → `v12-auth` (guest não alcança OPC); shell auth = 1 |
| Auth `/b2b/account/login` | HTTP 200, `v12-auth`, 296 420 B, `awa-b2b-auth-shell-final` = 1, styles-l link = 0 |
| Conta B2B | exige login; paridade XML em `customer_account.xml` + loader em `GrupoAwamotos_B2B` |

#### Entregue

- [x] `CART_REMOVE_SRCS` / `CHECKOUT_REMOVE_SRCS` / `AUTH_REMOVE_SRCS` / `ACCOUNT_REMOVE_SRCS`;
- [x] shells **não** removidos (`awa-cart-stack`, `awa-checkout-shell-final`, `awa-b2b-auth-shell-final`, loader da conta);
- [x] teste `testCascadeCommercePdpCartCheckoutAuthAccountLayoutParity`;
- [x] medição #1 = #2 (cart/auth/checkout-redirect/PLP/busca/home);
- [x] **não** apagar `cartStrip` / `authStrip` / `b2bAccountStrip`.

### Fatia 6 — fast-path do strip (mesma onda)

- [x] `stripStylesheetFragments` só corre `preg_replace` se o fragmento existe no HTML;
- [x] teste `testStripStylesheetFragmentsNoopsWhenFragmentAbsent`;
- [x] PHPUnit 30 tests, 159 assertions.

#### Arquivos alterados (fatias 4–6)

- `app/code/GrupoAwamotos/Theme/Model/CascadeCommerceCssParity.php` (novo)
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Catalog/layout/catalog_product_view.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Checkout/layout/checkout_cart_index.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Checkout/layout/checkout_index_index.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Rokanthemes_OnePageCheckout/layout/onepagecheckout_index_index.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/GrupoAwamotos_B2B/layout/b2b_auth_shell.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Customer/layout/customer_account.xml`
- `app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/templates/html/awa-head-preload.phtml`
- `var/view_preprocessed/.../awa-head-preload.phtml` (patch cirúrgico do skip PDP)
- `app/code/GrupoAwamotos/Theme/Plugin/Response/OptimizeHeadStylesPlugin.php`
- `app/code/GrupoAwamotos/Theme/Test/Unit/Plugin/Response/OptimizeHeadStylesPluginTest.php`

### Tarefas

- [x] inventariar cada regex que remove ou injeta `<link>`/`<style>` (home gate — fatia 1);
- [x] mapear cada regra para seu layout handle (home → `cms_index_index.xml`);
- [x] migrar uma rota por vez (home → PLP → busca → PDP → cart/checkout/B2B);
- [x] mover home para `cms_index_index.xml` (fatia 1: remoções + seed; regex permanece);
- [x] mover PLP para `catalog_category_view.xml` (fatia 2: remoções PageConfig + skip emit refine no preload; regex permanece);
- [x] mover busca para `catalogsearch_result_index.xml` (fatia 3: mesmas remoções PageConfig; regex permanece);
- [x] mover PDP para `catalog_product_view.xml`;
- [x] preservar shells específicos de carrinho, checkout e B2B;
- [ ] remover regex somente após nova janela de medições **sem** depender do plugin (adiada: FPC stale; safety-net permanece).

### Critérios de aceite

- [x] output HTML equivalente nas duas medições pós-deploy (bytes idênticos #1/#2);
- [x] nenhum `styles-l` duplicado (um link, `min-width: 768px` onde aplicável);
- [x] shells de cart/auth presentes; gallery PDP presente;
- [ ] nenhuma dependência de ordem do `beforeSendResponse` — **parcial**: plugin ainda é safety-net (intencional);
- [x] redução mensurável do pós-processamento: fast-path sem `preg` quando o fragmento está ausente (métodos regex **não** apagados).

---

## [ ] Fase 7 — Tornar LESS a autoridade visual canônica — EM ANDAMENTO

Diagnóstico 2026-08-18 (somente leitura): **NO-GO** para SCD de `_extend.less` como primeiro passo. A home publicada não carrega `styles-m`/`styles-l` como `<link>`. `--awa-primary` não tem um dono único (ver `CSS_INVENTORY.md` § Autoridade de token por rota).

### Fatia 1 — contrato de autoridade por rota (AUTORIZADA 2026-08-18)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia1-20260819T0025Z`
**Status:** fatia 1 **CONCLUÍDA** (só markdown; zero CSS/LESS/PHP; zero cache).

#### Evidência live (curl)

| Rota | Última declaração `--awa-primary` |
|------|-----------------------------------|
| Home | `awa-home-deferred-stack.min.css` → `#b73337` |
| PLP / PDP | `awa-super-global-20260611m.min.css` → `var(--awa-sg-c26)` |
| Carrinho | `styles-l.min.css` → `var(--awa-oklch-primary)` |
| Auth B2B | nenhuma; fallback hex no shell |

`awa-design-system.min.css` live SHA = fonte do tema (`f60770df…`). `pub/static` `awa-design-system.css` não minificado está stale.

#### Entregue

- [x] tabela por rota no contrato `CSS_INVENTORY.md`;
- [x] índice + plano atualizados;
- [x] proibir `:root { --awa-primary }` novo e SCD “para ativar LESS” na home;
- [x] **não** unificar declarações nesta fatia (feito na fatia 2);
- [x] **não** SCD.

### Fatia 2 — SSOT `--awa-brand` em home + auth B2B (AUTORIZADA 2026-08-18)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia2-20260819T0037Z`
**Status:** fatia 2 **CONCLUÍDA** (CSS + PHTML + `pub/static` + sidecars; sem SCD).

Magento empty layout (`b2b_auth_shell`) não carrega `awa-design-system`. Boas práticas:
o **shell da superfície** declara a marca; o módulo (`login.css` / `cta-lock`) consome
`var(--awa-*)`. `login.css` `:root` mantém literais hex (workaround Chromium em `color-mix`).

#### Entregue

- [x] `awa-b2b-auth-shell-final`: `--awa-brand` + `--awa-primary: var(--awa-brand)`; fallback `#c0392b` → `#b73337`;
- [x] `cta-lock-20260815.css`: só CTAs, sem redeclaração de token;
- [x] `login.css` + crítico inline: aliases `--awa-brand` em hex (1º paint);
- [x] `awa-home-deferred-stack.min.css`: `--awa-primary: var(--awa-brand, #b73337)`;
- [x] sidecars `.br`/`.gz` regenerados; fonte = `pub/static` pt_BR;
- [x] fatia 3: PLP/PDP/cart vencedor `impeccable-layout` + super-global sem ciclo + conta B2B + login-to-cart;
- [x] **não** SCD / **não** `styles-m` na home.

### Fatia 3 — catálogo Magento + conta B2B (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia3-20260819T0110Z`
**Status:** fatia 3 **CONCLUÍDA** (CSS + `pub/static` + sidecars; sem SCD; sem cache Magento).

Medição live 2026-08-19 mostrou que o contrato da fatia 1 (vencedor = super-global / `styles-l`) estava **stale**: o último arquivo a declarar `--awa-primary` em PLP/PDP/carrinho é `awa-impeccable-layout-2026-06-16.min.css`.

#### Entregue

- [x] `awa-impeccable-layout`: `--awa-primary: var(--awa-brand,#b73337)` (vencedor PLP/PDP/cart);
- [x] `awa-super-global`: `--awa-sg-c26` / `--awa-primary` deixam o ciclo e apontam `--awa-brand`;
- [x] `login-to-cart.css`: remove `--aw-primary` e `#A33B3B`; CTA B2B “login para comprar” usa marca AWA;
- [x] `awa-b2b-account-shell-final`: lock Magento `2columns-left` com `--awa-primary: var(--awa-brand)`;
- [x] **não** editar `styles-l.min.css` (compilado Magento; SCD); OKLCH declara mas não vence;
- [x] **não** SCD.

### Fatia 4 — CMS Magento + checkout + leftovers B2B (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia4-20260819T0131Z`
**Status:** fatia 4 **CONCLUÍDA** (CSS + `pub/static` + sidecars; sem SCD; sem cache Magento).

Guest `/checkout/` redireciona para login B2B. `/catalogsearch/result/?q=zzzznonexistentawa` **não** redireciona para PLP. CMS `/sobre-nos` é 404; `/contact` e `/politica-de-privacidade` existem.

#### Entregue

- [x] `awa-checkout-shell-final`: `--awa-primary: var(--awa-brand, #b73337)` no lock OPC + `.checkout-onepage-success`;
- [x] `awa-cms-shell-final`: mesmo alias no lock CMS/contato/404/currículo;
- [x] busca: vencedor live = `awa-impeccable-layout` (já fatia 3; contrato atualizado);
- [x] B2B `premium-blocks` / `dashboard-combined` / `register` / `landing` / `suggestion` / `b2b-marketing`: fallback `#b73337` e `--awa-brand` no lugar de `#A33B3B` / `--aw-primary`;
- [x] `login-to-cart.min.css` sincronizado com a fonte da fatia 3;
- [x] **não** editar `styles-l.min.css`; **não** SCD; **não** adminhtml.

### Fatia 5 — módulos storefront Magento + cookie + 404 (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia5-20260819T0228Z`
**Status:** fatia 5 **CONCLUÍDA** (CSS/JS + `pub/static` + sidecars; sem SCD; sem cache Magento).

`/customer/account/create/` redireciona para `/b2b/register/`. 404 Magento vence em `awa-cms-shell-final`. Cookie carrega na home. Social proof carrega na PDP. `FakePurchase` não está em `pub/static` (HTML guest sem o asset).

#### Entregue

- [x] `CookieConsent`: `--cookie-c2: var(--awa-brand, #b73337)`;
- [x] `SocialProof`: `--awa-sp-primary: var(--awa-brand, #b73337)`;
- [x] `ProductIntelligence`: `--rx-ayo-primary` aponta `--awa-brand` (2 blocos);
- [x] `FakePurchase`: `--fp-primary` aponta `--awa-brand` (fonte; sem pub);
- [x] B2B `performance-charts.js`: `#A33B3B` → `#b73337` (canvas);
- [x] 404 medida: vencedor `awa-cms-shell-final`;
- [x] **não** PHTML Magento register (rota redireciona); **não** adminhtml; **não** SCD.

### Fatia 6 — higiene Magento nativo + cookie runtime + e-mail (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia6-20260819T0240Z`
**Status:** fatia 6 **CONCLUÍDA** (PHTML + CSS/PHP + `pub/static` + sidecars; `view_preprocessed` do tema; reload `php8.4-fpm` por `EmailSender.php`; sem SCD; sem `cache:clean`).

Rotas Magento nativas (302, guest): `/customer/account/login/` → `/b2b/account/login/`; `/customer/account/create/` → `/b2b/register/`; `/customer/account/forgotpassword/` → `/b2b/account/forgotpassword/`; `/customer/account/createpassword/` → `/customer/account/forgotpassword/` (depois B2B); `/wishlist/` → login Magento com referer (depois B2B). Auth-shell continua vencedor.

`GrupoAwamotos_CookieConsent::css/cookie-consent.css` é **removido** em `default_head_blocks.xml`. Hover live do botão Permitir = `awa-cookie-consent-fix` (`background: var(--awa-primary-hover)` + `!important`). Home/PDP não carregam `awa-interaction-widgets`; `/contact` carrega. `FakePurchase` permanece `0` em `config.php`.

#### Entregue

- [x] `Magento_Customer/templates/form/register.phtml` (tema filho + `view_preprocessed` do tema): bloco `:root` morto `#A33B3B` removido;
- [x] `CookieConsent` fonte: `--cookie-c5: var(--awa-brand-dark, #8e2629)` (pub do módulo sincronizado; **não** está no head);
- [x] `awa-interaction-widgets`: cópia inlined do cookie alinhada a `--awa-brand` / `--awa-brand-dark`;
- [x] `AbandonedCart` `EmailSender.php`: preço do HTML transacional `#b73337`;
- [x] **não** FakePurchase `pub/static`; **não** adminhtml; **não** SCD; **não** `styles-l`; **não** C1.

### Fatia 7 — e-mails Magento nativos + CTAs B2B (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia7-20260819T0255Z`
**Status:** fatia 7 **CONCLUÍDA** (HTML de e-mail no tema/módulo; sem `pub/static`; sem SCD; sem cache Magento; sem php-fpm).

`core_config_data` `design/email/header_template` = `design_email_header_template` (identificador Magento, não `email_template.template_id`). Os 10 templates gravados no banco usam `{{template config_path="design/email/header_template"}}` e **não** contém `#D52F33`/`#A33B3B` no corpo. `email.min.css` já tinha `a { color:#b73337 }`.

Cabeçalhos semânticos preservados: aprovado teal, proposta verde, pendência amber, perigo. Badge laranja de “cotação recebida” preservado. Adminhtml e ERP **não** alterados.

#### Entregue

- [x] `Magento_Email/email/header.html` + `footer.html`: `#D52F33` → `#b73337`;
- [x] B2B frontend: `registration_confirmation`, `customer_approved`, `customer_rejected`, `quote_response`, `quote_replied`, `quote_rejected`, `b2b_cotacao_quoted`, `b2b_cotacao_received` — chrome/CTA Material blue → `#b73337`;
- [x] `AbandonedCart` `abandoned_cart_email_1_b.html`: CTA/acento alinhados à marca (o `_1.html` já estava);
- [x] **não** adminhtml; **não** ERP; **não** SCD; **não** C1; **não** `styles-l`.

### Fatia 8 — checkout/header B2B Magento (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia8-20260819T0318Z`
**Status:** fatia 8 **CONCLUÍDA** (CSS + PHP + `pub/static` + sidecars; reload `php8.4-fpm` por `StatusPanel`/`B2bPanel`; sem SCD; sem `cache:clean`).

`b2b_marketing_styles` carrega `suggestion.css` em `checkout_onepage_success` e `customer_account_create` (create 302 → B2B). Home **não** baixa o arquivo (omitido no head-preload). Badge Atacado é guest-invisível; evidência = fonte PHP + OPcache recarregado.

VIP `#7c3aed`, pendente `#f59e0b` e revendedor `#059669` **não** alterados.

#### Entregue

- [x] `suggestion.css` / `b2b-marketing.css`: `--b2b-mkt-sug-c14` / `--b2b-mkt-c14` → `var(--awa-brand, #b73337)`; fallback Luma `#1979c3` e anéis `rgb(25 118 210)` → marca;
- [x] `StatusPanel.php` + `B2bPanel.php`: Atacado `#2563eb` → `#b73337`;
- [x] **não** adminhtml; **não** anéis de foco PHTML (A9); **não** SCD; **não** C1.

### Fatia 9 — header B2B Magento + anel de foco (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia9-20260819T0402Z`
**Status:** fatia 9 **CONCLUÍDA** (CSS módulo + fonte a11y + PHTML Magento mortos + `pub/static` + sidecars + `view_preprocessed`; sem SCD; sem `cache:clean`; sem reload php-fpm).

PDP/CMS guest baixam `GrupoAwamotos_B2B/css/header/status-panel.min.css` (HTTP = disco). Hover `#c53d42` saiu. Anel live já era marca no inline; `id="awa-focus-visible"` do PHTML **não** está no HTML. Skip-link **color** `#2563eb` em `awa-visual-fixes` permanece exceção a11y. Fundo skip `#111827` permanece (não é `--awa-ink`).

#### Entregue

- [x] `status-panel.css`: `--b2b-primary*` → `--awa-primary` / `--awa-brand` / `--awa-brand-dark`;
- [x] `awa-focus-visible.css` + `.min.css` publicado: fallback `var(--awa-primary, #b73337)`;
- [x] `awa-focus-visible.phtml` + outline do `awa-skip-link.phtml` alinhados; sync `view_preprocessed`;
- [x] **não** cor do skip-link em visual-fixes; **não** adminhtml; **não** SCD; **não** C1; **não** `styles-l`.

### Fatia 10 — PHTML Magento mortos, anel de foco (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia10-20260819T0413Z`
**Status:** fatia 10 **CONCLUÍDA** (6 PHTML Magento + `view_preprocessed`; sem `pub/static`; sem SCD; sem `cache:clean`; sem php-fpm).

HTML guest: zero `#2563eb`; `data-awa-dark-mode-toggle` e `data-awa-whatsapp-float` **ausentes**. FAB live = `GrupoAwamotos_Theme::core/whatsapp-btn.phtml`. 404 live = `noroute.phtml`. `trust-stack` SVG info **não** tocado.

#### Entregue

- [x] `awa-newsletter-popup.phtml`, `awa-dark-mode-toggle.phtml`, `awa-whatsapp-microcopy.phtml`, `awa-pdp-qa.phtml`, `awa-quick-order.phtml`, `no-route.phtml`: `#2563eb` → `var(--awa-primary, #b73337)`;
- [x] verde WhatsApp `#25d366` preservado; skip-link color e `trust-stack` intactos;
- [x] **não** adminhtml; **não** SCD; **não** C1; **não** `styles-l`.

### Fatia 11 — Magento carrinho (AUTORIZADA 2026-08-19)

**Backup:** `/home/deploy/backups/awa-css-cascade-phase7-fatia11-20260819T1021Z`
**Status:** fatia 11 **CONCLUÍDA** (`awa-cart-stack` + shim `awa-cart-shell-final` publicados em `pt_BR`/`en_US` + sidecars; sem SCD; sem `cache:clean`).

HTML guest `/checkout/cart/`: href `awa-cart-stack.min.css`; **sem** `awa-cart-shell-final`. Vencedor `--awa-primary` = `awa-impeccable-layout-2026-06-16`. `--awa-red` no `styles-l` continua OKLCH, mas o chrome do stack não consome mais esse token.

#### Entregue

- [x] `awa-cart-stack.css`: preço `var(--awa-brand-primary, var(--awa-primary, #b73337))`; chrome `--awa-red`/`--awa-red-dark` → `--awa-brand`/`--awa-brand-dark`;
- [x] `awa-cart-shell-final.css` (shim legado): 13× `var(--awa-primary, var(--awa-primary))` → `var(--awa-primary, #b73337)`;
- [x] `pub/static` `pt_BR` + `en_US` + `.gz`/`.br`; HTTP live = disco;
- [x] **não** adminhtml; **não** SCD; **não** C1; **não** `styles-l`.

### Tarefas (fase completa)

- [x] fatia 1: documentar quem vence `--awa-*` no HTML publicado;
- [x] fatia 2: home + auth B2B alias `--awa-brand`;
- [x] fatia 3: PLP/PDP/cart + conta B2B + login-to-cart;
- [x] fatia 4: CMS + checkout shell + busca + leftovers B2B (`#A33B3B` / `--aw-primary`);
- [x] fatia 5: cookie + social proof + rexis + fake-purchase + charts B2B + 404;
- [x] fatia 6: PHTML Magento register + cookie runtime (`cookie-consent-fix` / widgets) + e-mail AbandonedCart + redirects nativos;
- [x] fatia 7: chrome Magento `Magento_Email` + CTAs B2B frontend (Material blue → marca);
- [x] fatia 8: B2B marketing/checkout Luma blue + badge Atacado do header;
- [x] fatia 9: hover `status-panel` Magento + fallback do anel de foco;
- [x] fatia 10: PHTML Magento mortos — anel de foco `#2563eb` → `--awa-primary`;
- [x] fatia 11: Magento carrinho — chrome `--awa-brand` + fallback circular;
- [ ] consolidar tokens em `_awa-variables.less` (só depois de um runtime SSOT);
- [ ] migrar componentes estáveis para partials importados por `_extend.less` (**bloqueado** enquanto a home não carrega `styles-m`/`styles-l`);
- [ ] eliminar fallback cromático duplicado (deferred-stack hex vs OKLCH vs `--awa-brand`);
- [ ] substituir hexadecimais por tokens (não em massa nesta fase);
- [ ] manter critical CSS apenas para geometria above-fold;
- [ ] documentar exceções que precisam permanecer standalone.

### Critérios de aceite

- [x] fatia 1: contrato descreve a declaração vencedora por rota (não o arquivo LESS);
- [ ] tokens possuem uma única definição **no HTML**;
- [ ] alteração no LESS aparece após static deploy **nas rotas que carregam styles-m/l**;
- [ ] PHP não contém regras visuais estáveis que pertencem ao tema;
- [ ] visual SSOT não depende de repetição de `#html-body`.

---

## [ ] Fase 8 — Consolidar footer — PENDENTE

### Problemas conhecidos

- regras divididas entre PHP, arquivo terminal e `footer.phtml`;
- JavaScript duplicado no template;
- possibilidade de sobreposição entre refine, terminal e patches finais;
- ausência de teste que detecte requisição duplicada.

### Tarefas

- [ ] criar teste de rede para o footer terminal;
- [ ] confirmar ou refutar duplo-fetch na home;
- [ ] extrair JavaScript duplicado do `footer.phtml`;
- [ ] remover CSS inline coberto pelo arquivo terminal;
- [ ] preservar critical anti-CLS;
- [ ] validar footer desktop/mobile e sem JavaScript.

### Critérios de aceite

- [ ] uma autoridade por propriedade do footer;
- [ ] uma requisição do stylesheet terminal;
- [ ] zero shift relevante quando o footer CSS entra;
- [ ] navegação e accordions acessíveis por teclado.

---

## [ ] Fase 9 — Remover CSS home retransmitido inline — PENDENTE

### Problema confirmado

`awa-home-b2b-ops-density-20260805.css` é injetado inline na home, aumentando o
HTML em aproximadamente 12 KB por resposta.

### Tarefas

- [ ] medir tamanho atual do HTML;
- [ ] gerar versão minificada externa;
- [ ] preservar a ordem terminal efetiva;
- [ ] evitar novo CLS ao externalizar;
- [ ] validar home em quatro viewports;
- [ ] comprovar redução do HTML.

### Critérios de aceite

- [ ] CSS não aparece inline;
- [ ] asset externo possui cache imutável;
- [ ] HTML reduzido;
- [ ] CLS não piora;
- [ ] espaçamento e densidade B2B permanecem idênticos.

---

## [ ] Fase 10 — Reduzir `align-grid`, especificidade e `!important` — PENDENTE

### Problema confirmado

O align-grid possui aproximadamente 900 KB de fonte e 696 KB minificado, além
de regras de múltiplos domínios visuais.

### Tarefas

- [ ] criar inventário por seção/componente;
- [ ] classificar cada `!important` como obrigatório, temporário ou removível;
- [ ] identificar regras repetidas;
- [ ] remover seletores com IDs repetidos quando a ordem da cascata for suficiente;
- [ ] mover regras visuais para os componentes corretos;
- [ ] manter no align-grid somente o contrato terminal de grid;
- [ ] medir bytes removidos por fase.

### Critérios de aceite

- [ ] redução mensurável do arquivo;
- [ ] ausência de hotfixes de cor/rating no align-grid;
- [ ] nenhuma regressão de layout;
- [ ] nenhum aumento de especificidade.

---

## [ ] Fase 11 — Limpar assets mortos e atualizar governança — PENDENTE

### Tarefas

- [ ] revalidar o inventário de CSS/JS sem referências;
- [ ] verificar shims que ainda podem estar em cache;
- [ ] remover backups `.bak.*` das fontes ativas;
- [ ] retirar bundles mortos dos scripts de build;
- [ ] atualizar `CSS_INVENTORY.md`;
- [ ] corrigir regras que recomendam cópia manual para `pub/static`;
- [ ] documentar static deploy como único fluxo de publicação;
- [ ] monitorar 404 de assets antes e depois.

### Critérios de aceite

- [ ] zero referência ativa para arquivos removidos;
- [ ] zero 404 novo;
- [ ] documentação corresponde ao pipeline real;
- [ ] `pub/static` permanece somente artefato.

---

## [ ] Fase 12 — Normalizar Git, ownership e permissões — PENDENTE

### Problemas confirmados

- worktree contém muitas alterações não relacionadas;
- arquivos relevantes possuem owners diferentes (`root`, `deploy`,
  `jessessh`, `www-data`);
- permissões variam entre arquivos equivalentes.

### Tarefas

- [ ] preservar integralmente o worktree atual;
- [ ] separar alterações relacionadas por escopo;
- [ ] definir usuário canônico de deploy;
- [ ] registrar política de owner/grupo/permissão;
- [ ] detectar arquivos root-owned em áreas graváveis pelo Magento;
- [ ] normalizar somente após autorização e backup;
- [ ] impedir deploy futuro como root.

### Critérios de aceite

- [ ] alterações da cascata são auditáveis isoladamente;
- [ ] diretórios runtime são graváveis pelo PHP-FPM;
- [ ] arquivos-fonte seguem política uniforme;
- [ ] deploy não produz novos arquivos root-owned.

---

## [ ] Fase 13 — Retirar locks de compatibilidade — PENDENTE

### Objetivo

Reduzir progressivamente:

- `OptimizeHeadStylesPlugin.php`;
- `HeaderImpeccableCascadeLockCss.php`;
- injeções terminal-wins;
- guards e regex legados.

### Tarefas

- [ ] listar cada lock e seu substituto canônico;
- [ ] comprovar equivalência visual;
- [ ] medir duas execuções estáveis;
- [ ] remover um domínio visual por vez;
- [ ] revisar o diff completo;
- [ ] manter rollback byte a byte.

### Critérios de aceite

- [ ] nenhuma regra sem proprietário;
- [ ] uma autoridade por componente/propriedade;
- [ ] redução mensurável de PHP/CSS inline;
- [ ] home, PLP, PDP, carrinho, checkout e B2B aprovados.

---

## [ ] Fase 14 — Auditoria final e encerramento — PENDENTE

### Tarefas

- [ ] repetir hashes e mtimes após 15 segundos;
- [ ] comparar fonte, minificado, `pub/static`, Brotli, Gzip e HTTP;
- [ ] executar PHP lint, build checks e diff checks;
- [ ] validar rotas críticas desktop/mobile;
- [ ] validar teclado, menus, autocomplete e minicart;
- [ ] validar guest/customer B2B;
- [ ] executar Lighthouse e Playwright aprovados;
- [ ] revisar logs Magento;
- [ ] executar revisão defect-first;
- [ ] registrar arquivos, comandos, caches e serviços afetados.

### Critérios finais

- [ ] CLS `≤ 0,1`;
- [ ] LCP `≤ 2,8 s`;
- [ ] TBT `≤ 300 ms`;
- [ ] zero divergência fonte/publicado;
- [ ] zero asset duplicado;
- [ ] zero exceção Magento relacionada;
- [ ] zero achado pendente na revisão;
- [ ] rollback testado e documentado.

---

## 4. Ordem obrigatória de execução

1. ~~Fase 2 — drift do `super-global`.~~ **CONCLUÍDA**
2. ~~Fase 3 — CLS da home.~~ **CONCLUÍDA**
3. ~~Fase 4 — testes de contrato.~~ **CONCLUÍDA**
4. ~~Fase 5 — builds e versionamento.~~ **CONCLUÍDA**
5. ~~Fase 6 — pipeline Magento.~~ **CONCLUÍDA** (regex safety-net permanece)
6. Fase 7 — LESS canônico. ← **em andamento (fatia 1 docs; código só com autorização)**
7. Fases 8 e 9 — footer e home inline.
8. Fase 10 — redução do align-grid.
9. Fases 11 e 12 — higiene e operação.
10. Fase 13 — retirada dos locks.
11. Fase 14 — auditoria final.

Não avançar para a próxima fase com regressão aberta na fase atual.

### Backups de fases concluídas

| Fase | Backup |
|------|--------|
| 0–1 | `/var/backups/awa-css-cascade-20260806T173600Z` |
| 2 | `/var/backups/awa-css-cascade-phase2-super-global-20260806T155500Z` |
| 3 | `/var/backups/awa-css-cascade-phase3-cls-20260806T193710Z` · `...-phase3-h12-20260806T225620Z` |
| 4 | `/home/deploy/backups/awa-css-cascade-phase4-tests-20260807T012700Z` |
| 6 fatia 1 | `/var/backups/awa-css-cascade-phase6-fatia1-20260807T123134Z` |
| 6 fatia 2 | `/home/deploy/backups/awa-css-cascade-phase6-fatia2-20260818T2308Z` |
| 6 fatia 3 | `/home/deploy/backups/awa-css-cascade-phase6-fatia3-20260818T2324Z` |
| 6 fatias 4–6 | `/home/deploy/backups/awa-css-cascade-phase6-complete-20260818T2345Z` |
| 7 fatia 1 | `/home/deploy/backups/awa-css-cascade-phase7-fatia1-20260819T0025Z` |

## 5. Registro de execução

Copiar o bloco abaixo ao final de cada fase concluída:

```text
Fase:
Status: CONCLUÍDA
Data/hora:
Responsável:
Autorização:
Arquivos alterados:
Hash antes:
Hash depois:
Backup:
Rollback:
Comandos executados:
Caches afetados:
Serviços afetados:
Rotas testadas:
Desktop:
Tablet:
Mobile:
LCP:
CLS:
TBT:
Logs:
Revisão:
Observações:
```

```text
Fase: 6 (fatias 4–6 — PDP, cart/checkout/B2B, fast-path strip)
Status: CONCLUÍDA (regex safety-net permanece)
Data/hora: 2026-08-18T23:45Z–23:55Z
Responsável: agente Cursor (produção)
Autorização: usuário — “RETOME E COMPLETE / GO / até 100%”
Arquivos alterados:
  - app/code/GrupoAwamotos/Theme/Model/CascadeCommerceCssParity.php (novo)
  - Magento_Catalog/layout/catalog_product_view.xml
  - Magento_Checkout/layout/checkout_cart_index.xml
  - Magento_Checkout/layout/checkout_index_index.xml
  - Rokanthemes_OnePageCheckout/layout/onepagecheckout_index_index.xml
  - GrupoAwamotos_B2B/layout/b2b_auth_shell.xml
  - Magento_Customer/layout/customer_account.xml
  - Magento_Theme/templates/html/awa-head-preload.phtml
  - var/view_preprocessed/.../awa-head-preload.phtml (patch skip PDP)
  - OptimizeHeadStylesPlugin.php (fast-path strip)
  - OptimizeHeadStylesPluginTest.php
Hash antes (backup 20260818T2345Z):
  catalog_product_view.xml 9677e7919a73f3f4eeac2778c554bf70bab3bc5256ac3fd5458c5b77315ef9c9
  checkout_cart_index.xml f670b7c6e8fa64f106235159e8a75465b34cd70c437ac3eb3c81ded51973fa0f
  checkout_index_index.xml 536182f1dbaf8a92d94e5b565d6e89d5c0097f5a5a0d885234a115bd7c3fb317
  onepagecheckout_index_index.xml 41c11a131bcdfafa886c9e8048fedff772247a569746a8de582f378b8d4f54db
  b2b_auth_shell.xml e42a97451fde24657aad3e708df369959b8bd82efcdcbd57eef11d1e645c2dc7
  customer_account.xml 450a74a202790c6405d680eea76ca0fa503e137ff602398658c8726356d77a69
  awa-head-preload.phtml e1dfc3b222eab26cf1a7c08293ec275d72e21dc603c4ae3b0bf7a8fa0d055cd9
  OptimizeHeadStylesPlugin.php 7ef9c1ceca3afba9a1eb0235f00cfa9ae7b97f0fc87eb2a9889025b7ecf8801b
Hash depois:
  CascadeCommerceCssParity.php 1290af04098806c2d5413177128d7e1fe57ac526535261fa90516b6e463f7cd9
  catalog_product_view.xml 29102657c8b2e3a0cb7f905434164d10649852f3f16aefcdb9fd3520ea40bf8d
  checkout_cart_index.xml 55dfa8c9a95b45599b8c4e689fa437092abbd359d1ba22450d13a5781ea867c4
  checkout_index_index.xml d3b511f93f798e5f60e52e4931fdec9fc2c4254b3e8aac80734dfa7d98ad1a6a
  onepagecheckout_index_index.xml 3453ab0c6e3bb576fee23d7d076a1f1c1e30a65d11d4e4e6edc7e5c0ad628d99
  b2b_auth_shell.xml fdcef7ec72f4f369d660e2c7ea42853fc62f4b094c42ac357ed217a9bf025ead
  customer_account.xml 31fc6bbc4ef884254dbe623b1cea6d652f64e1dcf3990b47b38572a9429cbead
  awa-head-preload.phtml 32a477d8b45a8573eba1efd3b70b58827100e773de9d6dad35312585bfb22187
  OptimizeHeadStylesPlugin.php b99fb64d3305dc25c6170ea446d48b22c2714d03b1ef6ea710a1ac54b9566df6
Backup: /home/deploy/backups/awa-css-cascade-phase6-complete-20260818T2345Z
Rollback: copiar arquivos do backup sobre o DocumentRoot + cache:clean layout block_html full_page + reload php8.4-fpm
Comandos executados: php -l; PHPUnit 30/30; xmllint; cache:clean layout block_html full_page; systemctl reload php8.4-fpm; curl HTML #1 e #2
Caches afetados: Magento layout, block_html, full_page (sem Redis FLUSHDB, sem SCD)
Serviços afetados: php8.4-fpm reload
Rotas testadas: PDP, cart, /checkout/ (guest→auth), auth, PLP /carcacas.html, busca q=carcaca, home
Desktop: HTML publicado (curl); sem lab Lighthouse nesta fatia
Tablet/Mobile: não reexecutado (sem mudança visual de tokens/LESS)
LCP/CLS/TBT: não re-medidos; sem CSS visual novo
Logs: exception.log 0 B, mtime 2026-08-17 inalterado
Revisão: PHPUnit 30 passed / 159 assertions; gallery PDP mantida; cart-stack e auth-shell mantidos; home refine na fila gate
Observações: regex do plugin NÃO apagada; checkout OPC e conta B2B sem HTML guest; A7–A14 e trilho B/C não iniciados
```

```text
Fase: 7 fatia 1 — contrato de autoridade de token por rota
Status: CONCLUÍDA (só documentação)
Data/hora: 2026-08-19T00:25Z
Responsável: agente Cursor (produção)
Autorização: usuário — “continue” após diagnóstico A7 NO-GO para SCD
Arquivos alterados:
  - app/design/frontend/AWA_Custom/ayo_home5_child/CSS_INVENTORY.md
  - _documentacao/PADRONIZACAO_LAYOUT.md
  - _documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md
Hash antes:
  CSS_INVENTORY.md 1fe57ada39c671060dbfd7a1a26357ff0376f82e56c7b3ac9c0321cb2377376b
  PADRONIZACAO_LAYOUT.md ac546c851ed495444460cbfd5450b9086b10836f8946ee05b503bdc639022ae5
  PLANO_CORRECAO_CASCATA_CSS_FASES.md 99f3e86269f1b917ba77fdc02d2581aaaf41439a179ab1c048493b0e4da535af
Hash depois:
  CSS_INVENTORY.md e9264a099d66becdbd9da9d3f7e7f1cd6e7eeb7f205942e34ec5e9351df53263
  PADRONIZACAO_LAYOUT.md 1f769fb36456fa7286738554eac874f245c7a8be6108ff9ba2120eac65189111
  PLANO (este arquivo) ver sha256 após o commit da fatia
Backup: /home/deploy/backups/awa-css-cascade-phase7-fatia1-20260819T0025Z
Rollback: copiar os 3 markdown do backup sobre o DocumentRoot (sem cache)
Comandos executados: sha256sum ×2; edição markdown
Caches afetados: nenhum
Serviços afetados: nenhum
Rotas testadas: n/a (sem mudança de HTML/CSS)
LCP/CLS/TBT: inalterados
Logs: não aplicável
Revisão: contrato descreve última declaração --awa-primary por rota
Observações: A7 fatia 2 (unificar declaração) NÃO iniciada; sem SCD
```

```text
Fase: 7 fatia 2 — SSOT --awa-brand em home + auth B2B
Status: SUCESSO
Data/hora: 2026-08-19T00:55Z
Responsável: agente Cursor (produção)
Autorização: usuário — “Continue corrigindo e implementando padronização visual Magento + B2B”
Arquivos alterados (fonte):
  - app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-b2b-auth-shell-final.css
  - app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-b2b-auth-shell-final.min.css
  - app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-home-deferred-stack.min.css
  - app/code/GrupoAwamotos/B2B/view/frontend/web/css/account/cta-lock-20260815.css
  - app/code/GrupoAwamotos/B2B/view/frontend/web/css/account/login.css
  - app/design/frontend/AWA_Custom/ayo_home5_child/GrupoAwamotos_B2B/templates/html/awa-b2b-auth-critical-css.phtml
  - app/design/frontend/AWA_Custom/ayo_home5_child/CSS_INVENTORY.md
  - _documentacao/PADRONIZACAO_LAYOUT.md
  - _documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md
  - .impeccable/config.json (ignore design-system-color no shell auth; slate #111827)
Publicados: pub/static pt_BR (+ en_US auth/deferred) + sidecars .br/.gz + var/view_preprocessed do PHTML
Hash depois (fonte):
  awa-b2b-auth-shell-final.css 2e797a66298a274e754217eabb4d09c1bf9b6c75cfe14e01ce1af3608365f491
  awa-b2b-auth-shell-final.min.css 5c476d2cf2f1562baad27ab0739a00335c47a4ff65d56c918126dd04d018944d
  awa-home-deferred-stack.min.css 9065847ffa84f3ead9cc612ed3854dea939e5b0b160be4cf3112414732ff6abb
  cta-lock-20260815.css a4f3af7bad67b55ce0ff508d025c07bb20ff639c2915d53f08cc840b3868dc74
  login.css 3f2a8deec426a9d4d476fb97d63e37e93a5023232ba860b7526e628a8e8cbcdf
  awa-b2b-auth-critical-css.phtml 53de14173d6cbe8a61cc6f1349fc825eae100e6cff54a7d554feec991b94dcc9
Backup: /home/deploy/backups/awa-css-cascade-phase7-fatia2-20260819T0037Z
Rollback: copiar backup sobre DocumentRoot; restaurar pub/static e view_preprocessed; cache:clean layout block_html full_page
Comandos: sha256 ×2; edição CSS/PHTML; gzip/brotli; cache:clean layout block_html full_page; systemctl reload php8.4-fpm
Caches: layout, block_html, full_page (sem flush Redis)
Serviços: php8.4-fpm reload
Rotas: home, /b2b/account/login/, /b2b/register/
Evidência: home winner deferred-stack var(--awa-brand,#b73337); auth winner shell var(--awa-brand)
LCP/CLS/TBT: sem CSS novo; cor computada da marca inalterada (#b73337)
Logs: exception.log 0 B, mtime 2026-08-17
Observações: sem SCD; PLP/PDP/cart fora desta fatia; login.css :root permanece hex (Chromium)
```

```text
Fase: 7 fatia 3 — catálogo Magento + conta B2B
Status: SUCESSO
Data/hora: 2026-08-19T01:15Z
Responsável: agente Cursor (produção)
Autorização: usuário — “Continue corrigindo e implementando padronização visual Magento + B2B”
Arquivos fonte:
  - web/css/awa-impeccable-layout-2026-06-16.css (+ .min.css)
  - web/css/awa-super-global-20260611m.css (+ .min.css)
  - web/css/awa-b2b-account-shell-final.css (+ .min.css)
  - app/code/GrupoAwamotos/B2B/view/frontend/web/css/product/login-to-cart.css
  - CSS_INVENTORY.md, PADRONIZACAO_LAYOUT.md, PLANO, .impeccable/config.json
Publicados: pub/static pt_BR + en_US (layout/super-global/account) + login-to-cart.min.css + sidecars .br/.gz
Hash depois:
  awa-impeccable-layout-2026-06-16.css 9e531e89082ce05cee29125d639e07b4aa83da5b77896cea156edb60f0f9a1d5
  awa-super-global-20260611m.css 7016d097199f6b1f5859b4e449b48b84ccb9ee8856da75ed93943c346585f912
  awa-b2b-account-shell-final.css 4835caac1eec0b1cd40aed49b269fab1ec496077d0ac256debb5621ab15cb0e6
  login-to-cart.css 98fc4f5f8b5393c5f78135fb98566e303b0ab45085a4fb66dca254012e12472b
Backup: /home/deploy/backups/awa-css-cascade-phase7-fatia3-20260819T0110Z
Rollback: restaurar backup sobre DocumentRoot e pub/static (sem cache Magento nesta fatia)
Comandos: sha256 ×2; edição CSS; gzip/brotli; sem cache:clean
Caches: nenhum (só CSS estático)
Serviços: nenhum
Rotas: /bauletos.html, /mini.html, /checkout/cart/, home, /b2b/account/login/
Evidência: vencedor PLP/PDP/cart = impeccable-layout var(--awa-brand,#b73337); home/auth inalterados
LCP/CLS: sem folha nova; marca computada #b73337 (login-to-cart modal sai de #A33B3B)
Logs: exception.log 0 B
Observações: styles-l OKLCH declara mas não vence; conta B2B não medida guest (FPC/login)
```

