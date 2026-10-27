# AGENTS.md

> Instruções universais para todos os coding agents (Copilot, Claude Code, Cline, Cursor, etc.)

## Ambiente de Desenvolvimento
- **OS:** ubuntu (servidor remoto via ssh)
- **Plataforma:** magento 2.4.8-p5 (community edition)
- **PHP:** 8.4
- **Banco:** mysql (via magento orm)
- **Cache:** redis
- **Servidor:** nginx + php-fpm
- **Editor:** vs code via ssh remoto
- **Shell:** bash
- **Git:** Conventional commits (feat:, fix:, refactor:, etc.)

## Filosofia

### Código Real, Sempre
Este workspace NÃO aceita código placeholder. Toda implementação deve ser funcional e pronta para produção. Se uma integração com API é solicitada, implemente com chamadas reais, tratamento de erro, retry, e tipagem completa.

### Leia Antes de Escrever
Antes de criar ou editar qualquer arquivo:
1. Liste a estrutura do módulo (`ls`, `find`)
2. Leia `etc/module.xml`, `etc/di.xml`, `registration.php`
3. Verifique dependências e interfaces existentes
4. Só então comece a implementar

### Valide Após Cada Mudança
Após qualquer edição de código:
1. verifique sintaxe php (`php -l arquivo.php`)
2. verifique logs: `tail -20 var/log/system.log` e `tail -20 var/log/exception.log`
3. limpe cache se necessário: `php bin/magento cache:clean`
4. Corrija qualquer erro antes de prosseguir

## Proibições Absolutas
- ❌ ObjectManager direto (use DI via construtor)
- ❌ Código mock, stub, ou placeholder
- ❌ `var_dump`, `print_r`, `echo` em produção (use Logger)
- ❌ Secrets hardcoded
- ❌ `// TODO: implement` sem implementação real
- ❌ Ignorar erros silenciosamente (`catch {}`)
- ❌ Instalar dependências composer sem justificativa
- ❌ Alterar `app/etc/env.php` sem comunicar
- ❌ Criar READMEs ou documentação não solicitada
- ❌ Refatorar código que não foi pedido para refatorar
- ❌ Alterar arquivos do core Magento ou vendor

## Padrões de Código

### PHP / Magento 2
```
- declare(strict_types=1) em todo arquivo
- PSR-12 coding style
- Type hints em parâmetros e retornos
- DocBlocks com @param, @return, @throws
- DI via construtor (nunca ObjectManager)
- Service Contracts (interfaces em Api/)
- Repository Pattern para acesso a dados
```

### Frontend (Magento)
```
- Knockout.js para componentes dinâmicos
- RequireJS para módulos JS
- LESS para estilos (não SCSS)
- jQuery via RequireJS (não CDN)
- Layout XML para estrutura de página
- PHTML templates com escape de output
```

### Banco de Dados
```
- db_schema.xml (Declarative Schema)
- Repository Pattern + Collections
- NUNCA queries SQL diretas
- Paginação obrigatória em listagens
- Índices em colunas de WHERE/JOIN
```

## Estrutura Esperada (Módulo Customizado)
```
app/code/GrupoAwamotos/NomeModulo/
├── registration.php
├── etc/
│   ├── module.xml
│   ├── di.xml
│   ├── db_schema.xml
│   ├── events.xml
│   ├── routes.xml
│   └── adminhtml/
│       ├── routes.xml
│       └── system.xml
├── Api/
│   └── Data/
├── Model/
├── Controller/
├── Block/
├── view/
│   ├── frontend/
│   └── adminhtml/
├── Observer/
├── Plugin/
├── Cron/
└── Helper/
```

## Contexto de Negócio
- **AWA Motos** — distribuidora de peças para motos em Araraquara, SP
- **Foco:** e-commerce magento 2, b2b, integração erp, automações
- **Tema:** rokanthemes ayo (customizado, 27 extensões)
- **ERP:** integração com sql server (módulo erpintegration)
- **B2B:** sistema de clientes empresariais com aprovação por cnpj
- **Fitment:** compatibilidade de peças por modelo de moto
- **SEO:** schema.org json-ld + open graph (módulo schemaorg)
- **Inteligência:** salesintelligence com previsão de demanda

## Frontend — Proteção de Layout

> Regras obrigatórias para qualquer edição visual (CSS, LESS, PHTML, layout XML).

### Protocolo antes de editar

1. **Identifique a zona** — verifique se o arquivo pertence ao tema filho (`AWA_Custom/ayo_home5_child`) ou a um módulo customizado (`app/code/GrupoAwamotos/`). Nunca edite `app/code/Rokanthemes/*`.
2. **Identifique a camada correta** — a cascata real vigente (2026-08, ver `CSS_INVENTORY.md` no tema) é carregada por `Magento_Theme/templates/html/awa-head-preload.phtml` (lógica condicional por rota), `awa-align-grid-terminal-loader.phtml` (PDP/checkout/CMS/B2B) e plugins PHP (`OptimizeHeadStylesPlugin.php`, `HomeCssGateParity.php`). Os antigos `awa-bundle-core/category/phases/refinements` **não existem mais**; `awa-bundle-site.css` é um shim vazio proposital — não referenciá-lo.
3. **Verifique `var/view_preprocessed`** — em produção, templates PHTML são servidos de `var/view_preprocessed/pub/static/app/design/frontend/AWA_Custom/ayo_home5_child/`. Após criar override, copie manualmente o arquivo para lá antes de limpar cache.
4. **Nunca use hex hardcoded** — use sempre `var(--awa-red)`, `var(--awa-primary)` etc. Fonte canônica dos tokens: `web/css/source/_awa-variables.less` (vars LESS `@awa-*`) + `web/css/source/_tokens.less` (custom properties `--awa-*`).
5. **Cuidado com rename de CSS** — `OptimizeHeadStylesPlugin.php`, `HeaderImpeccableCascadeLockCss.php` e `HomeCssGateParity.php` fazem match por **nome de arquivo via regex** (~15 pontos hardcoded, ex.: `awa-align-grid-terminal-2026-06-11`). Renomear/mover arquivo sem atualizar esses pontos quebra injeção/dedup silenciosamente (o `__emitDeferredCss` do head-preload omite links de arquivos ausentes sem erro). Sempre `grep` pelo nome antes de renomear.

### Cascata CSS (ordem de prioridade, última ganha) — vigente 2026-08

1. `styles-m.css` / `styles-l.css` (LESS compilado Magento)
2. `themes.css` (merge herdado do tema pai)
3. `awa-super-global-20260611m.min.css` — base global
4. `awa-layout-bundle-20260611m.min.css` — layout estrutural
5. `awa-commerce-impeccable-refine.min.css` — refinamentos (substitui o antigo refinements)
6. `awa-align-grid-terminal-2026-06-11.min.css` — grid/locks terminais (PDP, checkout, CMS, B2B; via loader dedicado)
7. `awa-m2-visual-ssot.min.css` — SSOT final (rating/shelf/carousel/cards/badge)
8. `css/awa-design-system.css` — último `<css>` do `default_head_blocks.xml`

Via `default_head_blocks.xml` também carrega `css/awa-visual-fixes-2026-06-29-final.css` (folha consolidada — absorveu `awa-visual-noise-2026-07-15-r2` e `awa-cookie-fab-collision-fix-2026-07-08`, que não são mais servidos; refs residuais no `OptimizeHeadStylesPlugin.php` são matchers mortos a limpar na P5). Home/PLP têm folhas condicionais extras via `awa-head-preload.phtml` (paint gate + fila `__awaCssQ`).

Para novos estilos que precisam ter prioridade: adicionar na camada de menor nível que abrange o contexto, **com seletor específico**, evitando `!important`.

### Deploy após edição

```bash
# CSS/LESS alterado
sudo -u www-data php bin/magento setup:static-content:deploy pt_BR -f --theme AWA_Custom/ayo_home5_child
sudo -u www-data php bin/magento cache:flush

# Apenas PHTML alterado
sudo -u www-data php bin/magento cache:clean block_html full_page

# Se var/view_preprocessed estiver desatualizado
sudo -u www-data cp app/design/frontend/AWA_Custom/ayo_home5_child/[Vendor_Module]/templates/[file].phtml \
  var/view_preprocessed/pub/static/app/design/frontend/AWA_Custom/ayo_home5_child/[Vendor_Module]/templates/[file].phtml
sudo -u www-data php bin/magento cache:clean block_html full_page
```

### Checklist pós-edição de layout

- [ ] Página modificada renderiza sem erros (sem 500, sem 0 bytes)
- [ ] `tail -5 var/log/exception.log` sem novas entradas
- [ ] Áreas adjacentes não regrediram (header, footer, mobile)
- [ ] Inspecionar no browser sem service worker (`Disable cache` + unregister SW se necessário)

### Proibições de layout

- ❌ Editar `app/code/Rokanthemes/*` — usar override no tema filho
- ❌ `!important` sem comentário explicando motivo
- ❌ CSS inline no PHP/PHTML (usar classes)
- ❌ Hex hardcoded — usar tokens CSS (`var(--awa-*)`)
- ❌ `setup:static-content:deploy` sem `--theme AWA_Custom/ayo_home5_child` para mudanças no tema filho (mais lento e pode causar diferença de comportamento)

## MCP — Perfis de performance (VPS)

**Padrão: perfil LEVE** — zero Chrome headless, zero browser MCP. Economia ~1–1,5 GB RAM + CPU.

```bash
scripts/mcp-performance.sh status      # diagnóstico RAM/Chrome/MCP
scripts/mcp-performance.sh heavy-off   # perfil leve (produção)
scripts/mcp-performance.sh heavy-on    # Playwright MCP só para QA visual
scripts/mcp-performance.sh cleanup     # mata processos MCP/Chrome órfãos
```

Após trocar perfil: **Settings → MCP → Reload** no Cursor.

| Perfil | MCP ativos | RAM extra |
|--------|-----------|-----------|
| Leve (padrão) | filesystem | ~128 MB |
| Pesado (QA) | filesystem + playwright | ~300–500 MB |

> Não manter `chrome-devtools-mcp` + `playwright-mcp` + `chrome-cdp-9222.service` ao mesmo tempo — são 2–3 instâncias Chrome duplicadas. O perfil pesado usa **só Playwright** (1 Chrome). O browser nativo do Cursor (`cursor-ide-browser`) cobre investigação visual sem MCP externo.

## Ferramentas de Debug Visual

### Browser MCP — Playwright (investigação em tempo real)
Ativar só quando necessário: `scripts/mcp-performance.sh heavy-on`. Sem `--caps vision` (economia de CPU/RAM).

Fluxo para investigar layout quebrado:
1. `browser_navigate` → URL da página com problema
2. `browser_take_screenshot` → estado atual desktop
3. `browser_resize` `{"width": 375, "height": 812}` → `browser_take_screenshot` mobile
4. `browser_snapshot` → inspecionar DOM (accessibility tree) sem executar JS
5. `browser_evaluate` → `getComputedStyle(document.querySelector('.seletor'))` para confirmar qual CSS está ativo
6. `browser_network_requests` → verificar recursos bloqueados/com erro
7. Busca em bundle CSS → via filesystem MCP ou `browser_evaluate` com fetch+text

### Playwright (testes visuais automatizados)
Specs em `tests/e2e/specs/` — cobrem home, header, footer, PDP, categoria, checkout, 404, B2B, acessibilidade.

```bash
cd tests/e2e

# Rodar spec visual
npx playwright test specs/visual-audit-home-header-footer.spec.ts

# Criar/atualizar baseline (só após confirmar visualmente!)
npx playwright test --update-snapshots

# Relatório HTML
npx playwright show-report reports/html
```

> ⚠️ O diretório `tests/e2e/snapshots/` ainda não tem baseline gerado. Antes de usar `toHaveScreenshot`, rode `--update-snapshots` uma vez com o layout em estado correto.

## Procedimentos Operacionais Críticos

### Mudança de Domínio / URL Base
Após qualquer alteração de `web/secure/base_url` ou `web/unsecure/base_url`, execute **obrigatoriamente** nesta ordem:

```bash
sudo -u www-data php bin/magento cache:flush
redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' -n 1 FLUSHDB  # Redis DB1: cache Magento
redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' -n 2 FLUSHDB  # Redis DB2: FPC (Full Page Cache)
sudo -u www-data php bin/magento indexer:reindex catalog_url
```

**Por quê:** O FPC armazena HTML completo incluindo URLs absolutas. Se o domínio mudou mas o FPC não foi limpo, o browser receberá HTML com URLs do domínio antigo. O CSP usa `'self'` = domínio atual, então todas as referências ao domínio antigo serão bloqueadas — incluindo `require.js`, que derruba toda a stack JavaScript do Magento.

### Redis AWA — Mapa de bancos
| DB | Conteúdo | Comando flush |
|----|----------|--------------|
| 0 | Sessions | `redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' -n 0 FLUSHDB` |
| 1 | Cache Magento (config, block, layout) | `redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' -n 1 FLUSHDB` |
| 2 | FPC — Full Page Cache (HTML completo) | `redis-cli -h ::1 -a 'Aw4R3d1s2026Sec' -n 2 FLUSHDB` |

> `php bin/magento cache:flush` faz flush do DB1 via Magento. O DB2 (FPC) precisa ser limpo separadamente via redis-cli.
