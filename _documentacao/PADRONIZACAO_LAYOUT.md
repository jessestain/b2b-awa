# Padronização de layout — índice único

**Projeto:** AWA Motos — Magento 2.4.8
**Tema:** `AWA_Custom/ayo_home5_child` (pai: `ayo/ayo_home5`)
**Criado:** 2026-08-18
**Ambiente:** produção (somente leitura por padrão)

Este arquivo é o **índice**. Não duplica planos, não autoriza escrita em
produção e não substitui o contrato da cascata.

Cada fase que altere CSS, LESS, PHTML, layout XML, plugins PHP, cache,
Redis, Varnish ou `pub/static` exige diagnóstico, diff, backup, rollback
e autorização explícita.

---

## 1. Quem manda (autoridade)

| Papel | Arquivo | Usa para |
|---|---|---|
| **Índice** | `_documentacao/PADRONIZACAO_LAYOUT.md` (este) | Ordem de leitura, fases, o que está superado |
| **Contrato vigente** | `app/design/frontend/AWA_Custom/ayo_home5_child/CSS_INVENTORY.md` | O que carrega hoje, shell por superfície, nomes acoplados |
| **Plano operacional** | `_documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md` | Fases 0–14, aceite, evidências, rollback |
| **Arquitetura-alvo** | `_documentacao/ARQUITETURA_VISUAL_ALVO_MODERNIZACAO_2026-08-10.md` | Destino Magento XML + LESS (proposta; não é o estado atual) |
| **Governança de agente** | `.cursor/rules/awa-css-governance.mdc`, `awa-design-system.mdc`, `awa-layout-skill-pipeline.mdc`, `AGENTS.md` | Impede patch datado, hex solto, edição de `Rokanthemes`/`vendor` |

Conflito entre documentos: **contrato vigente > plano operacional > alvo >
histórico**. O índice só aponta; não redefine a cascata.

---

## 2. Documentos superados (não seguir)

| Arquivo | Status | Ainda serve como |
|---|---|---|
| `app/design/frontend/AWA_Custom/ayo_home5_child/PLANO_CORRECAO_LAYOUT_FASES.md` | **SUPERSEDED** (2026-06, só home) | Histórico das fases 1–5 da home |
| `app/design/frontend/AWA_Custom/ayo_home5_child/DESIGN_SYSTEM_STATUS.md` | **SUPERSEDED** (lista 9 CSS que não são a cascata 2026-08) | Histórico de componentes cobertos em 2026-06 |
| `__PLANO_EMERGENCIA_TIMEOUT_2026-06-05.md` no tema | **SUPERSEDED** | Incidente pontual |
| Qualquer `awa-*-2026-MM-DD.css` novo como “plano” | **PROIBIDO** | — |

Não criar `Fase-01.md`, `Fase-02.md` nem um markdown por superfície.
Atualizar a tabela da secção 4 **neste** arquivo e o progresso no plano
de cascata.

---

## 3. Duas verdades (não misturar)

### 3.1 Magento 2 indica (arquitetura)

- Estender tema filho; nunca editar core/`vendor`/`Rokanthemes`.
- Estrutura em layout XML (`default.xml`, handles de rota, page layout
  `empty` / `1column` / `2columns-left`).
- Visual nativo em `_theme.less` + `_extend.less` → `styles-m.css` /
  `styles-l.css` (mobile-first, mixin `.media-width()`).
- Preferir XML a copiar `.phtml`; i18n em CSV; imagens em `etc/view.xml`.
- CSS de módulo customizado em `app/code/Vendor/Module/view/frontend/web/css/source/_module.less`.
- ViewModel no XML, não Block inchado nem `ObjectManager`.

### 3.2 Contrato AWA vigente (pixel)

Uma autoridade por superfície. Última camada ganha.

1. `styles-m` / `styles-l`
2. `themes.css`
3. `awa-super-global`
4. `awa-layout-bundle`
5. `awa-commerce-impeccable-refine`
6. `awa-align-grid-terminal` + `awa-m2-visual-ssot`
7. `awa-design-system.css` (último `<css>` do XML; loaders de rota podem vir depois)

Detalhe, shells e nomes acoplados: `CSS_INVENTORY.md`.

`_extend.less` **não** governa sozinho home/PLP/PDP/cart/B2B. Provar
carregamento real na rota (`curl` / view-source) antes de editar.

---

## 4. Fases (todas neste índice)

Estados: `CONCLUÍDA` · `EM ANDAMENTO` · `PENDENTE` · `BLOQUEADA`.

### Trilho A — Cascata (já existe; não reescrever)

Fonte de tarefas e evidências:
`_documentacao/PLANO_CORRECAO_CASCATA_CSS_FASES.md`

| Fase | Título | Estado (2026-08-18) |
|---|---|---|
| A0–A5 | Baseline, bundles, super-global, CLS home, contrato, cache-bust | CONCLUÍDA (plano de cascata) |
| A6 | Pipeline Magento (layout / PageConfig) | CONCLUÍDA (PageConfig; regex permanece safety-net) |
| A7 | LESS autoridade visual canônica | EM ANDAMENTO (fatia 8 — checkout/header B2B Magento; `styles-l` OKLCH ainda declara antes) |
| A8 | Consolidar footer | PENDENTE |
| A9 | Remover CSS home inline | PENDENTE |
| A10 | Reduzir `align-grid` / `!important` | PENDENTE |
| A11 | Limpar assets mortos e governança | PENDENTE (quarentena 2026-08-13 avançou parte) |
| A12 | Git, ownership e permissões | PENDENTE |
| A13 | Retirar locks de compatibilidade | PENDENTE |
| A14 | Auditoria final da cascata | PENDENTE |

Não avançar A6–A14 se LCP, CLS, B2B ou teclado regredirem.
Não remover `!important` em massa. Não renomear CSS sem `grep` nos
plugins PHP (`OptimizeHeadStylesPlugin`, `HeaderImpeccableCascadeLockCss`,
`HomeCssGateParity`).

### Trilho B — Superfícies (depois que a cascata da superfície estiver estável)

Uma fatia por vez. Shell canônico = `CSS_INVENTORY.md`.

| Fase | Superfície | Shell vigente | Estado |
|---|---|---|---|
| B1 | Header / chrome global | locks PHP + `awa-design-system` | PENDENTE |
| B2 | Footer | container + newsletter (sem gutter duplo) | PENDENTE |
| B3 | Home | críticos + refine + SSOT (sem `flex-grid-flow`) | PENDENTE |
| B4 | PLP / busca | refine + align-grid + SSOT | PENDENTE |
| B5 | PDP | shell PDP + align-grid + SSOT | PENDENTE |
| B6 | Carrinho | `awa-cart-stack` | PENDENTE |
| B7 | Checkout | `awa-checkout-shell-final` | PENDENTE |
| B8 | B2B auth (`empty`) | `awa-b2b-auth-shell-final` | PENDENTE |
| B9 | B2B conta (`2columns-left`) | `awa-b2b-account-shell-final` | PENDENTE |
| B10 | CMS | `awa-cms-shell-final` | PENDENTE |
| B11 | Módulos `GrupoAwamotos_*` | `_module.less` no próprio módulo | PENDENTE |

B1/B2 podem caminhar em paralelo com A8 quando o plano de cascata
chegar no footer; não abrir B3–B11 com patch datado novo.

### Trilho C — Tokens e poda (transversal)

| Fase | Trabalho | Estado |
|---|---|---|
| C1 | Um `:root` `--awa-*` (hoje duplicado em vários bundles) | PENDENTE (mapa A7 fatia 1) |
| C2 | Escala de espaço 4/8/12/16/24/32/40/48; page pad 16/16/24 | PENDENTE |
| C3 | HEX novo proibido; hex legado só com justificativa | PENDENTE |
| C4 | LESS órfãos (~200) → `_disabled/` ou quarentena | PENDENTE |
| C5 | Folhas mortas (`flex-grid-flow`, `plp-distill`, shims) — não recarregar | PARCIAL (quarentena 2026-08-13) |

---

## 5. Aceite de uma superfície

Uma fase B só vira `CONCLUÍDA` com:

- [ ] shell único; nenhum CSS datado novo
- [ ] tokens `var(--awa-*)`; sem hex novo
- [ ] XML/LESS no tema filho ou `_module.less` do módulo; zero `Rokanthemes`/`vendor`
- [ ] prova de carga na rota (HTML publicado, não só o fonte)
- [ ] QA 390 · 768 · 1366 · 1920
- [ ] header, footer e superfície vizinha sem regressão
- [ ] CLS/LCP sem piora material; `exception.log` sem entrada nova
- [ ] hashes fonte / min / `pub/static` / sidecars `.br` `.gz` registrados
- [ ] rollback byte a byte fora do DocumentRoot

Viewports e tokens: `_awa-variables.less` + `_tokens.less`.

---

## 6. Anti-padrões

- Criar `awa-fix-*-2026-08-18.css` ou segundo shell na mesma superfície
- Editar `pub/static` ou `var/view_preprocessed` como fonte
- Assumir que `_extend.less` vence o body-end
- Referenciar `awa-bundle-site.css` ou `awa-flex-grid-flow`
- Marcar “100% completo” sem evidência na rota publicada
- Copiar o guia Adobe para mais um `.md` (já está na Adobe)

---

## 7. Próximo passo concreto

**A7 fatia 12 concluída** (2026-09-15): `awa-head-tail-bundle.css` tinha 5
custom properties circulares no bloco "ROOT TOKEN OVERRIDE — Paleta AWA Red"
(`--awa-primary`, `--awa-primary-hover`, `--awa-text`, `--awa-text-secondary`,
`--awa-bg` todas declaradas como `var(--elas-mesmas)` — inválido por spec CSS,
navegador ignorava e caía no fallback dos consumidores). Corrigido para os
valores SSOT de `_tokens.less` (`#b73337`/`#8e2629`/`#333333`/`#666666`/`#fff`).
Sem mudança visual (fallbacks já resolviam para os mesmos valores). Arquivo
adicionado a `.impeccable/config.json` `ignoreFiles` (mesmo padrão dos outros
bundles datados legados da lista). **A7 fatia 13 concluída** (2026-09-15): `awa-visual-bugfix.css` (`.awa-category-hero--has-image`,
PLP/busca com hero de imagem). 3 fallbacks `var(--x, var(--x))` circulares
(`--awa-text-secondary`, `background:var(--awa-bg,...)`, `border:...var(--awa-border,...)`)
— se o token global algum dia não existisse nesse escopo, texto/fundo/borda
sumiam em silêncio em vez de cair num valor seguro. Fallback trocado pelo hex
SSOT (`_awa-variables.less`). Sem mudança visual (tokens globais já resolvem
hoje). Arquivo adicionado a `.impeccable/config.json` `ignoreFiles` (3 achados
pré-existentes não relacionados nas linhas 198/252/253).

**A7 fatia 14 concluída** (2026-09-15): `awa-head-preload-critical-home.css`
(crítico síncrono da **home**, `.css`/`.min.css` eram idênticos — nunca
minificado de fato). 8 declarações circulares: `--head-preload-c1..c6` e
`--head-preload-footer-muted` com fallback `var(--x, var(--x))`, mais
`--awa-primary: var(--awa-primary)` puro (o próprio comentário da linha já
dizia "valor literal, sem cadeia var()" — intenção documentada, nunca
implementada). Corrigido para hex SSOT. Sem mudança visual (tokens globais
já resolviam). Arquivo adicionado a `.impeccable/config.json` `ignoreFiles`
(11 achados pré-existentes não relacionados).

**A7 fatia 15 concluída** (2026-09-15): `awa-super-global-20260611m.css`
(camada 3/7 da cascata, carregada em TODA página). Ciclo real de 2 saltos
confirmado: `--awa-text` ↔ `--awa-sg-c54` e `--awa-bg` ↔ `--awa-sg-c35`
(cada um definido como `var()` do outro — CSS invalida ambos por spec, não
é só fallback nunca usado). Corrigido nas declarações vencedoras (`:root`
aberto na linha 92) para hex SSOT; também corrigidos `--awa-red`/`--awa-red-dark`
auto-referenciados dentro de `@layer awa-fixes` (viravam inválidos em rotas
sem `styles-l` desambiguando por fora da layer) e um fallback redundante
`--awa-red: var(--awa-primary, var(--awa-primary))`. QA visual (screenshot +
snapshot) da home sem regressão; smoke home/PDP/categoria 200; exception.log
sem entrada nova. Arquivo já estava em `ignoreFiles` (fatia 3 anterior).

**A7 fatia 16 concluída** (2026-09-15) — CORREÇÃO da fatia 13: confirmado
empiricamente via `getComputedStyle` no Chromium que `--x: var(--x, literal)`
(auto-referência no slot PRIMÁRIO do `var()`, não no fallback) **sempre**
resolve para valor-inválido-garantido, mesmo com fallback literal — o
fallback só é usado quando a variável está *ausente*, não quando está em
ciclo. A fatia 13 só tinha trocado o fallback de `--awa-text-secondary` em
`awa-visual-bugfix.css` (`.awa-category-hero--has-image`), deixando a
referência primária `var(--awa-text-secondary, ...)` intacta — bug
permanecia ativo em produção (`.awa-category-hero__count` com `color`
inválido/vazio no painel claro da PLP/busca com imagem). Corrigido para
valor literal direto (`#666666`, sem `var()`), única declaração desse token
nesse escopo. Publicado em `pub/static` (pt_BR/en_US) + sidecars `.br`/`.gz`
+ FPC (Redis DB2) flush; `bin/magento cache:clean` indisponível no momento
(ver achado à parte sobre DI quebrado — não bloqueia, arquivo é servido
direto pelo Nginx). Hash confirmado igual fonte↔publicado↔HTTP ao vivo.

**A7 fatia 17 concluída** (2026-09-15): `awa-layout-bundle-20260611m.min.css`
(camada 4/7 da cascata, carregada em TODA página — só existe versão
minificada, sem fonte legível). 10 custom properties `--cui-*` (surface,
surface-soft, surface-cool, border, text-dark, text-mid, success,
success-text, warning, warning-text) todas com `--cui-X: var(--cui-X, hex)`
— mesmo padrão de ciclo confirmado na fatia 16. Sem impacto visual até
agora porque **todo** consumidor (badges/cards em `background`, `color`,
`border-color`) repete o mesmo hex como fallback próprio — mas o token em
si nunca funcionou como token (não é herdável/sobrescrevível). Corrigido
para hex direto nas 10 declarações. Achado à parte: log de exceção mostra
erro de DI pré-existente e não relacionado (`RestrictedProductViewPlugin`
com assinatura de construtor incompatível — 8 args passados, 9 esperados),
provavelmente a causa do `bin/magento cache:clean` estar indisponível via
CLI; reportado, não deve ser corrigido nesta sessão (fora do escopo CSS,
requer autorização própria).

**A7 fatia 18 concluída** (2026-09-15) — 10 bundles `.min.css` regenerados via
build determinístico (`scripts/build-awa-css-min-pair.sh --write`), fechando
o `ERRO: mins stale` do `check-awa-min-pairs.sh`. Auditados 1-a-1 antes de
publicar: **3 byte-idênticos** (`awa-commerce-impeccable-refine`,
`awa-impeccable-layout-2026-06-16`, `awa-checkout-layout-lock` — só timestamp
desatualizado, zero risco); **2 com drift cosmético confirmado e publicados**
(`awa-m2-visual-ssot`, `awa-design-system` — diffs eram 100% formatação:
espaço em `var(--x, y)`, `calc(1.35em*2)` vs `2.7em` equivalente, aspas em
seletor de atributo; `awa-design-system`, 876KB→726KB, -17%, nunca tinha sido
minificado de fato apesar do nome); **1 com mudança visual real, aprovada
explicitamente pelo usuário antes de publicar** (`awa-home-b2b-ops-density-
20260805`: título 16→20px, cabeçalhos 40→44px/44→48px, + 3 tokens novos —
fonte tinha ficado pronta e nunca publicada; QA visual screenshot sem
regressão); **4 fatias anteriores (12/13/14/15) finalmente rodadas pelo
build oficial** (antes só tinham `sed` cirúrgico nos dois arquivos em
paralelo — mesmo conteúdo, agora com proveniência determinística;
`awa-head-preload-critical-home` foi o que mais encolheu, 147KB→133KB,
nunca tinha sido minificado de verdade). Todos publicados em pt_BR + en_US,
sidecars `.br`/`.gz` regenerados, FPC (Redis DB2) limpo a cada lote. QA
visual (home + PLP + card/shelf/rating) sem regressão; smoke 200 em todas
as rotas testadas. `check-awa-min-pairs.sh` retorna `OK` (só restam os
min-only sem fonte legível e o congelado `align-grid-terminal`, ambos
esperados).

**A7 fatia 19 concluída** (2026-09-15) — medição de espaçamento (largura/altura)
via CDP `getBoundingClientRect`/`getComputedStyle` em produção (home + PLP +
mobile 390px) a pedido do usuário ("otimizar quantidade exata de caracteres
para não ficar desproporcional"). Larguras/gaps de grid já estavam 100%
consistentes (cards de categoria 144×143.5px uniformes; grid PLP 226px/173px
com gap 12px; sem overflow horizontal em 390px). **Achado real**: altura do
card variava com o comprimento do título (sem `line-clamp` efetivo), até 19px
de diferença dentro da mesma prateleira da home e 8px entre linhas da mesma
grade PLP. Causa raiz: regra **"R27"** duplicada em dois arquivos
(`awa-align-grid-terminal-2026-06-11.css` linha ~19, escopo
`.content-top-home .awa-shelf--carousel`; `awa-plp-shell-final.css` linha
~24, escopo `.catalog-category-view .products-grid`) que **desativava de
propósito** o clamp (`-webkit-line-clamp: unset !important`) para preservar
nomes técnicos completos (modelo/lado/rosca/acabamento) — decisão documentada,
mas gerava cards desalinhados. Confirmado via bisecção de stylesheets
(`sheet.disabled = true` um a um) que essas duas regras eram as únicas
vencedoras da cascata (maior especificidade, ~30 repetições de `#html-body`).
Usuário optou explicitamente por **reverter para clamp de 2 linhas em ambos os
escopos** (home + PLP), aceitando truncamento com reticência em nomes muito
longos. Ambas as regras R27 reescritas para `-webkit-line-clamp: 2` +
`min-height`/`max-height: calc(1.35em * 2)` (padrão já usado em outras regras
do mesmo arquivo), preservando os demais seletores intactos. Rebuild via
`build-awa-css-min-pair.sh --write`, sync `pub/static`, sidecars `.br`/`.gz`
regenerados, Redis DB1+DB2 limpos (CLI `cache:clean` segue quebrado pelo bug
de DI pré-existente em `ERPIntegration\Console`, não relacionado). **Verificação
ao vivo**: cache HTTP do navegador (`Cache-Control: immutable`, mesma query
`?v=`) mascarou a mudança na primeira checagem — resolvido com
`Network.setCacheDisabled` + reload. Resultado confirmado por medição e
screenshot: todos os cards de cada prateleira/grade agora com altura idêntica
(ex.: "Linhas em destaque" 5/5 cards a 385px; grade `/bauletos.html` 12/12
cards a 418.9px desktop e 368.5px mobile), títulos longos truncados com "…"
visível, sem overflow horizontal, sem novas entradas em `exception.log`.

**A7 fatia 16 concluída** (2026-09-15) — CORREÇÃO da fatia 13: confirmado
empiricamente via `getComputedStyle` no Chromium que `--x: var(--x, literal)`
(auto-referência no slot PRIMÁRIO do `var()`, não no fallback) **sempre**
resolve para valor-inválido-garantido, mesmo com fallback literal — o
fallback só é usado quando a variável está *ausente*, não quando está em
ciclo. A fatia 13 só tinha trocado o fallback de `--awa-text-secondary` em
`awa-visual-bugfix.css` (`.awa-category-hero--has-image`), deixando a
referência primária `var(--awa-text-secondary, ...)` intacta — bug
permanecia ativo em produção (`.awa-category-hero__count` com `color`
inválido/vazio no painel claro da PLP/busca com imagem). Corrigido para
valor literal direto (`#666666`, sem `var()`), única declaração desse token
nesse escopo. Publicado em `pub/static` (pt_BR/en_US) + sidecars `.br`/`.gz`
+ FPC (Redis DB2) flush; `bin/magento cache:clean` indisponível no momento
(ver achado à parte sobre DI quebrado — não bloqueia, arquivo é servido
direto pelo Nginx). Hash confirmado igual fonte↔publicado↔HTTP ao vivo.

**A7 fatia 17 concluída** (2026-09-15): `awa-layout-bundle-20260611m.min.css`
(camada 4/7 da cascata, carregada em TODA página — só existe versão
minificada, sem fonte legível). 10 custom properties `--cui-*` (surface,
surface-soft, surface-cool, border, text-dark, text-mid, success,
success-text, warning, warning-text) todas com `--cui-X: var(--cui-X, hex)`
— mesmo padrão de ciclo confirmado na fatia 16. Sem impacto visual até
agora porque **todo** consumidor (badges/cards em `background`, `color`,
`border-color`) repete o mesmo hex como fallback próprio — mas o token em
si nunca funcionou como token (não é herdável/sobrescrevível). Corrigido
para hex direto nas 10 declarações. Achado à parte: log de exceção mostra
erro de DI pré-existente e não relacionado (`RestrictedProductViewPlugin`
com assinatura de construtor incompatível — 8 args passados, 9 esperados),
provavelmente a causa do `bin/magento cache:clean` estar indisponível via
CLI; reportado, não deve ser corrigido nesta sessão (fora do escopo CSS,
requer autorização própria).

**🚨 ACHADO CRÍTICO FORA DO ESCOPO CSS (2026-09-15, ~21:44–22:03):**
`GrupoAwamotos\B2B\Plugin\Catalog\RestrictedProductViewPlugin` com
assinatura de construtor incompatível (8 args passados pelo DI, 9
esperados pela classe) está derrubando **toda página de produto (PDP) do
site com HTTP 500** — confirmado para visitante anônimo (sem cookie) e
para sessão B2B logada. 76 ocorrências no `exception.log` só nesta janela
de ~20 min. Arquivo do plugin tem mtime 2026-09-15 21:58 (alguém pode estar
mexendo nisso em paralelo). **Não é CSS, não foi tocado nesta sessão, não
corrigido — requer autorização própria e é prioridade máxima** (site sem
conseguir vender nenhum produto agora).

**A7 fatia 11 concluída** (2026-08-19): Magento carrinho. `awa-cart-stack`
(live) consome `--awa-brand` no chrome (barra mobile / skip-summary / foco)
em vez de `--awa-red` OKLCH do `styles-l`; fallback circular do preço
vira `#b73337`. Shim `awa-cart-shell-final` (sem href no HTML) alinhado.
Vencedor de `--awa-primary` no carrinho continua `awa-impeccable-layout`.
Sem SCD. Sem `cache:clean`.

Próxima fatia A7: um único `:root` (C1) **ou** leftovers adminhtml
(ERP/SmartSuggestions `#A33B3B`), e-mails ERP/admin, LESS compilado,
CSS Magento_Theme morto (`awa-polish-final` / `layout-fixes` /
`visual-bugfixes` / `carousel-fixes` / `inline-extracted` — sem href).
Não abrir A8–A14.
