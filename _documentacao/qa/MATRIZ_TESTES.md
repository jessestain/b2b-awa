# Matriz de testes — catálogo estável

IDs **não mudam**. Atualize só a coluna Estado e a linha de evidência na [execução do dia](execucoes/). A coluna “Estado 2026-09-14” é o último consolidado desta auditoria; execuções novas prevalecem se divergirem.

Legenda de estado: `não testado` · `verificado` · `falhou` · `corrigido e retestado` · `bloqueado` · `fora de escopo`.

SKU âncora da F1/F2: **2220** — `https://awamotos.com/retrovisores/ret-biz-100-cr-redondo-universal-2220.html`  
Pedido âncora Sectra: **000000115** (processing / `ready_for_import`).

---

## F0 — Servidor (`V-`)

| ID | Caso | Pré-condição | Passos | Esperado | Estado 2026-09-14 | Evidência |
|---|---|---|---|---|---|---|
| V-GIT-01 | Worktree Git | SSH no DocumentRoot | `git status --short \| wc -l`; branch; HEAD | Branch conhecida; sujo documentado; sem reset | `falhou` (risco operacional) | 876 arquivos fora do commit; `rescue/production-20260712` @ `0a7e43137` |
| V-MODE-01 | Modo Magento + URL | CLI | `deploy:mode:show`; `config:show web/secure/base_url` | production; `https://awamotos.com/` | `verificado` | Magento 2.4.8-p5 production |
| V-CRON-01 | Cron Magento 24 h | SQL `cron_schedule` | Contar error/missed; último `consumers_runner` | Sem error/missed; jobs recentes success | `verificado` | 14 set, error=0 missed=0; cron sequencial ativo |
| V-IDX-01 | Indexadores | CLI | `indexer:status` | Todos Ready, backlog 0 | `verificado` | 15 indexadores Ready |
| V-QUE-01 | Filas MySQL | SQL `queue_message` | Contar por `topic_name` e status | Sem crescimento contínuo de new/error | `não testado` (amostra única) | 66 msgs: product_alert 44, WhatsApp 20; HIPÓTESE de resíduos |
| V-CACHE-01 | Cache Magento + Varnish | CLI + `curl -sI` | `cache:status`; Age/X-Varnish na home | Caches on; Varnish responde | `verificado` | Todos os caches=1; home `age` Varnish |
| V-REDIS-01 | Sessão/cache KV | `ss -lntp :6379` | Identificar processo | Porta 6379 ativa | `verificado` | Valkey pid 1543; unit `redis-server` masked |
| V-LOG-01 | exception/system do dia | `rg` nos logs | CRITICAL/ERROR do dia | Sem CRITICAL de funil; guest GraphQL esperado | `falhou` | CRITICAL Helper/ArgumentInterface; GraphQL guest (esperado) |
| V-RES-01 | Load e RAM | `uptime`; `free -h` | Registrar | Sem saturação | `verificado` | load ~0,4–0,5; ~21 GiB livres |
| V-BKP-01 | Backup MySQL | `ls` dest do cron | Dump do dia; retenção | Dump gzip do dia presente | `verificado` | `/home/deploy/backups/magento-db/` 07–14 set |
| V-SVC-01 | Nginx PHP Varnish OS MySQL | systemd | `is-active` | nginx, php8.4-fpm, varnish, opensearch, mysql ativos | `verificado` | Percona + OpenSearch 2.19 |
| V-HDR-01 | Header de debug público | `curl -sI` home/busca/login | Não expor debug de plugin | Sem `x-awa-debug-*` | `falhou` | `x-awa-debug-w18: plugin-ran` |
| V-ADM-01 | Caminho `/admin` | GET público | `/admin` não deve ser o admin real | 404 no `/admin` | `verificado` | HTTP 404; frontName ofuscado (não documentar aqui) |

---

## F1 — Loja guest (`L-`)

Config esperada (CONFIRMADA em `core_config_data`): `hide_price_guests=1`, `hide_add_to_cart_guests=1`, `guest_checkout=0`, `b2b_mode=strict`.

| ID | Caso | Perfil / viewport | Passos | Esperado | Estado 2026-09-14 | Evidência |
|---|---|---|---|---|---|---|
| L-HOME-01 | Home carrega | guest / 1366 | Abrir `/` | 200; hero; categorias; mais vendidos; sem preço numérico | `verificado` | 14 set browser; HTTP 200 |
| L-HOME-02 | Home mobile | guest / 390 | Mesmo, 390 px | Header compacto; nav inferior; sem overflow horizontal | `não testado` | — |
| L-COOKIE-01 | Aviso de cookies | guest | Primeira visita | Texto LGPD visível; sem bloquear busca | `verificado` | Texto no snapshot; não bloqueou busca |
| L-SEARCH-01 | Busca por peça | guest | Buscar `retrovisor biz` | PLP com resultados; CTA cadastro no preço | `verificado` | PLP “Resultados da busca por 'retrovisor biz'” |
| L-SEARCH-02 | Termos relacionados / misspell | guest | Olhar chips relacionados | Não explodir relevância (centenas irrelevantes) | `não testado` | Chips visíveis; qualidade não medida |
| L-MENU-01 | Departamentos | guest | Abrir Departamentos | Categorias; links funcionam | `não testado` | Mega-menu no a11y tree; clique não feito |
| L-FILTER-01 | Filtros PLP | guest | Marca / cor na busca | Lista reduz; URL/filtro coerente | `não testado` | Facetas renderizadas |
| L-CAT-01 | Categoria | guest | Abrir Retrovisores | PLP categoria; preços ocultos | `não testado` | — |
| L-PDP-01 | Guest sem preço | guest | Abrir SKU 2220 | Sem R$; “Ver preço atacado”; sem add to cart | `verificado` | PDP 2220 |
| L-PDP-02 | Estoque na PDP guest | guest | Mesmo | Status “Em estoque” visível; qty exata não obrigatória | `verificado` | Badge Em Estoque + SKU 2220 |
| L-PDP-03 | CTA compra guest | guest | Clicar “Entrar para Comprar” | Modal portal B2B (cadastro / login) | `verificado` | Modal “Portal de Compra Empresarial” |
| L-PDP-04 | FAQ pagamento | guest | Abrir FAQ pagamento | Texto “a combinar” | `verificado` | Snapshot PDP |
| L-CART-01 | Carrinho guest vazio | guest | `/checkout/cart/` | Vazio; não permite checkout de compra | `verificado` | Título “carrinho está vazio” |
| L-CART-02 | Carrinho guest mobile | guest / 390 | Mesmo | Layout móvel utilizável; nav inferior | `verificado` | Chrome móvel no cart (amostra) |
| L-CHECK-01 | Checkout guest recusado | guest | GET `/checkout/` + GraphQL | Redirect e/ou recusa; sem pedido | `verificado` | 302 → `expresscheckout.html`; log GraphQL “Guest checkout is not allowed” |
| L-ASSIST-01 | Assistente AWA | guest | Abrir widget; pergunta inócua | Abre; não dispara pedido/e-mail | `não testado` | Botão visível; não aberto de propósito |
| L-FOOTER-01 | Footer pagamento | guest | Rodapé | “Pagamento a combinar” | `verificado` | Home e PDP |
| L-PRICE-01 | Vazamento de preço no HTML/JSON-LD | guest | Ver source da PDP 2220 | Sem preço no JSON-LD / HTML cru | `não testado` | Hipótese do mapa 2026-07-16; **retestar** |
| L-HTTP-01 | Home sem erro JS bloqueante | guest | Console + network | 200 nos CSS/JS críticos | `não testado` | Só headers HTTP |

---

## F2 — Login, preço, carrinho, checkout (`B-` + `C-`)

Passagem 2 (14 set): login B2B aprovado + **um** place order autorizado (20× SKU 3122).

| ID | Caso | Perfil | Passos | Esperado | Estado 2026-09-14 | Evidência |
|---|---|---|---|---|---|---|
| B-LOGIN-01 | Formulário login | guest | `/customer/account/login/` | 301 para `/b2b/account/login/`; e-mail ou CNPJ | `verificado` | Form 200; campos e-mail/CNPJ, senha, criar conta, ativar |
| B-LOGIN-02 | Login aprovado | aprovado teste | Entrar com conta de teste | Dashboard ou loja logada; sem CRITICAL | `verificado` | Header “Olá, Facilyty — B2B Atacado” |
| B-LOGIN-03 | Login pendente | pendente | Entrar | Entra na conta; preço/compra ainda bloqueados | `bloqueado` | — |
| B-LOGIN-04 | Senha inválida | guest | Senha errada | Mensagem clara; sem stack | `não testado` | — |
| B-LOGOUT-01 | Logout | aprovado | Sair | Volta guest; preço some | `bloqueado` | — |
| B-PRICE-01 | Preço após login | aprovado + ERP | PDP 3122 logado | Preço visível; add to cart visível | `verificado` | R$ 78,66 (tabela 020); CTA Adicionar |
| B-PRICE-02 | Pendente sem preço | pendente | PDP 2220 | Mensagem pendente; sem tabela | `bloqueado` | Config `show_price_pending=0` (FORTE EVIDÊNCIA) |
| B-PRICE-03 | Sem ERP | aprovado sem `erp_code` | PDP | Mensagem “tabela sendo definida” | `bloqueado` | Config `hide_price_no_erp=1` |
| C-ADD-01 | Add to cart | aprovado | Adicionar 3122 ×20 | Qty no carrinho; sem `cartId=0` novo | `verificado` | Quote ativa 20 un. / R$ 1573,20; botão “Adicionado” |
| C-MINI-01 | Minicart | aprovado | Abrir mini | Qty, nome, subtotal | `não testado` | Totais conferidos na página do carrinho, não no drawer |
| C-CART-02 | Carrinho totais | aprovado | `/checkout/cart/` | Subtotal = PDP × qty | `verificado` | 20 × 78,66 = 1.573,20 |
| C-ADDR-01 | Endereço checkout | aprovado | Ir ao checkout (sem place) | Endereço PJ; validação CEP | `verificado` | Araraquara 14808-604 no express checkout |
| C-SHIP-01 | Frete | aprovado | Selecionar transporte | `tablerate` / `carrierselect`; valor no total | `verificado` | `carrierselect` “Frete a combinar” R$ 0,00 (transportadora do cadastro) |
| C-PAY-01 | Pagamento A combinar | aprovado | Passo pagamento | Único método ativo “A combinar” | `verificado` | Único método no express checkout |
| C-TOT-01 | Totais | aprovado | Conferir resumo | Produtos + frete = grand total | `verificado` | Total R$ 1.573,20 |
| C-HOLD-01 | Hold por valor | aprovado | Quote ≥ limiar (não place) | Aviso de aprovação se configurado | `fora de escopo` até cenário | Pedido ~1,5k abaixo de 5k/15k/50k |
| C-PLACE-01 | Finalizar pedido | autorizado | Place order | Pedido gravado; tela de confirmação | `corrigido e retestado` | `000000116`; success inicial falhou (P-010); reteste OK no carrinho vazio |
| C-CHECK-02 | Checkout Rokanthemes | aprovado | URL efetiva | `expresscheckout.html` utilizável logado | `verificado` | Place order nesta URL |

---

## F3 — Conta e painel B2B (`B-`)

Rotas reais em `GrupoAwamotos_B2B` (`frontName` `b2b`). Features **desligadas** por config ficam `fora de escopo` até reativação.

| ID | Caso | Rota / tela | Esperado | Estado 2026-09-14 | Nota |
|---|---|---|---|---|---|
| B-REG-01 | Cadastro PJ 4 etapas | `/b2b/register` | CNPJ, endereço, contato, senha; ReceitaWS | `não testado` | Não criar cadastro real nesta fase |
| B-REG-02 | Página sucesso cadastro | `/b2b/register/success/` | “Sua jornada B2B começa aqui”; sem CRITICAL | `falhou` | P-001; página quase vazia; Helper ≠ ArgumentInterface |
| B-REG-03 | Lookup CNPJ | Ajax cadastro | Preenche razão social; CNPJ inativo recusa | `não testado` | Config `cnpj_lookup/enabled=1` |
| B-CLAIM-01 | Ativar conta existente | `/b2b/account/claim` | Fluxo sem duplicar cliente | `não testado` | Link no login |
| B-DASH-01 | Dashboard conta | `/b2b/account/dashboard` | Painel; sem 500 | `bloqueado` | Login |
| B-ORD-01 | Histórico pedidos | `/b2b/account/orders` | Pedidos do cliente | `verificado` | Detalhe `#000000116`: 20×3122, R$ 1.573,20, A combinar, Em Andamento |
| B-CO-01 | Empresa | `/b2b/company` | Dados company; papel admin | `bloqueado` | Adoção baixa (mapa 2026-07) |
| B-PERM-01 | Usuário comprador limitado | company user | Não vê crédito/aprovação se ACL negar | `não testado` | — |
| B-FIN-01 | Financeiro / boletos | `/b2b/finance` | Lista ou vazio elegante; sem 500 | `não testado` | — |
| B-CRED-01 | Crédito B2B no storefront | `/b2b/credit` | Feature off: não oferecer pagamento crédito | `fora de escopo` | `credit/enabled=0` |
| B-LIST-01 | Listas de compra | `/b2b/shoppinglist` | CRUD básico | `não testado` | — |
| B-QUOTE-01 | Cotação storefront | `/b2b/quote` | Feature off: não induzir orçamento | `fora de escopo` | `quote_request/enabled=0` |
| B-QUICK-01 | Pedido rápido | `/b2b/quickorder` | Feature off | `fora de escopo` | `quick_order_enabled=0` |
| B-REO-01 | Reordenar | `/b2b/reorder` | Recoloca itens com preço atual | `não testado` | — |
| B-ERP-01 | Pedidos ERP espelho | `/b2b/erporders` | Lista ou vazio; sem 500 | `não testado` | — |

---

## F4 — Admin Magento (`A-`)

Somente leitura. Não gravar produto, cliente ou pedido.

| ID | Caso | Onde | Esperado | Estado 2026-09-14 | Evidência |
|---|---|---|---|---|---|
| A-LOGIN-01 | Tela login admin | frontName ofuscado | Form usuário/senha Magento | `verificado` | Aba admin 14 set; `/admin` = 404 |
| A-LOGIN-02 | Acesso autenticado | login operacional | Dashboard sem 500 | `verificado` | User `qa.auditor.20260914`; painel comercial |
| A-CAT-01 | Produto 2220 | Catálogo → produto | SKU, status, preço admin | `bloqueado` | SKU desta passagem: **3122** (habilitado stores 0 e 1) |
| A-STK-01 | Estoque 2220 | Inventory | Saldo coerente com PDP logada | `não testado` | 3122: `manage_stock=0`; qty 110 inalterada após o pedido (CONFIRMADA) |
| A-CUS-01 | Grupos B2B | Clientes | Atacado / Pendente / Revendedor / VIP | `verificado` (SQL) | Grupos 4=8782, 7=45, 6=23, 5=0; “B2B Aprovado”=0 |
| A-CUS-02 | Cliente da F2 | Ficha | Grupo, CNPJ mascarado, `erp_code` | `verificado` | Customer `#8549`; grupo Atacado; `erp_code=18584`; reativado `is_active=1` |
| A-CUS-03 | Fila pendentes | B2B pending | Lista; badge | `bloqueado` | — |
| A-ORD-01 | Pedido 000000115 | Vendas | processing; pagamento acombinar | `verificado` (SQL) | processing; `ready_for_import`; R$ 3822,50 |
| A-ORD-02 | Pedido 000000114 | Vendas | pending / new | `verificado` (SQL) | pending; mesmo customer_id da 115 |
| A-ORD-03 | Pedido 000000116 | Vendas | processing; 20×3122; acombinar | `verificado` | Admin + SQL; `ready_for_import`; ERP cliente 18584; R$ 1573,20 |
| A-PAY-01 | Métodos ativos | Config pagamento | Só A combinar | `verificado` (config) | `payment/acombinar/active=1` |
| A-SHIP-01 | Fretes ativos | Config shipping | tablerate + carrierselect | `verificado` (config) | flatrate/correios off |
| A-CFG-01 | B2B geral | Stores → config B2B | enabled=1; strict; guest hide price | `verificado` (config) | Ver execução |
| A-SEC-01 | Fila Sectra admin | Platform → Sectra queue | Pedidos ready/failed visíveis | `bloqueado` | Controller existe |
| A-COM-01 | Painel comercial | Commercial dashboard | Abre; ACL | `verificado` | Dashboard “Painel da Equipe” / visão unificada |
| A-ATT-01 | Atendentes | Attendants | Lista | `bloqueado` | auto_assign=1 |
| A-LEG-01 | Menu legado vs unified | Menu admin | Unified on; legado visível conforme config | `não testado` | `unified_menu_enabled=1`, `legacy_menu_visible=1` |

---

## F5 — Sectra (`S-`)

Sem “Importar Pedidos” / “Exportar Clientes” em massa. Conferência Magento + log + (se autorizado) tela Sectra.

| ID | Caso | Esperado | Estado 2026-09-14 | Evidência |
|---|---|---|---|---|
| S-CLI-01 | Cliente Magento ↔ chave ERP | Aprovado tem `erp_code` ou status claro | `não testado` | Contagem SQL (FORTE EVIDÊNCIA de gap): 8925 com `erp_code` / 8993; 1253 `validated_in_erp`; 7355 `pending_erp_validation`. Falta conferência ficha a ficha. |
| S-PRO-01 | Prospects pendentes | Fila diminui ou tem dono | `falhou` (operacional) | Cron `still_pending: 10` repetido; `validated: 0` |
| S-PRD-01 | SKU 2220 no ERP | SKU existe nos dois lados | `não testado` | — |
| S-PRC-01 | Preço cliente F2 = Magento | Tabela FATORPRECO espelhada | `verificado` (Magento) | 20 × R$ 78,66 = 1.573,20 = pedido anterior 000000113; conferência desktop Sectra não feita |
| S-ORD-01 | 000000115 importável | `ready_for_import` e visível no PULL/`oc_order` | `falhou` (atraso) | ready desde 10 set; ainda não `imported` |
| S-ORD-02 | 000000052 falha | `import_failed` com motivo | `falhou` (legado) | failed desde jun/2026 |
| S-ORD-03 | Cancelado não fica ready | Cancelado ≠ `ready_for_import` | `falhou` | 000000101 canceled + ready_for_import |
| S-ORD-04 | 000000116 na fila PULL | `ready_for_import` após place | `verificado` | SQL + view pendentes; **observar** desktop; sem reimportação |
| S-ACK-01 | ACK pedido ERP | Magento e Sectra com mesmo id | `falhou` | WARNING `oc_order_id` 200102 ≠ `erp_order_id` 196444 (78× no log) |
| S-DUP-01 | CNPJ único | 1 CNPJ → 1 customer | `falhou` | 1 CNPJ com 2 `customer_id` (valores não registrados aqui) |
| S-PUSH-01 | Push automático desligado | `send_on_place=0` | `fora de escopo` | Integração é PULL desktop (mapa) |

---

## Cobertura Playwright (não substitui a matriz)

Specs em `tests/e2e/specs/` e `specs/auditoria-visual-completa.spec.ts` cobrem **aparência**. Um spec verde **não** marca `C-PAY-01` nem `S-ORD-01` como verificados.

Viewports do tema: 390 · 768 · 1366 · 1920 (`PLANO_CORRECAO_LAYOUT_FASES` / design system).

---

## IDs obrigatórios para fechar o ciclo de compra (F2)

Só fechar F2 quando estes estiverem `verificado` ou `corrigido e retestado`:

`B-LOGIN-02` · `B-PRICE-01` · `C-ADD-01` · `C-CART-02` · `C-ADDR-01` · `C-SHIP-01` · `C-PAY-01` · `C-TOT-01` · `C-PLACE-01`

F2 do ciclo 20×3122: **fechada** nesta passagem (P-010 retestado). Próximo: `C-MINI-01`, `B-REG-02`/P-001, fila Sectra `S-ORD-01`/`S-ORD-04` no desktop.

Ciclo 20×3122 fechado nesta passagem (P-010 retestado). Restam `C-MINI-01`, `B-REG-02`/P-001, desktop Sectra `S-ORD-01`/`S-ORD-04`.
