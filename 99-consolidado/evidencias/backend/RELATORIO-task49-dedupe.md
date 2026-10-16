# task-49 — deduplicação na origem no LogMonitoring (`occurrences` em vez de alerta novo)

Autor: dev-backend · 10/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo usado: **apenas** `app/code/GrupoAwamotos/LogMonitoring/` + `_backups/loganalyzer-dedupe-*`.
**Nada de preço, estoque, pedido, pagamento, mapeamento ERP, config comercial ou schema.**
`AlertProcessor.php` **não foi tocado**. Nenhuma conexão de escrita do ERP. Nenhum commit/push.

## VEREDITO: **PASS** — corrigido, validado por medição dirigida. **Sem `setup:di:compile`.**

---

## 1. O estado real (pior que o diagnóstico)

```
SELECT alert_type, COUNT(*), SUM(occurrences), SUM(status='open') FROM awa_log_alerts GROUP BY alert_type;
nosuchentity_spike | 157 linhas | 157 occurrences | 15 abertas | ids 1..157
```
Todas do mesmo `source=erp_analyzer`, `severity=high`. **157 linhas**, não 13 — o diagnóstico citou as
15 abertas (143..157) e as 142 reconhecidas (`acknowledged`) somavam o resto. Ritmo medido: **1 linha nova
a cada 15 min** (job `awa_log_monitoring_analyze`, `*/15 * * * *`).

Causa confirmada no código: `ErpAnalyzer::createAndSaveAlert()` (`:280`) e
`PerformanceAnalyzer::createAndSaveAlert()` (`:314`) **sempre** faziam `$alertFactory->create()` +
`setOccurrences(1)` — nunca procuravam um alerta aberto existente. O mesmo defeito nos **dois** analyzers.

---

## 2. O diff aplicado

| Arquivo | Mudança |
|---|---|
| `Api/AlertRepositoryInterface.php` | **+** `getLatestOpenAlert(string $alertType, string $severity, string $source): ?AlertInterface` |
| `Model/AlertRepository.php` | implementa: filtro `alert_type`+`severity`+`source`+`status=open`, ordem **`last_occurrence DESC, entity_id DESC`**, `setPageSize(1)` |
| `Service/LogAnalyzer/ErpAnalyzer.php` | `createAndSaveAlert()` consulta antes de criar |
| `Service/LogAnalyzer/PerformanceAnalyzer.php` | idem |

```diff
     private function createAndSaveAlert(string $type, string $severity, string $title, string $message, array $context): void
     {
         try {
+            $existente = $this->alertRepository->getLatestOpenAlert($type, $severity, 'erp_analyzer');
+
+            if ($existente !== null) {
+                $existente->setOccurrences(((int) $existente->getOccurrences()) + 1);
+                $existente->setMessage($message);
+                $existente->setContextData($context);
+                $existente->setLastOccurrence(date('Y-m-d H:i:s'));
+                $this->alertRepository->save($existente);
+
+                return;
+            }
+
             $alert = $this->alertFactory->create();
             ... (caminho antigo, intacto)
```

**Por que NÃO precisou de `setup:di:compile`** (e não rodei nenhum):
* `AlertRepositoryInterface` e `AlertRepository` ganharam **um método**; **nenhum construtor mudou** —
  provado pelo diff de `grep -c 'function __construct'` antes/depois: `1→1` nos 3 que têm, `0→0` na interface.
* `getLatestOpenAlert()` usa o `CollectionFactory` **já injetado** no `AlertRepository` e o
  `alertRepository` **já injetado** nos dois analyzers (confirmado no construtor do `ErpAnalyzer`).
* `AlertRepository` **não tem Interceptor** em `generated/code` (verificado) — não há artefato compilado a regenerar.
* **Prova empírica:** `$om->get(AlertRepositoryInterface::class)` devolveu
  `GrupoAwamotos\LogMonitoring\Model\AlertRepository` e o método novo respondeu — o DI compilado resolve sem recompilar.

Escolhi a **via determinista** (método novo no repositório) em vez do diff sugerido no diagnóstico
(`SearchCriteriaBuilder` no construtor), justamente para **não** exigir `di:compile` numa loja em produção.
A ordenação `entity_id DESC` como desempate é necessária: **todas as 157 linhas têm o mesmo
`last_occurrence`** (a coluna é `ON UPDATE CURRENT_TIMESTAMP` e um reconhecimento em massa as tocou todas),
então ordenar só por `last_occurrence` **não é determinístico**.

---

## 3. `notified_at` — não é tocada

* Nenhum setter de `notified_at` nos arquivos alterados: `grep -rn "setData('notified_at\|setNotifiedAt"` → **NENHUM**.
* O `save()` do repositório grava o valor **carregado** — preserva.
* Prova observada: depois das minhas 2 chamadas (20:35:01) a linha 157 estava com
  `notified_at = NULL`. Às **20:35:04** ela virou `2026-10-10 20:35:04` — **foi o `AlertProcessor`**
  (job `*/5`, código do Lead), não o meu código. Ou seja: o cooldown de 6 h continua funcionando.

---

## 4. VALIDAÇÃO POR MEDIÇÃO

### 4.1 O gatilho natural NÃO disparou — e isso precisa ser dito

`AnalyzerPool::analyzeAll()` rodado 2× deu **0 linhas novas e 0 incrementos**. Isso **não** é a correção
funcionando: é o **gatilho inativo**. Medido:

```
system.log     nosuchentity_errors=0  (limiar do alerta: >10)
exception.log  nosuchentity_errors=0  (limiar do alerta: >10)
```
O pico de `NoSuchEntityException` que gerava as 157 linhas **passou**; hoje o contador está em 0, então
`createAndSaveAlert()` não é chamado. Sem essa checagem eu teria reportado um PASS falso.

### 4.2 Prova dirigida do caminho corrigido (reflexão, 2 chamadas)

Invoquei o **método privado real** `createAndSaveAlert('nosuchentity_spike','high',...)` duas vezes:

```
ANTES                linhas=157 soma_occ=157 id_max=157 occurrences_do_157=1
DEPOIS da chamada 1  linhas=157 soma_occ=158 id_max=157 occurrences_do_157=2
DEPOIS da chamada 2  linhas=157 soma_occ=159 id_max=157 occurrences_do_157=3
linhas novas (id > 157) = 0
```
**2 invocações → 2 incrementos na MESMA linha, ZERO linhas novas.** Com o código antigo isso seriam
**159 linhas** com `occurrences=1` cada (o comportamento medido nas 157 existentes).
Controle negativo do método novo: `getLatestOpenAlert('tipo_que_nao_existe','high','erp_analyzer')` → `NULL`
(ou seja, tipo novo ainda cria alerta — a dedupe não engessa o primeiro alerta de um problema novo).

**Limpeza do artefato:** a chamada de teste sobrescreveu `message`/`context_data` da linha 157 (o dedupe
atualiza esses campos de propósito). Restaurei os valores originais
(`Detected 64 NoSuchEntityException errors in exception.log` / `{"file":"exception.log","count":64}`),
reconstruídos das linhas 155/156 que têm conteúdo idêntico. `occurrences=3` foi mantido (é a evidência).

---

## 5. Site e gate

```
home      200  https://awamotos.com/
PLP       200  https://awamotos.com/retrovisores.html
PDP       200  https://awamotos.com/bauleto-awa-modelo-proos-29-litros-prata-290-pr.html
carrinho  200  https://awamotos.com/checkout/cart/

pwsh -NoProfile -File C:\Users\espel\AWA-Motos\tools\verificar-loja.ps1
  -> 33 verificações · 33 PASS · 0 FAIL   (exit 0)
```

---

## 6. Dois pontos que o Lead precisa saber (não são regressão, são consequência/achado)

**(a) Interação dedupe × `occurrences <= 3` no `AlertProcessor`.** O consumidor só notifica enquanto
`status='open' AND occurrences <= 3`. Com o dedupe, uma ocorrência contínua agora **soma** no mesmo alerta:
ele notifica nas occurrences 1-3 (respeitando o cooldown de 6 h) e depois **fica silencioso, embora siga
aberto e visível no painel**. Antes, cada rodada criava uma linha nova com `occurrences=1`, que era
eternamente elegível a notificar — era isso que alimentava o flood. A mudança é no sentido que você pediu,
mas **muda a semântica de notificação**: incidente longo passa a avisar no máximo ~3 vezes. Não toquei no
`AlertProcessor` (fora do escopo e já validado por você).

**(b) Linhas legadas continuam notificáveis.** As 15 linhas abertas antigas (143-157) permanecem com
`occurrences=1`, então **elas** seguem elegíveis (1 notificação a cada 6 h cada, teto do seu cooldown).
O dedupe impede **novas** linhas; não resolve o passivo. Hygiene possível (fora do meu escopo e não
autorizada): reconhecer/resolver as 14 que não representam o incidente atual. Hoje o pico passou
(`nosuchentity_errors=0`), então na prática elas tendem a envelhecer sem novos incrementos.

**(c) Observação fora do escopo (pré-existente, não é minha):** duas URLs de produto que **aparecem na
busca** devolvem 404 no PDP:
`/protetor-capa-da-corrente-titan-150-04-13-e-fan-150-09-13-prata-0047-pr.html` (id 1626) e
`/bauleto-awa-modelo-proos-26-litros-prata-260-pr.html` (id 1813) — ambas com `url_rewrite` em `store_id=1`
e `redirect_type=0`. Não tem relação com alertas (minha mudança é só persistência de alerta, executada por
cron/CLI) e o gate está 33/33. Vale virar tarefa própria.

---

## 7. Rollback

Backup: **`_backups/loganalyzer-dedupe-20261010T203350Z/`** (`cp -p`, com os 4 originais).

```bash
B=<root>/_backups/loganalyzer-dedupe-20261010T203350Z
M=<root>/app/code/GrupoAwamotos/LogMonitoring
cp -p "$B/Api__AlertRepositoryInterface.php"              "$M/Api/AlertRepositoryInterface.php"
cp -p "$B/Model__AlertRepository.php"                     "$M/Model/AlertRepository.php"
cp -p "$B/Service__LogAnalyzer__ErpAnalyzer.php"          "$M/Service/LogAnalyzer/ErpAnalyzer.php"
cp -p "$B/Service__LogAnalyzer__PerformanceAnalyzer.php"  "$M/Service/LogAnalyzer/PerformanceAnalyzer.php"
php -l "$M/Service/LogAnalyzer/ErpAnalyzer.php"    # e os outros 3
bash scripts/opcache-reset.sh
```

## 8. Comandos usados (reprodutíveis)

```bash
php /tmp/fix-dedupe.php            # dry-run: 4 ancoras exatas + php -l em memoria, sem escrever
bash /tmp/fix-dedupe-aplicar.sh    # backup -> aplica -> php -l -> SO ENTAO opcache-reset
php /tmp/dedupe-medir.php 2        # medicao pelo AnalyzerPool (caminho que persiste)
php /tmp/dedupe-prova.php          # prova dirigida por reflexao (2 chamadas)
php /tmp/dedupe-limpar.php         # restaura message/context_data do artefato de teste
```

## 9. Um erro meu, registrado

O primeiro dry-run **abortou** porque escrevi `*/15 min` dentro de um comentário de bloco PHP — o `*/`
**fecha o comentário** e o `php -l` acusou `syntax error, unexpected identifier "min"`. Foi o gate de lint
do próprio script que pegou, **antes de qualquer escrita**. Corrigi o texto do comentário. É a mesma
família do guardrail de `.phtml` do projeto: conteúdo de comentário não é inerte.

## 10. Arquivos

* Alterados: os 4 acima. `sha256` finais —
  `iface eb7c8309…` · `repo da9e9819…` · `erp 608a146a…` · `perf c1956c6f…`; dono/grupo/modo `1004/33/674` preservados.
* Backup: `_backups/loganalyzer-dedupe-20261010T203350Z/`
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `fix-dedupe.php`, `fix-dedupe-aplicar.sh`,
  `dedupe-antes.php`, `dedupe-open.php`, `dedupe-medir.php`, `dedupe-prova.php`, `dedupe-limpar.php`, `achar-pdp.php`
* `cache:flush` **nunca** usado. `setup:di:compile` **não** executado (desnecessário, provado).
