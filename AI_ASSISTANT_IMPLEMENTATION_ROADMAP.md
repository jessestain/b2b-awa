# Assistente IA AWA — Roadmap de Implementação por Fases

Documento operacional para evoluir o `GrupoAwamotos_AiAssistant` de piloto
funcional para operação comparável a grandes plataformas de e-commerce.

Cada fase exige diagnóstico, diff, backup fora do DocumentRoot, autorização
explícita, aplicação mínima, testes e rollback. Este arquivo **não** autoriza
alterar código, banco, cache ou serviços.

Auditoria de origem: 2026-08-18. Host `awamotos.com`. Branch
`rescue/production-20260712` @ `dd06b6e0c`. Magento em `production`.

---

## 1. Estado atual (CONFIRMADO)

| Item | Valor |
|------|--------|
| Módulo | `GrupoAwamotos_AiAssistant` habilitado |
| Storefront / coach / copiloto Admin | todos `1` |
| Provider ativo | OpenRouter (`OpenRouterClient`) |
| Modelo | `nousresearch/hermes-3-llama-3.1-70b` |
| Timeout | 45 s (JS 60 s) |
| Rate limit efetivo | 30 msgs/h/IP (XML default era 20) |
| Uso auditado | 104 turnos (10–17/ago), ~97% `ok` |
| Git | módulo rastreado (`6c685e6b4` + P0 `d4ba93e52`); hotfix UX e Fase 2 ainda dirty |
| Maturidade | piloto real, não plataforma madura |

Já existe: canais storefront/B2B/Admin; tool calling (até 3 rounds); catálogo e
fitment com prefetch/grounding; cards básicos; carrinho; pedidos; cotação e
recompra B2B; status B2B; Help Center; tools Admin somente leitura; rate limit
por hash de IP; log de tokens/tools/status; coach guest/auth/B2B.

---

## 2. Princípios e gates globais

1. Autorização nunca depende só do prompt.
2. Toda tool sensível revalida identidade, canal e permissão internamente.
3. Preço, estoque, pedido, crédito e compatibilidade vêm só do Magento.
4. PII é minimizada antes do log e do envio ao LLM.
5. Prompt, modelo, tool, schema e release são versionados.
6. Falha da IA não interrompe navegação, carrinho ou checkout.
7. Escritas exigem confirmação determinística e idempotência no servidor.
8. Nenhuma fase avança com teste P0 falhando.
9. Não editar core, `vendor/`, Rokanthemes ou `pub/static` manualmente.
10. Produção permanece somente leitura até autorização específica da fase.

**NO-GO global:** módulo fora do Git; exposição de pedido; escrita decidida
pelo LLM; dados pessoais sem retenção; ausência de handoff humano; testes de
segurança falhando.

---

## Fase 0 — Governança, Git, baseline e staging

### Objetivo

Tornar o código atual rastreável, reproduzível e recuperável **antes** de
qualquer evolução funcional.

### Implementação

- Inventariar arquivos, SHA-256, tamanho, mtime, inode, dono e permissões.
- Comparar fonte, minificados, `pub/static`, gzip e Brotli.
- Preservar o worktree sujo (sem `reset` / `checkout` destrutivo).
- Backup byte a byte fora do DocumentRoot.
- Adicionar ao Git somente fontes aprovadas do `AiAssistant`.
- Excluir logs, caches, temporários, chaves e assets gerados.
- Criar release/tag e registrar hash do artefato implantado.
- Separar config de produção, staging e desenvolvimento.
- Flags independentes: storefront, B2B, Admin, coach e tools sensíveis.
- Staging com dados anonimizados, pedidos sintéticos e orçamento LLM próprio.
- Documentar kill-switch emergencial e rollback.

### Arquivos / áreas

- `app/code/GrupoAwamotos/AiAssistant/`
- `.gitignore`
- workflows de CI
- config Magento por ambiente
- scripts de backup/validação (quando aprovados)

### Aceite

- Módulo 100% rastreado e sem segredos.
- Produção reproduzível a partir de uma release.
- Staging funcional.
- Backup e rollback validados.
- CI analisa todos os arquivos do módulo.

### Rollback

Restaurar bytes preservados, permissões e dono. Invalidar só caches
estritamente necessários. Conferir hashes e HTTP.

### Gate

**NO-GO** para alterar funcionalidades enquanto o módulo estiver fora do Git.

### Progresso (2026-08-18)

Concluído nesta sessão (sem Git e sem staging):

- Inventário: 57 arquivos fonte; dono `deploy:www-data`; perms `664`; sem symlinks.
- Backup byte a byte: `/home/deploy/awa-production-backups/fase0-aiassistant-20260818T175900Z/` (57/57, mismatch 0).
- `pub/static` JS/CSS servidos batem com a fonte (`ai-assistant.js`, `guided-coach.min.js`, `ai-assistant.min.css`).
- `.min.css` / `.min.js` na fonte **não** estão minificados (mesmo SHA do original).
- Ausentes em `pub/static`: `guided-coach.js` (+ gzip/br), `guided-coach.min.js.gz/.br`, `ai-assistant.css`, gzip/br do CSS. Com `dev/js/minify_files=1` o coach `.min.js` carrega; gzip/br do coach/CSS faltam.
- `var/view_preprocessed` do `widget.phtml` / `copilot.phtml` é HTML minificado Magento, não cópia byte a byte da fonte.
- Worktree sujo **não** foi resetado.

Pendente Fase 0: `git add`/`commit` do módulo, tag/release, flags por ambiente, staging, kill-switch documentado em runbook, CI enxergando o path.

---

## Fase 1 — Segurança P0 e autorização determinística

### 1.1 Pedidos (IDOR)

Problema: visitante informa `increment_id`; com `customer_id` nulo a checagem
de propriedade não bloqueia itens, total, status e frete
(`OrderTrackingTool.php`).

- Exigir login para detalhes de pedido.
- Validar sempre `order.customer_id === session.customer_id`.
- Resposta uniforme para inexistente e não autorizado (anti-enumeração).
- Se rastreio guest for requisito: pedido + e-mail + CEP **ou** token
  assinado, sem revelar existência antes da validação.
- Mascarar número e dados retornados.
- Registrar tentativas bloqueadas sem PII desnecessária.

### 1.2 CSRF e endpoint

Problema: `validateForCsrf()` retorna `true` em `Controller/Chat/Message.php`.

- Remover o bypass; exigir `form_key` válido.
- Enviar `form_key` pelo JS Magento.
- Aceitar só POST JSON com tamanho máximo.
- Validar origem/CORS e método.
- Limite Nginx para `/aiassistant/`.
- HTTP 400/403/429/5xx coerentes.

### 1.3 Histórico do browser

- Aceitar só array de `{role, content}`.
- Roles permitidos: `user` e `assistant`.
- Limitar turnos, chars/turno e total **antes** do LLM.
- Remover campos extras; normalizar Unicode.
- Rejeitar payload excessivo/malformado.
- Nunca derivar autorização do histórico informado pelo cliente.

### 1.4 Escritas B2B

Cotação (`b2b_quote submit`) e recompra (`b2b_reorder reorder`) executam
escrita; a “confirmação explícita” existe só no prompt.

Fluxo obrigatório:

1. IA prepara proposta somente leitura.
2. Backend gera resumo canônico.
3. Servidor cria nonce de uso único (cliente, sessão, ação, payload).
4. UI mostra botão explícito de confirmação.
5. Endpoint separado valida nonce, expiração e conteúdo.
6. Serviço revalida cliente B2B e executa com idempotency key.
7. Nonce é consumido; operação é auditada.

Texto como “confirmo” **nunca** autoriza escrita. Retry/duplo clique não
duplica operação. Limitar SKU, quantidade e itens.

### 1.5 Defesa em profundidade e checkout

- Tools Admin exigem contexto Admin autenticado internamente.
- Tools B2B exigem cliente aprovado no momento da execução.
- Impedir enumeração de status B2B por CNPJ/telefone.
- Não retornar exceções técnicas ao usuário ou ao LLM.
- Remover widget, coach, som e CSS específico do checkout (hoje só o coach
  é barrado; o FAB permanece via `default.xml`).

### Testes obrigatórios

- Guest não obtém pedido.
- Cliente A não obtém pedido B.
- POST sem `form_key` falha.
- Histórico excessivo falha antes do provider.
- Nenhuma escrita B2B ocorre sem nonce válido.
- Nonce alterado, expirado ou reutilizado falha.
- Admin tools falham fora do Admin.
- Widget não existe no DOM do checkout.

### Aceite / gate

Zero bypass de autorização. CSRF ativo. Checkout sem assistente. Testes
automatizados cobrindo todos os cenários acima.

**NO-GO para escala até todos os itens passarem.**

### Progresso (2026-08-18)

Código P0 aplicado em produção (autorização “continue”):

- Pedidos: guest não consulta o banco; logado filtra `customer_id`.
- CSRF: `form_key` no header/query vs cookie; body ≤ 64 KB; histórico
  sanitizado (`user`/`assistant`, teto de turnos/chars).
- B2B write: `deferred_write` + nonce de sessão (10 min, uso único);
  UI Confirmar/Cancelar; token não vai ao LLM.
- Checkout: `checkout_index_index` e `onepagecheckout_index_index`
  removem `grupoawamotos.ai.chat.widget`.
- Backup pré-edição: `/home/deploy/awa-production-backups/fase1-aiassistant-20260818T180800Z/`

Pendente nesta fase: limite Nginx `/aiassistant/`; testes automatizados.

---

## Fase 2 — LGPD, consentimento e ciclo de vida dos dados

### Implementação

- Classificar: mensagem, resposta, `customer_id`, sessão, IP, telefone, CNPJ,
  pedido, SKU e logs do provider.
- UI: identificar “respostas geradas por IA”, finalidade, provedor externo
  e link de privacidade.
- Oferecer atendimento humano sem obrigar uso da IA.
- Integrar consentimento ao mecanismo de cookies vigente, quando aplicável.
- Mascarar CPF, CNPJ, telefone, e-mail, endereço e cartão antes do LLM/log.
- Bloquear senha, token, chave, CVV e pagamento completo.
- Evitar body bruto em logs.
- Definir retenção para guest, cliente, erros e auditoria B2B.
- Cron Magento paginado para anonimização/expurgo (índice `created_at`).
- Exportação e exclusão por titular (DSR).
- Revisar retenção, treinamento, região e subprocessadores do OpenRouter.
- Rotação/revogação de chaves; base legal documentada.

Nota: o logger hasheia IP. A partir de 2026-08-18 a persistência e o envio
ao LLM passam por `PiiRedactor` (e-mail, CPF, CNPJ, telefone, cartão e
segredos). `error_detail` no banco guarda só classe/código, não stack.

### Progresso (2026-08-18)

Código aplicado (autorização “continue melhorando”):

- `PiiRedactor` antes do LLM e do `ConversationLogger`.
- Payload com senha/cartão/token é bloqueado (`status=blocked`).
- Cron `grupoawamotos_aiassistant_purge_conversation_log` (03:20 UTC):
  guest 30d apaga; cliente 90d anonimiza; `error_detail` 14d.
- DSR exclusão: observer `customer_delete_commit_after`.
- Widget: “Assistente com IA”, aviso, Privacidade e “Falar com atendente”.
- Config Admin: grupo Privacidade e retenção.
- Testes unitários: `Test/Unit/Model/PiiRedactorTest.php`.

Pendente nesta fase: consentimento cookies, DSR exportação, revisão
contratual OpenRouter, documentação de base legal.

### Testes e aceite

- PII não chega ao provider nem ao log.
- Cron remove apenas dados vencidos.
- Exportação/exclusão não afeta outro cliente.
- Recusa de consentimento funciona.
- Política e fornecedor documentados.
- Métricas agregadas permanecem anônimas.

---

## Fase 3 — Testes, evals e CI/CD

Hoje existe só `Test/Unit/Model/Query/ProductQueryParserTest.php` (7 casos).
Zero E2E, zero evals, zero integração do módulo. CI não cobre código untracked.

### Unitários

Orchestrator; clientes OpenRouter/Groq/Hermes; parser de tool calls;
RateLimiter; ConversationLogger; validação de histórico; cada tool;
autorização por canal; PII; nonce; fallbacks e classificação de erros.

### Integração Magento

Controller com `form_key`; guest/cliente/B2B/Admin; ownership de pedido;
carrinho; cotação/recompra confirmadas; ACL; schema/config por escopo;
cron de retenção.

### E2E

Abrir/fechar/enviar; catálogo por termo, SKU e moto; cards; timeout, retry,
offline e rate limit; feedback e handoff; persistência; teclado, leitor de
tela e mobile; ausência no checkout; consentimento; B2B aprovado e não.

### Eval suite versionada

Golden set: saudações, SKU, fitment, ambiguidades, inexistente, preço,
estoque, pedido, políticas, B2B, prompt injection, jailbreak, PII, pedido
de terceiro, escrita sem confirmação, RAG malicioso.

Medir: tool/argumentos corretos; groundedness; SKU/preço/URL inventados;
compatibilidade sem fonte; vazamento de PII; recusa correta; consistência;
latência; tokens; custo; rubrica humana.

### Gates CI

`php -l`, PHPCS, XML, PHPUnit, integração, JS, E2E smoke, evals críticas,
Codacy, secrets scan, diff de schema. Bloquear merge em falha P0.

### Metas

- 100% dos fluxos sensíveis cobertos.
- Zero vazamento e zero escrita sem confirmação.
- Zero invenção de fatos comerciais críticos.
- ≥ 95% de seleção correta de tool no golden set.

---

## Fase 4 — Observabilidade, SLO, custos e alertas

### Implementação

- Correlation ID por turno: controller → orchestrator → tool → provider.
- Volume, sucesso, erro, latência total/provider/tool e rounds.
- Time to first token, timeout, retry, fallback e rate limit.
- Modelo, versão de prompt, tokens e custo estimado.
- Orçamento por canal, cliente e dia.
- Funil: abertura, conversa, busca, clique, carrinho, cadastro, cotação,
  recompra, handoff, conversão e receita assistida.
- Consumir `awa:guide:action` (hoje emitido e sem listener).
- Dashboards técnico, de IA, segurança, custo e conversão.
- Alertas: 5xx/429, timeout, erro de tool, token burn, custo, abuso, cron, fila.
- Corrigir logger dedicado vazio e padronizar wiring dos controllers.
- Registrar `rate_limited` no log (hoje o controller rejeita sem logar).

### SLO inicial

| Métrica | Meta |
|---------|------|
| Disponibilidade mensal | ≥ 99,9% |
| Erro técnico | < 1% |
| p95 resposta completa | < 8 s |
| Time to first token (streaming) | < 1,5 s |
| Incidente autorização/PII | 0 |
| Handoff em falha crítica | 100% |
| Custo | dentro do orçamento diário |

### Aceite

Dashboard e alertas testados. Correlation ID ponta a ponta. SLO/error budget
aprovados. Incidente simulado diagnosticável sem ler conteúdo pessoal.

---

## Fase 5 — Resiliência, performance e escala

### Implementação

- Compositor multi-provider: OpenRouter principal + fallback independente
  (Groq/Hermes com chave e orçamento próprios).
- Modelos compatíveis com o mesmo contrato de tools.
- Retry só para timeout, 429 e 5xx, com backoff e jitter.
- Orçamento total de tempo; poucos retries; nunca repetir escrita.
- Circuit breaker (cooldown / half-open).
- Bulkhead separado para storefront, B2B e Admin.
- Rate limit **atômico** por IP, sessão, cliente, canal, tokens e custo.
- Redis ou SQL atômico; limite Nginx; quota Admin.
- Sem fail-open irrestrito do limiter (`RateLimiter` hoje retorna `true` em
  erro de DB).
- SSE para streaming + cancelamento + fallback HTTP.
- Não expor raciocínio interno nem payload de tool.
- Filas Magento para analytics, agregação, PII, retenção, resumo e indexação.
- Cache de schemas e grounding público; **nunca** cachear pedido/carrinho/PII.
- Chave de cache inclui store, moeda, grupo e versão dos dados.
- JS/CSS só onde o widget existe; gerar `.gz`/`.br` pelo pipeline.
- Padronizar `guided-coach.js` vs `.min.js` e CSS “min” não minificado.
- Medir LCP, CLS, INP; widget fora do caminho crítico.

### Testes

Provider principal down; fallback ok; 429/5xx; circuito aberto; pico
concorrente; Redis/DB down; cancelamento de streaming; tool lenta; fila
acumulada; cache não mistura usuários/lojas/grupos.

### Aceite

Fallback testado. Nenhum retry duplica escrita. Limites atômicos. Picos não
esgotam PHP-FPM. Sem regressão de Core Web Vitals. Orçamento de custo aplicado.

---

## Fase 6 — UX, acessibilidade e atendimento humano

### Transparência

- Título “Assistente com IA”.
- Limitações: preço, estoque e compatibilidade confirmados nos cards.
- Link de privacidade.
- Nunca simular pessoa humana.
- Identificar handoff quando ocorrer.

### Dialog acessível (WCAG 2.2 AA)

Hoje: dois `role="dialog"` sem `aria-modal`; Escape só se o foco já estiver
no root; sem focus trap.

- `aria-modal` quando modal.
- Mover foco ao abrir; trap de Tab; devolver foco ao FAB ao fechar.
- Escape; impedir foco em painel oculto.
- Anunciar loading/erro/nova resposta sem duplicação.
- Zoom, contraste e teclado.

### Mobile

- Full-sheet / viewport adaptativo (`100dvh`, `safe-area-inset`).
- Input visível com teclado virtual.
- Sem colisão com WhatsApp, cookie, bottom nav e back-to-top.
- Validar 320, 360, 375, 390, 414 e tablet.

### Estados de UI

Conectando; pensando; consultando catálogo/pedido; parcial; offline;
timeout; rate limit; provider down; erro recuperável; erro que exige humano;
“Tentar novamente”; “Cancelar resposta”; preservar rascunho.

### Handoff humano

Hoje o WhatsApp/“chat ao vivo” existe só no texto do LLM e na mensagem de
rate limit. Zero CTA no `widget.phtml`.

- CTA persistente “Falar com atendente”.
- CTA automático após falhas repetidas ou baixa confiança.
- Integração WhatsAppCommerce / LiveChat / ticket aprovado.
- Consentimento para anexar transcrição.
- Resumo estruturado para o atendente.
- Horário de funcionamento e expectativa de resposta.
- Fallback direto quando a IA estiver desligada.

### Quick actions e cards

Mostrar só ações permitidas no contexto: buscar peça, compatibilidade,
carrinho, pedidos, como comprar, login, cadastro B2B, cotação, recompra,
atendente.

Produto: imagem com alt, nome, SKU, preço só se autorizado, disponibilidade,
fitment com confiança, link PDP, ATC só por fluxo determinístico.

Pedido: número mascarado, status, data, tracking seguro.

B2B: cotação, crédito informativo, recompra com confirmação.

### Feedback, coach, i18n

- “Foi útil?” + motivo categorizado, ligado a correlation ID.
- Coach guest: 1 ação principal + 1 secundária (hoje 4 CTAs).
- Som só após opt-in; não deixar botão de som como ruído permanente.
- Remover strings hardcoded do JS; dicionário Magento `i18n`.

### Aceite

WCAG 2.2 AA nos fluxos principais. Handoff sempre disponível. Mobile sem
colisões. Analytics do funil funcionando. Zero regressão no checkout.

---

## Fase 7 — Contexto, memória, RAG e personalização

### Contexto da página

Enviar só estrutura confiável: tipo de página, categoria, produto/SKU,
moto/fitment, filtros, carrinho, autenticado, grupo B2B, store/moeda/locale.
O browser **não** é fonte de verdade para autorização, preço ou pedido.

### Memória

Persistência server-side com consentimento; ID opaco; retomada entre páginas;
lista/exclusão pelo usuário; expiração; resumo de conversas longas.
Hoje o histórico vive só em memória JS (`_history`); refresh apaga tudo.

### Preferências

Motos do cliente, marca, canal de atendimento — com consentimento e
edição/exclusão.

### RAG semântico

Indexar conteúdo público aprovado; versionar documento e vigência; chunking;
embeddings; busca híbrida keyword+vetor; filtros por audiência/store/canal;
rerank; limiar de relevância; citações título+URL; invalidação na publicação.

Segurança: conteúdo recuperado é **não confiável**; separar instruções de
conteúdo; bloquear prompt injection armazenado; sanitizar HTML; nunca expor
tópicos seller/admin no storefront.

Hoje `KnowledgeBaseSearchTool` é keyword na Help Center (até 4 tópicos,
`strip_tags`, 1200 chars) — base útil, não RAG.

### Roteamento de modelos

Modelo econômico para FAQ; tool calling confiável para comércio; modelo
mais forte só para casos complexos. Nenhuma troca automática sem eval.

### Grounding

Fatos críticos só de tool. Compatibilidade sem ficha = não confirmada.
Abstention. Bloquear SKU/preço/estoque/URL ausentes do retorno.

### Aceite

Retomada com consentimento. RAG com citações. Nenhuma audiência interna vaza.
Qualidade superior ao baseline em eval controlada.

---

## Fase 8 — Escala, experimentação e operação contínua

### Painel operacional

Conversas agregadas; saúde do provider; tools; custos; feedback; handoffs;
tópicos sem resposta; qualidade por versão; funil comercial. ACL. Conteúdo
pessoal oculto por padrão.

### Revisão humana

Amostragem redigida; fila de feedback negativo; rubrica; causa raiz; novos
casos de teste a partir de falhas.

### Experimentos A/B

Uma variável por vez (coach, quick actions, welcome, handoff, cards, modelo,
prompt). Alocação estável. Guardrails. Interromper se piorar. **Não**
experimentar autorização, privacidade ou P0.

### Gestão de prompts

Arquivos versionados; ID no log; changelog; eval antes do deploy; rollout
progressivo; rollback independente quando seguro. Proibir edição livre de
prompt no Admin de produção.

### Rotina

- **Diária:** erro, custo, disponibilidade, filas, segurança.
- **Semanal:** feedback negativo, gaps, tools lentas, conversão, custos.
- **Mensal:** SLO, retenção LGPD, acessos Admin, chaves, modelos, restore.

### Aceite

Releases progressivas. Rollback rápido. Qualidade e conversão mensuradas.
Custos previsíveis. Nenhuma mudança de modelo/prompt sem avaliação.

---

## 3. Ordem de execução e dependências

### Bloco A — bloqueadores (antes de qualquer escala)

1. Fase 0 — Git e governança.
2. Fase 1 — Segurança P0.
3. Fase 2 — LGPD.
4. Fase 3 — testes mínimos dos fluxos sensíveis.

### Bloco B — operação confiável

5. Fase 4 — observabilidade.
6. Fase 5 — resiliência e capacidade.
7. Completar Fase 3 (E2E + evals extensas).

### Bloco C — experiência e inteligência

8. Fase 6 — UX, a11y, handoff.
9. Fase 7 — contexto, memória, RAG.
10. Fase 8 — experimentação e melhoria contínua.

### Dependências externas

Segurança/infra (Nginx, Redis, APM); jurídico/DPO; atendimento (handoff);
catálogo/fitment; B2B; dados/marketing (atribuição); QA.

---

## 4. Rollout

| Etapa | Quem | Escopo |
|-------|------|--------|
| 1 Interno | Equipe AWA | Dados sintéticos; escrita desligada; telemetria completa |
| 2 Piloto | % pequena de visitantes | Catálogo/FAQ; pedidos só autenticados; handoff; orçamento baixo |
| 3 Autenticados | Clientes logados | Pedidos, carrinho, histórico consentido |
| 4 B2B | Aprovados | Leitura primeiro; escrita só com nonce |
| 5 Escala | Progressivo | SLO, custo e conversão diários; rollback por guardrail |

---

## 5. GO / NO-GO global

**GO:** código versionado; backup/rollback prontos; autorização de pedidos
testada; CSRF e rate limit ativos; B2B com nonce; LGPD operacional; testes e
evals críticas verdes; observabilidade; handoff; checkout sem widget; custo e
capacidade no orçamento; sem regressão de CWV/a11y.

**NO-GO:** módulo fora do Git; exposição de pedido; escrita só pelo LLM;
mensagens sem retenção; sem fallback humano; sem alerta de erro/custo; testes
de segurança falhando; provider único sem contingência; regressão de checkout;
diff de produção não reproduzível.

---

## 6. Definition of Done por entrega

Requisito e ameaça documentados. DI + PSR-12. Sem ObjectManager direto. Sem
segredo/placeholder. Unitários e integração verdes. Evals relevantes verdes.
Logs sem PII. Métricas adicionadas. Config documentada. Diff revisado. Codacy
sem regressão introduzida. Hashes/permissões conferidos. Deploy mínimo
autorizado. HTTP, logs e fluxos adjacentes validados. Rollback confirmado.

---

## 7. Backlog consolidado

### P0

- Rastrear módulo no Git.
- Bloquear consulta guest de pedido + ownership sempre.
- Reativar CSRF/`form_key`.
- Limitar histórico antes do LLM.
- Confirmação server-side para escrita B2B.
- LGPD: redação, retenção, DSR.
- Observabilidade mínima e alertas.
- Testes de autorização.
- Remover assistente do checkout.

### P1

- Rate limit atômico e multicamada; quota Admin e de custo.
- Fallback multi-provider; retry; circuit breaker; bulkhead.
- E2E e eval suite.
- Handoff humano; a11y completa; persistência consentida.
- Analytics; feedback; retry/offline; mobile full-sheet.
- Quick actions; cards estruturados; contexto de página; i18n.
- Backup/restore das tabelas AI.

### P2

- RAG híbrido + citações.
- Model routing; semantic cache público.
- Painel avançado; A/B; personalização consentida.
- Streaming com cancelamento.
- Atribuição de receita; revisão contínua.
- Otimização de assets e carregamento condicional.

---

## 8. Arquivos-chave de referência

| Área | Caminho |
|------|---------|
| Widget | `view/frontend/templates/chat/widget.phtml` |
| JS chat | `view/frontend/web/js/ai-assistant.js` |
| Coach | `view/frontend/web/js/guided-coach.js` |
| Layout global | `view/frontend/layout/default.xml` |
| Controller storefront | `Controller/Chat/Message.php` |
| Copiloto Admin | `Controller/Adminhtml/Copilot/Chat.php` |
| Orquestração | `Model/Orchestrator.php` |
| OpenRouter | `Model/Llm/OpenRouterClient.php` |
| Pedidos | `Model/Tool/OrderTrackingTool.php` |
| Cotação / recompra | `Model/Tool/B2BQuoteTool.php`, `B2BReorderTool.php` |
| Rate limit | `Model/RateLimiter.php` |
| Log | `Model/ConversationLogger.php` + `etc/db_schema.xml` |
| Config | `Helper/Config.php`, `etc/config.xml`, `etc/adminhtml/system.xml` |
| CSS storefront | `view/frontend/web/css/ai-assistant.css` / `.less` |
| Checkout (remove widget) | `view/frontend/layout/checkout_index_index.xml`, `onepagecheckout_index_index.xml` |

---

## 9. Status do roadmap

- [x] Auditoria somente leitura (2026-08-18)
- [x] Riscos P0 identificados
- [x] Roadmap consolidado neste arquivo
- [x] Fase 0 inventário + backup (2026-08-18)
- [x] Fase 0 Git do módulo + roadmap (aprovado 2026-08-18)
- [ ] Fase 0 staging/CI e `config.php` isolado
- [x] Fase 1 código P0 (CSRF, IDOR, nonce B2B, checkout) — 2026-08-18
- [ ] Fase 1 testes automatizados + Nginx rate limit
- [x] Fase 2 fatia 1 (PII, retenção, DSR delete, aviso IA + handoff) — 2026-08-18
- [ ] Fase 2 completa (cookies, DSR export, OpenRouter/base legal)
- [ ] Fase 3 concluída
- [ ] Fase 4 concluída
- [ ] Fase 5 concluída
- [ ] Fase 6 concluída
- [ ] Fase 7 concluída
- [ ] Fase 8 concluída

Próximo passo recomendado: fechar residual da Fase 1 (Nginx `/aiassistant/`
+ testes de autorização) e o restante da Fase 2 (cookies, DSR export).
`app/etc/config.php` e `GrupoAwamotos_HelpCenter` continuam fora do
commit do módulo.
