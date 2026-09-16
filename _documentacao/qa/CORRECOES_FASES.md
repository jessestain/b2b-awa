# Correções por fases

Patches `P-00n`. Este arquivo **não autoriza** aplicação. Ordem: menor risco de regressão primeiro, como em `WhatsAppCommerce/AUDITORIA_CORRECAO_FASES.md`.

Estados do patch: `diagnosticado` · `aguardando autorização` · `aplicado` · `retestado` · `abortado` · `rollback`.

---

## Fila

| ID | Risco | Fase QA | Sintoma | Estado | Depende de |
|---|---|---|---|---|---|
| P-001 | Baixo | F3 / B-REG-02 | Página de sucesso do cadastro B2B em branco + CRITICAL | `aguardando autorização` | Só XML de layout |
| P-002 | Baixo | F0 / V-HDR-01 | Header HTTP `x-awa-debug-w18` no site público | `diagnosticado` | Achar o plugin que emite |
| P-003 | Médio (operacional) | F5 / S-ORD-01 | Pedidos 114/115 `ready_for_import` parados | `diagnosticado` | Admin + desktop Sectra; **não** é hotfix de loja |
| P-004 | Médio (dados) | F5 / S-ORD-03 | Pedido cancelado ainda `ready_for_import` | `diagnosticado` | Confirmar regra no `StuckOrderCleanup` |
| P-005 | Médio (dados) | F5 / S-DUP-01 | Um CNPJ em dois customers | `diagnosticado` | Decisão comercial; sem merge automático |
| P-006 | Médio (ops) | F0 / V-GIT-01 | 876 arquivos fora do Git em produção | `diagnosticado` | Inventário; **proibido** `git reset` no worktree sujo |
| P-007 | Hipótese | F5 / S-ACK-01 | ACK pulado: id Magento ≠ id ERP | `diagnosticado` | Entender se é pedido legado 200102 |
| P-008 | Hipótese | F1 / L-PRICE-01 | Preço no JSON-LD guest (mapa 2026-07) | `não retestado` | Executar L-PRICE-01 antes de patch |
| P-009 | Hipótese | F2 / C-ADD-01 | `cartId = 0` no log | `não retestado` | Reproduzir logado |
| P-010 | Baixo | F2 / C-PLACE-01 | Success page preta após place order | `retestado` | Proxy gerado desatualizado |

Não misturar P-001 (layout B2B) com P-003 (fila Sectra) na mesma janela.

---

## P-001 — Sucesso do cadastro B2B (`B-REG-02`)

**Sintoma:** GET `/b2b/register/success/` devolve HTTP 200, árvore quase vazia, log CRITICAL. Cliente `#9123` bateu nisso em 2026-09-14 15:59 UTC após `Register/Save`.

**Causa (CONFIRMADA):** layout não commitado passa `GrupoAwamotos\B2B\Helper\Data` como argumento de bloco. Magento exige `ArgumentInterface`. O Helper estende só `AbstractHelper`. O PHTML **não usa** o helper.

```diff
-            <block class="Magento\Framework\View\Element\Template"
+            <block class="GrupoAwamotos\B2B\Block\Register\Success"
                    name="b2b.register.success"
                    template="GrupoAwamotos_B2B::register/success.phtml"
                    cacheable="false">
+                <arguments>
+                    <argument name="b2b_helper" xsi:type="object">GrupoAwamotos\B2B\Helper\Data</argument>
+                </arguments>
```

Arquivo: `app/code/GrupoAwamotos/B2B/view/frontend/layout/b2b_register_success.xml`  
Bloco PHP já existe: `Block/Register/Success.php` (protocolo de sessão).  
`Helper/Data.php` ganhou `isCurrentCustomerApproved()` no mesmo worktree — **não** precisa ir neste patch.

**Correção mínima:** remover o nó `<arguments>…</arguments>`. Manter a classe `Block\Register\Success`.

**Não fazer:** implementar `ArgumentInterface` no Helper só para satisfazer o XML.

**Risco:** baixo. Só a página de obrigado do cadastro.

**Efeito colateral:** nenhum no preço/checkout. Cadastros novos voltam a ver o texto de sucesso.

**Backup:** copiar o XML para fora do DocumentRoot com `cp -a` + `sha256sum` antes e depois (protocolo `awa-production-safety`).

**Rollback:** restaurar o XML byte a byte. Não usar `git checkout` no worktree sujo.

**Reteste:** `B-REG-02` (GET da página) + `rg ArgumentInterface var/log/system.log` no minuto seguinte. Não precisa cadastrar CNPJ de verdade: o GET já dispara o CRITICAL hoje.

**Cache:** se o handle estiver em `block_html` / FPC, invalidação **mínima** só depois da autorização (não `cache:flush` global como primeiro passo).

**Autorização:** pendente. **Não aplicado.**

---

## P-002 — Header de debug público (`V-HDR-01`)

**Sintoma:** respostas públicas incluem `x-awa-debug-w18: plugin-ran` (home, busca, login, 404).

**Causa:** HIPÓTESE — plugin de otimização de head (`x-awa-header-optimize` também presente). Não abrir diff até `rg` no módulo certo.

**Correção prevista:** emitir o header só se `MAGE_MODE=developer` ou flag off em production.

**Risco:** baixo (header), mas o plugin mexe no `<head>` — retestar `L-HOME-01` e `L-SEARCH-01`.

**Autorização:** não pedir até localizar o arquivo.

---

## P-003 — Pedidos prontos e não importados (`S-ORD-01`)

**Sintoma:** `000000115` processing + `ready_for_import` desde 2026-09-10. `000000114` pending + mesmo status. Cron prospect não libera (`orders_released_for_import: 0`).

**Isto não é patch de loja.** Runbook operacional:

1. Admin F4: conferir os dois increment_id (A-ORD-01/02, A-SEC-01).
2. Desktop Sectra: fila **Importar Pedidos AWA** (mapa operacional).
3. Só então decidir código (`oc_order` vazio vs PULL vs cliente não validado).

**Proibido nesta fase:** reimportação em massa, `setup:upgrade`, flush amplo.

---

## P-004 — Cancelado ainda `ready_for_import` (`S-ORD-03`)

Pedido `000000101` canceled + `ready_for_import`. Há `StuckOrderCleanup` / `b2b:sectra:cancel-stuck-orders`. Diagnosticar se o cron não pega cancelados antigos. Sem UPDATE em `sales_order` sem autorização e backup de linha.

---

## P-005 — CNPJ duplicado (`S-DUP-01`)

Um valor de `b2b_cnpj` em dois `customer_entity`. Não publicar o CNPJ. Ação: ficha no admin + decisão de merge. Sem delete.

---

## P-006 — Worktree sujo (`V-GIT-01`)

876 paths modificados/não rastreados no DocumentRoot. Qualquer hotfix (incluindo P-001) precisa hash + backup. **Não** `git reset --hard`. Inventário em execução F0; não misturar com correção funcional.

---

## P-007 / P-008 / P-009

Só viram patch depois de reprodução na F2/F5. Até lá permanecem HIPÓTESE. P-009: add logado (20×3122) **não** gerou `cartId=0` novo; o ERROR 19:54 UTC permanece HIPÓTESE de sessão guest/JS.

---

## P-010 — Página de sucesso do pedido (`C-PLACE-01`)

**Sintoma:** place order grava o pedido; GET `/checkout/onepage/success/` devolve tela preta Magento (“An error has happened…”) + CRITICAL `Typed property … EmptyCartContext::$checkoutSession must not be accessed before initialization` (linha 187).

**Causa (CONFIRMADA):** `CartEmptyPageTitlePlugin` injeta `EmptyCartContext\Proxy` (`di.xml`). O Proxy em `generated/code/…/EmptyCartContext/Proxy.php` é de **2026-09-03** e **não** encaminha `markOrderSuccessEmptyCart()` / `clearOrderSuccessEmptyCart()`. O Proxy Magento **não** chama o construtor da classe-mãe; o método novo roda no objeto Proxy com `$checkoutSession` não inicializado. Stack: plugin `beforeRenderResult` na success page, 2026-09-14 20:35:53 UTC, pedido `000000116`.

**Correção mínima (aplicada nesta sessão, funil):**

1. Fonte: `CartEmptyPageTitlePlugin` grava as flags na `CheckoutSession` (já injetada) em vez de chamar o método ausente no Proxy.
2. Runtime: o Proxy gerado passou a encaminhar os dois métodos (sobrevive até o próximo `setup:di:compile`).

**Não fazer nesta janela:** `setup:di:compile` completo.

**Risco:** baixo. Só título/flag da success e do carrinho vazio pós-pedido.

**Efeito colateral:** `systemctl reload php8.4-fpm` (OPcache FPM `validate_timestamps=0`). Pedido `000000116` já existia; o reload não cria pedido.

**Backup:** `/home/deploy/backups/qa-20260914-f2/p010/` + `rollback.sh`.

**Rollback:** restaurar os dois `.bak` e reload FPM. Não `git checkout`.

**Reteste:** success/carrinho vazio mostrou “Pedido confirmado” `#000000116`; sem novo CRITICAL `EmptyCartContext` após 20:40 UTC.

**Autorização:** correção de funil da missão compra real 14 set. **Aplicado e retestado.**

---

## Critério para fechar um `P-00n`

- [ ] Autorização registrada na execução do dia  
- [ ] Backup e hashes  
- [ ] Diff só dos arquivos aprovados  
- [ ] IDs de reteste `verificado` ou `corrigido e retestado`  
- [ ] Sem nova CRITICAL relacionada  
- [ ] Rollback ainda possível  
