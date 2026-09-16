# Modernização Visual — AWA_Custom/ayo_home5_child
**Auditoria de arquitetura frontend · 2026-08-10 · Autor: análise assistida (Kimi Code) · Status: PROPOSTA — nenhuma mudança de código executada por este documento**

> **Índice de padronização:** [`PADRONIZACAO_LAYOUT.md`](PADRONIZACAO_LAYOUT.md).
> Este arquivo é a **arquitetura-alvo** (proposta). O estado publicado está em
> `app/design/frontend/AWA_Custom/ayo_home5_child/CSS_INVENTORY.md`.

> Alinhamento de referência: práticas oficiais Adobe Commerce Frontend Developer Guide —
> herança de temas (extensão via `_extend.less`/`_theme.less`, não override de arquivos do pai),
> Magento UI Library, mobile-first (`styles-m` mobile base + `styles-l` ≥768px via mixin `.media-width()`),
> tokens LESS, acessibilidade WCAG 2.1 AA, pipeline de static content deploy reproduzível.

---

## 1. Diagnóstico (evidências medidas em 2026-08-10)

### 1.1 Escala do problema

| Sintoma | Medição | Fonte |
|---|---|---|
| `!important` em CSS/LESS ativo | **99.699 ocorrências** (86% sem comentário) | auditoria ripgrep |
| Hex fora dos tokens `--awa-*` | ~8.800 | auditoria ripgrep |
| Arquivos em `web/css/` | **139 `.css` + 68 `.min.css`** | ls |
| Bundles com nome datado/legado carregados | 34 de 70 (49%) | loaders PHTML/PHP |
| `.min.css` dessincronizados da fonte | **8 stems críticos** (super-global 18 dias atrás; visual-bugfix/impeccable-layout 43 dias) | `build-awa-css-min-pair.sh --check` |
| Folhas servidas por página (home) | **83 stylesheets** no documento | probe Playwright |
| LESS órfãos (não importados) | ~200 de 251 em `css/source/` | `_extend.less` (31 imports ativos de 133 linhas) |
| `<style>` inline | 89 em PHTML + 130 injetados via PHP | auditoria |
| `style="` inline | 255 (72% adminhtml) | auditoria |
| Lighthouse | HOME perf **60** (TBT **8.419ms**), PDP perf **36** (CLS **0.576**) | `audit/lighthouse/pre-consolidacao-2026-08-10/` |

### 1.2 Causas-raiz (não sintomas)

1. **Cascata paralela ao Magento.** A cascata real não é a do layout XML do Magento: é montada por `awa-head-preload.phtml` (2.835 linhas, lógica por rota), `awa-align-grid-terminal-loader.phtml` (7 layouts) e 3 plugins PHP (`OptimizeHeadStylesPlugin.php`, `HeaderImpeccableCascadeLockCss.php`, `HomeCssGateParity.php`) que injetam/deduplicam/seguram CSS via **regex sobre o HTML renderizado, casando por nome de arquivo** (~15 pontos hardcoded). Isso viola o modelo Adobe (assets declarados em `default_head_blocks.xml`/page layout, merge/minify nativos) e torna qualquer rename de arquivo uma quebra silenciosa (`__emitDeferredCss` omite link ausente sem erro).

2. **FOUC arquitetural.** `media="print"` + swap JS + fila `__awaCssQ` + paint gate (`themes|third-party|visual-bugfix|deferred-stack` retidos até scroll/pointerdown/3s). No refresh, o primeiro paint sai só com crítico inline; os cards de produto (estilizados por 3+ bundles sobrepostos) renderizam com metade das regras durante a janela — é o "layout desconfigurado ao atualizar" reportado. Confirmado ao vivo: 6,5s após load ainda havia fila pendente.

3. **Build não reproduzível.** Pares `.css`/`.min.css` mantidos à mão; minificados servidos dias/semanas atrás da fonte; edições diretas em `pub/static` (script `build-awa-css-min-pair.sh` sincroniza pub in-place sem cache-bust); `bin/deploy-static` faz deploy sem `--theme` (diverge do AGENTS.md) e **não valida** se mins estão frescos. Tokens `--awa-*` duplicados em `:root` de 5 bundles compilados.

4. **Erosão por "terminal locks".** Padrão de trabalho por hotfix datado (`*-terminal-*`, `*-final`, `*-wins`, datas no nome) com specificity-lock `html body#html-body#html-body...` + `!important` — cada fix vence o anterior por força bruta em vez de posição na cascata. Os 10 maiores bundles datados concentram ~44% de todos os `!important`.

5. **Fontes.** Rubik self-hosted (Google Fonts removido corretamente para evitar download duplo — `default_head_blocks.xml:121-123`), mas `@font-face` espalhados em bundles compilados (`super-global`, `third-party-bundle`) em vez de um ponto único com `font-display: swap` garantido.

6. **Governança quebrada.** `guard:visual-baselines` aponta para script inexistente; documentação da cascata no AGENTS.md/CSS_INVENTORY.md estava 4 meses desatualizada (corrigido hoje); trabalho concorrente não commitado em arquivos críticos (24 arquivos dirty, incl. `align-grid` e os 2 plugins de cascata).

---

## 2. Arquitetura visual-alvo

### 2.1 Princípios (Adobe + moderno)

- **Extensão, não override**: estilos globais vivem em `web/css/source/_extend.less` (extend do tema pai); overrides de template/layout só quando indispensável.
- **Tokens SSOT**: uma única fonte de tokens (`source/_awa-variables.less` → `--awa-*` emitidos **uma vez**, no `:root` do bundle base). Nenhum hex fora dela.
- **Cascata declarativa**: todo CSS de página declarado via layout XML (`default_head_blocks.xml` + handles de rota). Zero injeção por regex PHP. Zero `<style>` em PHTML/PHP.
- **Cascade layers explícitas** substituindo specificity-lock + `!important`:
  `@layer awa-tokens, awa-base, awa-vendor, awa-components, awa-pages, awa-overrides;`
- **Mobile-first nativo**: base em `styles-m` (mobile), desktop via `.media-width(@extremum, @break)` em `styles-l` — o modelo Magento, não media queries soltas em bundles.
- **Build reproduzível**: fonte `.css` → `.min.css` sempre pelo mesmo script determinístico (cleancss `-O1`), com **guard de sincronia** que bloqueia deploy stale; deploy cria novo `version{N}` (cache-bust automático); ninguém edita `pub/static` diretamente.
- **Acessibilidade**: WCAG 2.1 AA — contraste nos tokens, touch target 44px, foco visível, `prefers-reduced-motion`.

### 2.2 Cascata-alvo (6 camadas, ~12 folhas em vez de 70)

```
1. styles-m/l.css            (LESS Magento — tokens + base + componentes via _extend.less)
2. themes.css                (pai, merge nativo)
3. awa-base.min.css          (:root tokens únicos + reset + tipografia + @font-face único)     @layer awa-tokens, awa-base
4. awa-components.min.css    (cards, botões, forms, header, footer — Magento UI library + AWA) @layer awa-components
5. awa-pages-{home|plp|pdp|checkout|b2b}.min.css  (só o que a rota precisa, via handle XML)    @layer awa-pages
6. awa-overrides.min.css     (única camada com !important permitido, comentado, @layer final)  @layer awa-overrides
```

- Carregamento: síncrono via layout XML; `styles-l` com `media="screen and (min-width: 768px)"` nativo. **Fim do paint gate, da fila `__awaCssQ` e do `media="print"` swap** — FOUC eliminado por construção, não por remendo.
- Crítico inline: apenas variáveis + regras above-fold do header (≤14KB), gerado no build a partir de `source/`, não mantido à mão em PHTML de 2.835 linhas.

### 2.3 Migração dos plugins PHP

`OptimizeHeadStylesPlugin.php` (8.082 linhas), `HeaderImpeccableCascadeLockCss.php`, `HomeCssGateParity.php` → aposentar por absorção: cada responsabilidade legítima (dedup de assets, ordem de head) é nativa do Magento (`<remove>`/`<move>` em layout XML, `minify_css`, `merge_css`, dev/static signing). O que sobrar vira 1 plugin pequeno e testável, sem regex por nome de arquivo.

---

## 3. Mapa de arquivos

### 3.1 Manter (núcleo da arquitetura-alvo)

| Arquivo | Papel |
|---|---|
| `web/css/source/_awa-variables.less` | SSOT tokens LESS (`@awa-*`) |
| `web/css/source/_tokens.less` | Emissão `--awa-*` (será o único `:root`) |
| `web/css/source/_theme.less`, `_extend.less` | Pontos de extensão Adobe (só 31 imports ativos — saneá-los) |
| `scripts/build-awa-css-min-pair.sh` | Build determinístico fonte→min (base do guard) |
| `bin/deploy-static` | Deploy (corrigir `--theme`, adicionar guard) |
| `tests/e2e/pw-visual-core.config.ts` + snapshots | Rede de segurança visual (criada hoje) |

### 3.2 Migrar (conteúdo absorvido nas camadas-alvo, na ordem)

| Origem | Destino |
|---|---|
| `awa-super-global-20260611m` | `awa-base` + `awa-components` (remover 3 trechos de sintaxe inválida — linhas 539, 3040, 3737) |
| `awa-layout-bundle-20260611m` | `awa-base` (grid/container) |
| `awa-commerce-impeccable-refine` | `awa-components`/`awa-pages-*` |
| `awa-align-grid-terminal-2026-06-11` (25,6k linhas) | seções §0–§25 → camadas correspondentes (**congelado até o trabalho concorrente commitar**) |
| `awa-m2-visual-ssot` | `awa-components` (rating/shelf/cards/badge) |
| `awa-home-deferred-stack`, `awa-home-critical-stack`, `defer-global-bundle` | `awa-pages-home` (dissolver o conceito "deferred") |
| 3 datados do `default_head_blocks.xml` (`visual-fixes-2026-06-29-final`, `visual-noise-2026-07-15-r2`, `cookie-fab-collision-fix`) | `awa-overrides` (1º merge prático) |
| `:root` duplicados em 5 bundles | `_tokens.less` único |
| 89+130 blocos `<style>` PHTML/PHP | classes nas camadas |
| `awa-head-preload.phtml` | morre junto com o paint gate; crítico inline gerado no build |

### 3.3 Eliminar / quarentena

- ~200 LESS órfãos em `css/source/` → `_disabled/`
- `_awa-flex-grid-flow.less` (fonte órfã cujo min é carregado — mesma base em 2 lugares)
- 102 linhas comentadas de `@import` em `_extend.less` (histórico, não código)
- `awa-bundle-site.css` (shim vazio), `*.bak-20260719_*`, anotações `__*.md` em `web/css/`
- 34 bundles datados após absorção (git preserva histórico)

### 3.4 Congelados agora (trabalho concorrente não commitado)

`awa-align-grid-terminal-2026-06-11.css`, `OptimizeHeadStylesPlugin.php`, `HeaderImpeccableCascadeLockCss.php`, `DeferHomeScriptsPlugin.php`, 5 PHTMLs — **não tocar até o outro fluxo commitar**.

---

## 4. Riscos e mitigações

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Rename quebra regex PHP silenciosamente | Alta | Alto | Nunca renomear antes de aposentar os plugins; grep obrigatório (já documentado no AGENTS.md) |
| FOUC "invisível" esconde regressão ao remover paint gate | Média | Alto | Remover gate só depois que a cascata-alvo estiver completa; baseline visual + LCP por fase |
| Conflito com agente concorrente (24 arquivos dirty) | Alta | Médio | Respeitar congelados; commit/push do outro fluxo é pré-requisito da P2 |
| Baseline Playwright flaky (Menu: clip dinâmico) | Média | Baixo | Tratar falha de Menu inspecionando diff; estabilizar clip na P1 |
| Minificação descarta trechos inválidos das fontes | Baixa | Médio | cleancss warnings viram falha de build na P1; corrigir sintaxe nas fontes |
| Deploy em produção | — | Alto | Uma fase por deploy; rollback = `git revert` + `bin/deploy-static` (version dir anterior é regenerável); FPC DB2 flush pós-deploy |
| Varnish (6081) servindo HTML com version dir antigo | Média | Alto | `varnishadm ban req.url ~ /static/` pós-deploy; verificar header `Age` no smoke |

---

## 5. Plano progressivo com rollback

| Fase | Escopo | Validação de saída | Rollback |
|---|---|---|---|
| **P0 — Fundação** (hoje) | Docs sincronizados ✅; baselines visuais ✅; Lighthouse baseline ✅; 8 mins regenerados + deploy ✅ (validação pendente) | suite visual-core verde; CSS 200; logs limpos | mins anteriores no git |
| **P1 — Build reproduzível** | Patch #1 (abaixo): guard de sincronia min-pair no deploy; corrigir `--theme` no `bin/deploy-static`; restaurar `guard:visual-baselines`; corrigir 3 sintaxes inválidas do super-global; estabilizar clip do Menu | deploy falha com min stale (teste negativo); deploy passa com tudo fresco | revert do patch; deploy roda sem guard |
| **P2 — Consolidação XML** | Merge dos 3 datados do `default_head_blocks.xml` em `awa-overrides.css` (atualizar refs no plugin — já mapeadas: `OptimizeHeadStylesPlugin.php:103,138,1329` + `b2b_auth_shell.xml:13`); remover `<style>` de `MaintenanceMode` | suite verde; diff de requests no head | git revert + deploy |
| **P3 — Tokens únicos** | `:root` único via `_tokens.less`; remover `:root` duplicados dos 5 bundles; hex → `var(--awa-*)` nos bundles sobreviventes (script assistido + revisão) | grep hex=0 fora de tokens; suite verde | revert + deploy |
| **P4 — Cascade layers + morte do paint gate** | Reagrupar bundles nas 6 camadas-alvo com `@layer`; absorver loaders PHTML nos handles XML; aposentar paint gate/fila | LCP/CLS ≤ baseline Lighthouse; FOUC probe = 0 folhas print retidas | revert + deploy (gate volta) |
| **P5 — Plugins e limpeza** | Aposentar `OptimizeHeadStylesPlugin`/`CascadeLock`/`GateParity` por absorção; quarentena dos 200 LESS órfãos; stylelint CI (`declaration-no-important` + no-hex com allowlist) | suite + smoke completo; CI verde | reativar módulo Theme via config |

Regra de ouro mantida: **uma consolidação por deploy**, suite visual-core verde (`desktop-1280` + `mobile-375`) antes da próxima.

---

## 6. Patch #1 — mínimo seguro (para o Codex 5.3 executar)

**Objetivo:** tornar o build reproduzível e impedir regressão de `.min.css` stale — **zero impacto visual, zero toque em arquivos congelados**. Escopo: 1 script novo + 2 edições pontuais em `bin/deploy-static`.

### 6.1 Novo arquivo `scripts/check-awa-min-pairs.sh`

```bash
#!/usr/bin/env bash
# check-awa-min-pairs.sh — falha se algum .min.css servido estiver stale vs. fonte.
# Uso: bash scripts/check-awa-min-pairs.sh
# Exit 0 = todos frescos (ou ausentes por exceção documentada); 1 = stale.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME_CSS="${ROOT}/app/design/frontend/AWA_Custom/ayo_home5_child/web/css"

# Stems servidos como .min.css em produção (loaders PHTML/XML/PHP).
# Fonte da lista: referências em app/design/frontend/AWA_Custom/ayo_home5_child
# (Magento_Theme/templates/html/*.phtml, */layout/*.xml) + OptimizeHeadStylesPlugin.
SERVED_STEMS=(
  awa-super-global-20260611m
  awa-defer-global-bundle
  awa-third-party-bundle
  awa-layout-bundle-20260611m
  awa-commerce-impeccable-refine
  awa-carousel-bundle
  awa-head-tail-bundle
  awa-m2-visual-ssot
  awa-head-preload-critical-home
  awa-home-polish-critical
  awa-home-launches-toggle-fix
  awa-home-deferred-stack
  awa-home-critical-stack-2026-06-11
  awa-plp-critical-fixes
  awa-impeccable-layout-2026-06-16
  awa-visual-bugfix
  awa-header-visual-audit-fixes-20260630
  awa-home-b2b-ops-density-20260805
  awa-checkout-layout-lock
  awa-cookie-fab-collision-fix-2026-07-08
  awa-design-system
)

# Exceções congeladas (trabalho concorrente não commitado) — revisar a cada fase.
FROZEN_STEMS=(
  awa-align-grid-terminal-2026-06-11
)

fail=0
for stem in "${SERVED_STEMS[@]}"; do
  src="${THEME_CSS}/${stem}.css"
  min="${THEME_CSS}/${stem}.min.css"
  [[ -f "$src" ]] || { echo "WARN: fonte ausente, pulando: ${stem}"; continue; }
  if [[ ! -f "$min" ]]; then
    echo "STALE(missing): ${stem}.min.css"
    fail=1
    continue
  fi
  if [[ "$src" -nt "$min" ]]; then
    echo "STALE(mtime): ${stem} — fonte mais nova que o min"
    fail=1
    continue
  fi
  if ! bash "${ROOT}/scripts/build-awa-css-min-pair.sh" --check "$stem" >/dev/null 2>&1; then
    echo "STALE(content): ${stem} — min diverge do build determinístico"
    fail=1
  fi
done

for stem in "${FROZEN_STEMS[@]}"; do
  echo "FROZEN(skip): ${stem} — congelado, verificação adiada"
done

if [[ "$fail" -eq 1 ]]; then
  echo "ERRO: mins stale. Regenere com: scripts/build-awa-css-min-pair.sh --write <stem>" >&2
  exit 1
fi
echo "OK: todos os pares min sincronizados (exceções congeladas listadas acima)."
exit 0
```

### 6.2 Edição em `bin/deploy-static` — pre-flight guard

Localizar o início do script (antes de qualquer `rm -rf`) e inserir:

```bash
# Pre-flight: bloqueia deploy com .min.css stale (Fase P1 — build reproduzível)
bash "$(dirname "$0")/../scripts/check-awa-min-pairs.sh" || exit 1
```

### 6.3 Edição em `bin/deploy-static` — escopo de tema (conforme AGENTS.md)

Trocar:

```bash
sudo -u www-data php bin/magento setup:static-content:deploy pt_BR en_US -f
```

por:

```bash
sudo -u www-data php bin/magento setup:static-content:deploy pt_BR en_US -f --theme AWA_Custom/ayo_home5_child
```

> Se o deploy completo de outros temas for intencional para adminhtml, manter uma segunda linha
> para o tema admin explícito em vez do deploy global indiscriminado.

### 6.4 Critérios de aceite do Patch #1

1. `bash scripts/check-awa-min-pairs.sh` → exit 0 hoje (mins regenerados em 2026-08-10) e lista `align-grid` como FROZEN.
2. Teste negativo: `touch` num `.css` da lista → guard falha com nome do stem → `--write` → guard passa.
3. `bin/deploy-static` aborta antes do `rm -rf` quando o guard falha (nada é apagado).
4. Deploy completo termina com suite `test:visual-core` verde e `tail var/log/exception.log` limpo.

### 6.5 Rollback do Patch #1

`git revert` do commit do patch (script novo deletado, `bin/deploy-static` restaurado) — sem efeito colateral: nada em produção depende do guard; ele só bloqueia deploys futuros.

---

## 7. Pendências imediatas fora deste documento

- Validação do deploy P0 em andamento (8 mins regenerados): suite visual-core + curl + logs ao término. ✅ (2026-08-10)
- `tests/e2e/scripts/visual-baseline-guard.mjs` ausente (script npm quebrado) — restaurar na P1. ✅ (2026-08-10)
- Baseline de checkout não cobre visitante anônimo (skip esperado) — cobrir com sessão autenticada na P2 (depende de credenciais TEST_USER/TEST_PASS no ambiente).
- P2 parcial (2026-08-10): merge dos 3 datados já estava concluído em `awa-visual-fixes-2026-06-29-final` (c380d1fa3); `<style>` do 503 extraído para `pub/errors/default/css/maintenance.css`; AGENTS.md sincronizado. Restam da P2: refs mortas no `OptimizeHeadStylesPlugin.php` (congelado — agente 9A) e checkout autenticado.
