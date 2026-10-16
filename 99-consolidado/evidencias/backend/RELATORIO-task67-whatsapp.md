# task-67 — WhatsAppConsumer descartava o retorno do envio (falha silenciosa gravada como COMPLETE)

Autor: dev-backend · 11/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo: `app/code/GrupoAwamotos/B2B/` + `_backups/whatsapp-success-*`. Nada mais foi tocado
(sem `LogMonitoring`, sem tema, sem SMTP, sem fila de `product_alert`, sem watchdog, sem conexão de escrita do ERP).

## VEREDITO: **PASS** — corrigido e provado end-to-end. **Sem `setup:di:compile`.**

---

## 1. O defeito e os `case` afetados

**TODOS os 8 `case` tinham o defeito** (não só o `send_text` do diagnóstico), porque todos chamavam o
serviço e descartavam o array de retorno:

| `case` | chamada | retorno descartado |
|---|---|---|
| `new_registration` | `notifyNewB2BRegistration($data)` | lista de resultados |
| `new_quote` | `notifyNewQuote($data)` | lista de resultados |
| `new_order` | `notifyNewOrder($data)` | aninhado (`team` + `customer`) |
| `customer_approved` | `notifyCustomerApproval($data)` | folha |
| `customer_rejected` | `notifyCustomerRejection($data)` | folha |
| `quote_response` | `notifyQuoteResponse($data)` | folha |
| `order_status` | `notifyOrderStatusUpdate($data)` | folha |
| `send_text` | `sendText($phone, $msg)` | folha |

E o `catch` só logava — **nunca relançava** ⇒ o handler terminava "bem" e o MQ gravava **COMPLETE (4)**.
Estado medido antes: `grupoawamotos.b2b.whatsapp.send` = **28 mensagens, status=4, number_of_trials=0**.

### Achado que um `if (!$result['success'])` ingênuo teria errado

Os retornos **não são uniformes** (lido em `WhatsAppService.php`):
* **folha** — `sendText`, `notifyCustomerApproval/Rejection`, `notifyQuoteResponse`, `notifyOrderStatusUpdate`
  → `['success' => bool, 'message' => string]`
* **lista** — `notifyTeam()` (`:61-68`) → `[ ['success'=>…], ['success'=>…] ]`
  (usado por `notifyNewB2BRegistration` e `notifyNewQuote`)
* **aninhado** — `notifyNewOrder()` (`:82-94`) → `['team' => [lista], 'customer' => folha|[]]`

Ler `$result['success']` direto nesses dois últimos daria `null` → tratado como sucesso **ou** como falha
dependendo do critério, nos dois casos errado. Por isso o helper percorre a estrutura recursivamente.

---

## 2. ⚠️ CORREÇÃO DE PREMISSA: o `throw` NÃO leva a `RETRY_REQUIRED` — leva a **ERROR (6)**

O enunciado diz "`throw` para que o MQ marque `RETRY_REQUIRED`". **Não é o que acontece neste install**,
e a diferença é boa notícia:

```
vendor/magento/framework-message-queue/Consumer.php:261
    } catch (Exception $exception) {
        $queue->reject($message, false, $exception->getMessage());     // requeue = FALSE

vendor/magento/module-mysql-mq/Model/Driver/Queue.php:138-150
    public function reject(EnvelopeInterface $envelope, $requeue = true, $rejectionMessage = null) {
        if ($properties[MESSAGE_NUMBER_OF_TRIALS] < $this->maxNumberOfTrials && $requeue) {
            $this->queueManagement->pushToQueueForRetry($relationId);              // -> RETRY_REQUIRED (5)
        } else {
            $this->queueManagement->changeStatus([$relationId], MESSAGE_STATUS_ERROR);  // -> ERROR (6)
            if ($rejectionMessage !== null) { $this->logger->critical(__('Message has been rejected: %1', ...)); }
        }
    }
```
Como o framework passa **`$requeue = false`**, a condição `… && $requeue` é falsa ⇒ **status = ERROR (6)**.
Constantes (`MysqlMq\Model\QueueManagement:26-31`): NEW=2 · IN_PROGRESS=3 · COMPLETE=4 ·
RETRY_REQUIRED=5 · **ERROR=6** · TO_BE_DELETED=7. E `ResourceModel/Queue.php:180` só consome
**NEW (2) e RETRY_REQUIRED (5)** ⇒ **ERROR é TERMINAL: não reprocessa.**

`RETRY_REQUIRED` só sai do caminho legado `MysqlMq\Driver\Queue::subscribe()` (`:127`, `reject($envelope)`
com o default `requeue=true`) — que **não** é o usado por `queue:consumers:start`.

**⇒ Resposta à pergunta do teto de retry: com este caminho, NÃO é preciso teto** — não existe laço, porque
a mensagem para em ERROR. Não implementei limite nenhum (e não seria necessário). Se o dono **quiser**
retentativa para falhas transitórias (Z-API 500, timeout), isso exige outro mecanismo (reenfileirar
explicitamente), **não** um `throw` — proponho como decisão separada, não implementei.

---

## 3. O diff (1 arquivo, 4 mudanças)

`app/code/GrupoAwamotos/B2B/Model/Notification/WhatsAppConsumer.php`

1. **os 8 `case`** passam o retorno por `$this->sendOrFail($type, <retorno>, $data)` (a mensagem, o
   destinatário e o momento continuam idênticos — **semântica de negócio intacta**);
2. **`sendOrFail()`** (novo, privado): conta envios OK e coleta recusas com `contarEnvios()`;
   havendo **qualquer** `success === false` → `logger->error` com tipo/destinatário/motivos e **`throw RuntimeException`**;
   havendo **zero tentativas** (retorno vazio — sem telefone ou sem número configurado) → `logger->error` e **throw**;
   havendo ≥1 sucesso e nenhuma recusa → `logger->info` de confirmação;
3. **`contarEnvios()`** (novo, privado): recursão que trata folha/lista/aninhado — uma folha é o array que
   **tem** a chave `success`;
4. **o `catch` relança**: `throw $e instanceof \Exception ? $e : new \RuntimeException(...)` — o
   `\Throwable` não-`Exception` é reembrulhado para o `catch (Exception)` do framework sempre pegar.
   O `default` (tipo desconhecido) também passou de `warning`-e-completa para `error`-e-lança.

Números: `sendOrFail` definido 1×, chamado **8×** (todos os cases), `contarEnvios` 1×, `throw` no catch 1×.

**Sem `di:compile`** — provado: `grep -c 'function __construct'` = **1 → 1**; nenhuma dependência nova
(usa só `$this->logger` e o serviço já injetados). sha256 `7daf7bc742393aa6ee3ebf614e6751371caecbb7539c8e549c6ab2dbcdbc7911`,
7.819 bytes, dono/grupo/modo `1004/33/674` preservados.

---

## 4. PROVA DIRIGIDA

### 4.1 Parte 1 — `sendOrFail()` por reflexão, com os 3 formatos reais + bordas: **9/9 PASS**

```
PASS  folha OK — sendText/notifyCustomerApproval             lanca=false esperado=false
PASS  folha RECUSADA — Z-API / telefone invalido             lanca=true  esperado=true   <- WhatsApp "send_text": 1 envio(s) recusado(s) — Invali
PASS  folha RECUSADA — WhatsApp desabilitado                 lanca=true  esperado=true
PASS  LISTA 2x OK — notifyTeam                               lanca=false esperado=false
PASS  LISTA 1 OK + 1 RECUSA — notifyTeam parcial             lanca=true  esperado=true   <- ... "Z-API 400"
PASS  ANINHADO team OK + customer OK — notifyNewOrder        lanca=false esperado=false
PASS  ANINHADO team OK + customer RECUSA                     lanca=true  esperado=true
PASS  ANINHADO team OK + customer VAZIO (sem telefone)       lanca=false esperado=false
PASS  VAZIO — nenhum numero configurado                      lanca=true  esperado=true   <- "nenhuma tentativa de envio"
```

### 4.2 Parte 2 — ponta a ponta NA FILA REAL, sem falar com cliente e sem chamar a API

Publiquei **uma** mensagem de teste (`send_text`, `phone = ""`). O sender
(`SmartSuggestions/Model/Whatsapp/Sender.php:62-67`) recusa telefone vazio com
`['success' => false, 'message' => 'Invalid phone number']` **antes de qualquer chamada HTTP** — ou seja,
falha real, determinística, sem Z-API e sem destinatário humano.

O consumidor que já roda pegou a mensagem **sozinho** e o resultado foi:

```
mensagem de teste (queue_message 6650 / status 6586): status = 6 (ERROR), trials = 0
distribuicao da fila: status=4:28   status=6:1
```
⇒ **a falha deixou de ser gravada como COMPLETE.** As **28 mensagens históricas continuam status=4,
intocadas** (a task proibia destravá-las).

E o rastro no log, na ordem exata da cadeia (04:24:08):

```
main.ERROR:    [B2B WhatsAppConsumer] Envio RECUSADO pelo provedor; a mensagem NAO sera marcada como concluida.
               {"type":"send_text","destinatario":"","envios_ok":0,"motivos":["Invalid phone number"]}
main.ERROR:    [B2B WhatsAppConsumer] Failed to process message.
               {"type":"send_text","error":"WhatsApp \"send_text\": 1 envio(s) recusado(s) — Invalid phone number"}
main.CRITICAL: Message has been rejected: WhatsApp "send_text": 1 envio(s) recusado(s) — Invalid phone number
```
A 3ª linha é o log do próprio `Driver/Queue::reject()` — a prova de que o framework recebeu o `throw`.

### 4.3 Parte 3 — envio que dá certo continua funcionando

Subclasse anônima de `WhatsAppService` devolvendo `['success' => true]` → `process()` **completou sem
lançar** (e logou `main.INFO: Envio confirmado pelo provedor.`). **Declaro a limitação: não fiz um envio
real bem-sucedido** — isso exigiria mandar mensagem de verdade para um número humano (proibido pela task).
O que está provado é que o caminho de sucesso não mudou e não lança.

### 4.4 Um erro MEU, registrado

O primeiro script de prova reprovou os 9 casos com `Undefined array key 0`. **O erro era do meu teste**,
não do código: eu montei `$casos` como o próprio retorno em vez do par `[retorno, esperaThrow]`.
Corrigi o script e refiz: 9/9. Mesmo padrão dos instrumentos ruins que o projeto já registrou.

---

## 5. Site e gate

```
home 200 · PLP 200 · PDP 200 · carrinho 200 · login B2B 200
pwsh -NoProfile -File tools\verificar-loja.ps1  ->  33 verificações · 33 PASS · 0 FAIL
```

---

## 6. Estado da fila e o que deixei para trás (declarado)

* **28 mensagens históricas: intactas** (status=4). Não destravei nenhuma — conforme a task.
* **1 mensagem MINHA de teste** ficou em ERROR: `queue_message.id = 6650` / `queue_message_status.id = 6586`.
  **Deixei de propósito como evidência auditável** (o `backend-operacoes`/Lead podem conferir a linha em vez
  de confiar no relatório). Se quiserem remover:
  `DELETE FROM queue_message_status WHERE id = 6586; DELETE FROM queue_message WHERE id = 6650;`
* Nenhuma mensagem foi enviada a cliente. Nenhuma chamada à Z-API foi feita na prova.

---

## 7. Rollback

Backup: **`_backups/whatsapp-success-20261011T042221Z/WhatsAppConsumer.php.orig`** (+ `ANTES.txt`).

```bash
R=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cp -p $R/_backups/whatsapp-success-20261011T042221Z/WhatsAppConsumer.php.orig \
      $R/app/code/GrupoAwamotos/B2B/Model/Notification/WhatsAppConsumer.php
php -l $R/app/code/GrupoAwamotos/B2B/Model/Notification/WhatsAppConsumer.php
bash $R/scripts/opcache-reset.sh
```
(rollback devolve a falha silenciosa: o consumer volta a marcar COMPLETE sem enviar.)

---

## 8. O que NÃO consegui validar / propostas (não aplicadas)

1. **Envio real bem-sucedido** — só por stub (ver §4.3). Exigiria mensagem real a um humano.
2. **As 28 mensagens** — não sei quantas foram entregues de fato; isso só se resolve medindo na Z-API.
   Continua valendo a recomendação do `backend-operacoes`: medir antes de reenviar; **não** reenviar as 21
   de `order_status` de pedidos cancelados.
3. **Teto de retry** — desnecessário neste caminho (§2): `ERROR` é terminal e `RETRY_REQUIRED` não é
   alcançável por `throw` aqui. Se o dono quiser retentativa de falha transitória, é mudança de
   comportamento de fila e precisa de decisão dele.
4. **Consumer de resize no watchdog** — NÃO apliquei (mudança de comportamento em produção), conforme a task.

## 9. Arquivos

* Alterado: `B2B/Model/Notification/WhatsAppConsumer.php` (`7daf7bc7…`, 7.819 bytes).
* Backup: `_backups/whatsapp-success-20261011T042221Z/`
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `wa-recon.sh`, `wa-mq.sh`, `wa-mq2.sh`,
  `wa-retry.sh`, `wa-status.sh`, `wa-sender.sh`, `fix-whatsapp.php`, `fix-whatsapp-aplicar.sh`,
  `wa-prova.php`, `wa-prova1.php`, `wa-consumir.sh`
* `cache:flush` nunca usado. Conexão de escrita do ERP nunca usada.
