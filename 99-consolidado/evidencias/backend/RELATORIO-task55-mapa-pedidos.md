# task-55 — mapa de pedidos do ERP no caminho de reconciliação (correção de raiz)

Autor: dev-backend · 11/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo usado: `app/code/GrupoAwamotos/ERPIntegration/` + `app/code/GrupoAwamotos/B2B/` + `_backups/erp-map-reconcile-*`.
`LogMonitoring` e o tema **não** foram tocados. **A conexão de ESCRITA do ERP não foi usada** (só a de leitura, para `SELECT`).
Nenhum commit/push.

## VEREDITO: **PASS** — corrigido no produtor, backfill aplicado, e validado por medição (`Syncing status for 0` → **21**). **Sem `setup:di:compile`.**

---

## 1. O ponto exato onde o `imported` era gravado SEM o mapa

`app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php::syncImportedOrderFlags()` (linhas 313-350):

```php
$rows = $connection->fetchAll(
    'SELECT oi.order_id, so.entity_id, so.increment_id, so.customer_id
     FROM oc_order_imported oi
     INNER JOIN sales_order so ON so.entity_id + ? = oi.order_id
     WHERE so.sectra_import_status IS NULL OR so.sectra_import_status IN (?, ?)', ...);

foreach ($rows as $row) {
    $orderId = (int) $row['entity_id'];
    $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);   // <-- MARCA imported
    $this->syncLogger->log(ProspectEvent::ORDER_IMPORTED_SUCCESS, ...);    // <-- loga sucesso
    $updated++;
}
```
**Nenhuma escrita em `grupoawamotos_erp_entity_map`.** Era este o produtor que divergia do ACK
(`OrderPullManagement.php:204`, que grava os dois). Este método é chamado pelo cron
`SyncSectraProspectStatus` e pelo CLI `SyncSectraProspectCommand`.

### Duas correções de premissa do enunciado (medidas, não supostas)

1. **`oc_order_imported` NÃO tem `erp_code`.** O schema é só `order_id | date_imported`:
   ```
   order_id | int | PRI     date_imported | datetime
   ```
   Não existe, em nenhuma tabela do Magento, o código do pedido do ERP: conferi
   `grupoawamotos_erp_sync_log` (as linhas `entity_type='order'` estão **vazias**; as que têm `magento_id`
   dos 23 são de **cliente**, com o código do CLIENTE), `sectra_export_audit` (0 linhas),
   `grupoawamotos_b2b_sectra_sync_log` (`sectra_chave` = cliente), `grupoawamotos_erp_order_retry` (0 linhas)
   e `sales_order` (só tem `customer_erp_code`). **A única fonte é o próprio ERP** — lido pela conexão
   de **LEITURA**, exatamente como `reconcileOrdersAlreadyInErp()` já fazia.
2. **`isOrderAlreadyImported()` não consulta o ERP** — consulta a tabela local `oc_order_imported`
   (o ACK do desktop). Por isso o loop acima "sabia" que o pedido foi importado, mas não tinha o código.

---

## 2. O diff aplicado (2 arquivos)

### 2.1 Produtor — `B2B/Model/Sectra/OrderImportGate.php`

```diff
         $updated = 0;
+        $importedOrderIds = [];
         foreach ($rows as $row) {
             $orderId = (int) $row['entity_id'];
             $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);
+            $importedOrderIds[] = $orderId;
             ...
             $updated++;
         }
+        /* task-55 — este laço marcava 'imported' e NÃO escrevia o mapa. 29 pedidos 'imported' e só
+           6 com linha no mapa; como o OrderSync::getPendingOrdersForSync() lê SOMENTE o mapa, esses
+           pedidos nunca entravam no sync de status ("Syncing status for 0 orders", 82x). */
+        if ($importedOrderIds !== []) {
+            $this->syncOrderEntityMap($importedOrderIds);
+        }
         return $reconciled + $updated;
     }
+
+    private function syncOrderEntityMap(array $orderIds): int
+    {   // lê CODIGO do ERP pela CONEXÃO DE LEITURA, upsert em grupoawamotos_erp_entity_map
+        // (mesma semântica de SyncLog::setEntityMap), nunca lança, devolve quantos mapeou
+    }
```

Guarda de segurança implementada: código do ERP só é aceito se **numérico e > 0**
(`ctype_digit($codigo) && (int) $codigo > 0`) — o mesmo filtro que o consumidor aplica
(`erp_code REGEXP '^[0-9]+$'`). Se o ERP não responder, **loga warning e devolve 0**, sem derrubar o
flag `imported` (que é a obrigação principal do caminho).

### 2.2 Métrica — `ERPIntegration/Model/Api/CustomerPullManagement.php` (health check `pending_orders`)

```diff
             $pendingOrders = (int) $db->fetchOne(
                 "SELECT COUNT(*) FROM sales_order WHERE state IN ('new','pending_payment','processing')
-                 AND entity_id NOT IN (SELECT magento_entity_id FROM grupoawamotos_erp_entity_map WHERE entity_type='order')"
+                 AND COALESCE(sectra_import_status, '') <> 'imported'"
             );
```
Medido antes da troca: **mapa = 8** × **`sectra_import_status` = 7**. A diferença era exatamente o
pedido **83** (`imported` e sem mapa). Detalhe dos 8 em `new/pending_payment/processing`:
`ready_for_import 6` · `import_failed 1` · `imported 1`. O mapa é o **vínculo de sync**, não a marca
de importação — agora a métrica usa quem de fato marca.

### 2.3 Por que NÃO precisou de `setup:di:compile` (e não rodei nenhum)

* `OrderImportGate`: **nenhum construtor mudou** — `grep -c 'function __construct'` antes = 1, depois = 1.
  O método novo usa `$this->resourceConnection` e `$this->erpConnection`, **já injetados**.
* `CustomerPullManagement`: idem (1 → 1), mudança só no corpo do health check.
* Ambos resolvidos pelo DI compilado em runtime (a classe foi instanciada e o método executado — ver §4).

---

## 3. BACKFILL (transacional, só INSERT no mapa)

Comando: `php /tmp/backfill-mapa.php --apply` — `--apply` explícito; sem ele é dry-run.

```
=== ANTES ===    linhas do mapa (entity_type='order'): 7
                 pedidos imported SEM mapa: 23
=== ERP (conexão de LEITURA) ===   linhas: 21 | com CODIGO: 21 | sem CODIGO: 2 (83,87)
=== APPLY (transacional) ===      COMMIT ok. inseridos=21 atualizados=0
=== DEPOIS ===   linhas do mapa (entity_type='order'): 7 -> 28 (delta 21)
                 pedidos imported ainda sem mapa: 2  (id=83 state=processing; id=87 state=canceled)
```

**21 linhas inseridas** (7 → 28), em transação com `commit`; nenhum `rollback` foi necessário.
Os 2 restantes **não foram preenchidos de propósito**: o ERP não devolveu `CODIGO` para
`PEDIDOWEB` 200083 e 200087. **Inventar um código ali seria pior que deixar vazio** — o sync passaria a
consultar o pedido ERRADO no ERP. Ficam declarados como pendência.

Backfill é **idempotente** (checa `entity_type`+`erp_code` antes de inserir) e **não toca** em pedido,
status, pagamento, estoque nem no ERP.

---

## 4. VALIDAÇÃO POR MEDIÇÃO

### 4.1 O consumidor passou a ver os pedidos

```
pedidos elegíveis (query do getPendingOrdersForSync): 21
=== rodando SyncOrderStatuses::execute() (o job do cron */15) ===
[2026-10-11T03:50:22] erp_integration.INFO: [ERP] Syncing status for 21 orders          <-- ERA 0 (82x no log)
[2026-10-11T03:50:39] erp_integration.INFO: [ERP Cron] Order status sync completed {"synced":21,"errors":0,"skipped":0}
```
**`N = 21 > 0`.** O log de `"Syncing status for 0 orders"` (82 ocorrências) para de ser a norma.

### 4.2 O efeito que isso teve nos pedidos — e a prova de que está CORRETO

O sync, ao finalmente enxergar os 21 pedidos, **cancelou os 21** (`processing/processing → canceled/canceled`),
com o comentário `[ERP] Status atualizado automaticamente.` Fui checar no ERP **por que**:

```
SELECT CODIGO, PEDIDOWEB, STATUS FROM VE_PEDIDO WHERE CODIGO IN (os 21)
  -> 21 linhas, distribuicao de STATUS: {"C":21}
```
**Todos os 21 estão com `STATUS='C'` (cancelados) no Sectra**, e `OrderSync::STATUS_MAP` (linha 35) mapeia
`'C' => ['state' => STATE_CANCELED, 'status' => 'canceled']`. **O Magento estava desatualizado, não errado.**
O cliente/operador agora vê o estado real do ERP — que é exatamente o objetivo declarado da task
("eles ficam presos em processing e o operador não tem como saber o estado real no ERP").

**Declaração honesta:** eu **rodei o job manualmente** (a task pediu). Ele roda sozinho a cada 15 min; como o
mapa é o único portão, esse mesmo cancelamento teria acontecido no primeiro tick após o backfill. O job não
criou faturas nem envios (`sales_shipment: 0 → 0`).

### 4.3 Prova dirigida do produtor novo (mesmo rigor da task-49)

Invoquei o método privado real `syncOrderEntityMap()` por reflexão:

| caso | entrada | retorno | linhas do mapa |
|---|---|---|---|
| 1 — ids **sem** código no ERP (83, 87) | `[83,87]` | **0** (warning, sem exceção) | 28 (não mudou) |
| 2 — ids já mapeados (26, 27, 28) | `[26,27,28]` | **3** (branch UPDATE idempotente) | 28 (não mudou) |
| 3 — lista vazia | `[]` | **0** | 28 |

**E nenhum pedido foi tocado**: `state/status/sectra_import_status` dos pedidos 26, 27, 83, 87 e 108
**idênticos** antes e depois. O único campo alterado foi `last_sync_at` das 3 linhas do caso 2 (esperado).

---

## 5. Site e gate

```
home 200 · PLP 200 · PDP 200 · carrinho 200 · login B2B 200

pwsh -NoProfile -File tools\verificar-loja.ps1
  1ª rodada: 32/33 — FALHA em "Shell SSR removido: shell.existe=True"
  2ª rodada: 33 verificações · 33 PASS · 0 FAIL
```
A falha da 1ª rodada é o **flake já documentado** no projeto (item 94 da memória) e é do minicart/shell
SSR — **frontend**, que esta task não toca (as duas mudanças são backend: mapa de pedidos e um health
check). Confirmei re-rodando, como a task manda: 33/33.

---

## 6. Rollback

Backups (`cp -p`):
* `_backups/erp-map-reconcile-20261011T034835Z/OrderImportGate.php.orig` (sha do original em `ANTES.txt`)
* `_backups/erp-map-reconcile-20261011T035323Z/CustomerPullManagement.php.orig`

```bash
R=<root>
cp -p $R/_backups/erp-map-reconcile-20261011T034835Z/OrderImportGate.php.orig \
      $R/app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php
cp -p $R/_backups/erp-map-reconcile-20261011T035323Z/CustomerPullManagement.php.orig \
      $R/app/code/GrupoAwamotos/ERPIntegration/Model/Api/CustomerPullManagement.php
php -l <os dois arquivos>
bash $R/scripts/opcache-reset.sh
```

**O que o rollback NÃO desfaz** (e precisa ser dito): as 21 linhas inseridas no mapa e o cancelamento dos
21 pedidos são dados, não código. Reverter o código volta a impedir que o mapa cresça pelo caminho de
reconciliação, mas **não** apaga os vínculos nem reabre os pedidos. Reverter os dados seria:
`DELETE FROM grupoawamotos_erp_entity_map WHERE entity_type='order' AND magento_entity_id IN (...)` —
**não fiz e não recomendo**: o cancelamento é o estado real do ERP (STATUS='C' nos 21).

---

## 7. O que NÃO consegui validar

1. **Pedidos 83 e 87** — sem `CODIGO` no ERP para `PEDIDOWEB` 200083/200087. 83 continua `processing` e
   sem mapa (não entra no sync); 87 está `canceled` no Magento (e continua fora do sync por estado).
   Isso não é falha da correção: é dado que o ERP não devolve. **Precisa de decisão do time do ERP.**
2. **Prova de que o caminho do produtor dispara sozinho no cron** — provei o método por reflexão e o
   consumidor ponta a ponta, mas **não esperei** um tick real do `SyncSectraProspectStatus` com pedidos
   novos elegíveis (não havia nenhum no momento). O primeiro pedido importado após esta mudança é a
   prova de campo.
3. **`vw_sectra_pedidos_sincronizados`** é uma VIEW MySQL com exatamente as linhas do mapa (era 7, agora 28).
   Não sei se algum relatório externo (Metabase/BI) a consome — não há referência no `app/code`.
   **Não alterei a view** (é schema, fora do escopo). Se algum painel externo usar essa view para dizer
   "pedidos no ERP", ele passou a mostrar 28 — e continua sendo uma leitura derivada do mapa, não de
   `sectra_import_status`. Fica registrado para o Lead decidir.

---

## 8. Arquivos e evidências

* Alterados: `B2B/Model/Sectra/OrderImportGate.php` (`3f34f021…`, 23.170 bytes) e
  `ERPIntegration/Model/Api/CustomerPullManagement.php` (`47ce1f7d…`, 16.002 bytes) — dono/grupo/modo
  `1004/33/674` preservados.
* Scripts em `99-consolidado/evidencias/backend/_scripts/`: `mapa-recon{,2,3,4}.sh`, `mapa-fonte.php`,
  `mapa-erpcode{,2}.php`, `mapa-erp-probe.php`, `mapa-metrica.sh`, `mapa-consumidor.sh`, `fix-mapa.php`,
  `fix-mapa-aplicar.sh`, `backfill-mapa.php`, `sync-base.sh`, `sync-validar.php`, `sync-erp-status.php`,
  `metrica-pending.php`, `fix-metrica.php`, `fix-metrica-aplicar.sh`, `prova-produtor.php`
* `cache:flush` **nunca** usado. Conexão de ESCRITA do ERP **nunca** usada.
