# task-72 — consolidar o passivo de alertas (dedupe sem consolidação) + diagnóstico do unique constraint das métricas

Autor: dev-backend · 11/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo usado: `app/code/GrupoAwamotos/LogMonitoring/` + `_backups/`. Nada de tema, `B2B/`, config, ERP.
`AlertProcessor.php` **NÃO foi alterado**. Schema **NÃO foi alterado** (só proposto).

## VEREDITO: **PASS** — passivo consolidado, recorrência coberta, achado (B) diagnosticado e proposto.

---

## 1. O passivo: 15 linhas `open` para UM incidente

Estado medido antes de agir:

```
nosuchentity_spike | high | erp_analyzer | acknowledged | n=142  occ=142
nosuchentity_spike | high | erp_analyzer | open         | n=15   occ=17   ids 143..157
MD5(message) das 15 -> c25d16f621100125493a12120683c249 (as 15 IDÊNTICAS)
```
E a prova de que o cooldown só espaça o lote: `notified_at` mostra **13 carimbos entre 02:15:07 e 02:15:16**,
mais 156 às 02:20:06 e 157 às 02:40:05 — exatamente o que o QA mediu. A 157 já estava com `occurrences=3`.

O incidente **não está vivo**: `NoSuchEntity` no último 200 KB = **0** em `system.log` e `exception.log`.

### Critério da consolidação (escolhido e explicado)

* **Chave do grupo:** `(alert_type, severity, source)` com `status='open'` e `COUNT(*) > 1` — a mesma chave
  que o dedupe da task-49 usa.
* **Canônica:** `MAX(entity_id)` (157) — determinístico e coerente com o critério do dedupe
  (`last_occurrence DESC, entity_id DESC`).
* **A canônica recebe a SOMA** das `occurrences` do grupo → **17** (o total real; nada de história perdida).
* **As 15 viram `resolved`** (`resolved_at`, `resolved_by='task-72-consolidacao'`).

**Por que resolvi a canônica TAMBÉM (e não a deixei `open`, como o exemplo do enunciado):**
o `AlertProcessor` só notifica `status='open' AND occurrences <= 3`. Uma canônica aberta com `occurrences=17`
ficaria em **silêncio permanente** — e pior: o dedupe da task-49 casa por `status='open'`, então uma
**recorrência incrementaria essa mesma linha para sempre, sem nunca notificar**. Eu teria trocado um flood
de 15 e-mails por uma **falha silenciosa nova**, que é justamente a família de bug que este projeto vem
fechando. Resolvendo todas, a próxima ocorrência não encontra alerta aberto ⇒ nasce **UMA** linha nova
(`occurrences=1`, `notified_at NULL`) ⇒ **UMA** notificação. Isso é o alvo do QA: "1, não 15".

### Antes → depois

```
ANTES                                      DEPOIS
open: 15 linhas (occ 17)                   open: 0 linhas
notificações por janela de 6h: 15          notificações do passivo: 0
                                           canônica 157: resolved, occurrences=17
```
Backup das 15 linhas (SQL + CSV + plano): `_backups/logalert-consolidacao-20261011T045555Z/`.
`notified_at` foi **preservado** em todas (02:15:07-16 / 02:20:06 / 02:40:05).

**Correção de fidelidade que eu mesmo fiz:** a consolidação bumpou `last_occurrence` das 15
(a coluna é `ON UPDATE CURRENT_TIMESTAMP`, mesmo eu reescrevendo o valor carregado). Restaurei os
valores originais (20:11:04 / 20:15:05 / 20:35:29) do backup — `last_occurrence` é "quando o alerta foi
visto", e a consolidação não é uma ocorrência. Não toquei em status/occurrences/resolved_at/notified_at.

---

## 2. PROVA DIRIGIDA

**(1) Um lote de N linhas do mesmo tipo gera 1 notificação, não N** — avaliando a **mesma** condição do
`AlertProcessor` (`getCriticalAlerts()` real + `status==='open'` + `occurrences <= 3` + cooldown de 6h):

```
ANTES  (as 15 linhas, reconstruídas do backup):
  open com occurrences <= 3 (elegíveis no próximo ciclo pós-cooldown): 15 -> ids 143..157
  => 15 e-mails por janela de 6h

DEPOIS (real, via getCriticalAlerts() do próprio serviço):
  notificáveis AGORA (cooldown valendo): 0
  notificáveis no próximo ciclo (cooldown expirado): 0
  => 15 -> 0
```

**(2) Um problema NOVO continua gerando alerta** — dentro de uma **transação revertida** (nada persiste,
nenhum e-mail real pode sair):
```
alerta sintético inserido (id=158, severity=high, occurrences=1, notified_at=NULL)
o AlertProcessor o veria? true (candidatos agora: 1)
o dedupe o encontra (2ª ocorrência incrementaria em vez de duplicar)? true
ROLLBACK executado -> linhas do tipo sintético APÓS o rollback: 0
```

---

## 3. Requisito 2 — o problema não volta?

**(a) Empiricamente: ZERO linhas novas de alerta depois do fix da task-49.**
```
maior entity_id = 157 | maior created_at = 2026-10-10 20:30:05   (o fix entrou 20:33:50)
linhas criadas DEPOIS do fix da task-49 = 0
```
Ou seja: as 8 duplicatas apontadas pelo QA são todas **anteriores** ao fix, e as ocorrências seguintes
foram absorvidas pela MESMA linha (157 foi de 1 para 3). ☑

**(b) Varredura dos caminhos que criam alerta no módulo** — três escritores:

| Caminho | Veredito |
|---|---|
| `Service/LogAnalyzer/ErpAnalyzer.php:297/302` | corrigido na task-49 (dedupe) ☑ |
| `Service/LogAnalyzer/PerformanceAnalyzer.php:331/336` | corrigido na task-49 (dedupe) ☑ |
| **`Service/AgentTermination/DiagnosticReportService.php:130/134`** | **NÃO tinha o defeito** — já deduplica |

O terceiro (`persistAlert()` → `findMatchingOpenAlert()`, linhas 115-147 + 153-182) casa por
`classification.code` + `exact_error_message` + `model` + `workflow`, pula `resolved`, e **incrementa**
`occurrences` em vez de inserir — está correto e **não foi tocado**. Não sobrou caminho defeituoso.

---

## 4. Requisito 3 — `AlertProcessor` não foi alterado

Nenhum arquivo além dos scripts de evidência foi editado nesta task: **zero mudanças em `app/code`**.
O cooldown de 6h e a coluna `notified_at` seguem exatamente como você corrigiu e o QA validou.

**Um ponto que precisa da sua decisão (não apliquei):** a guarda `occurrences <= 3` do `AlertProcessor`
foi pensada para conter o flood *antes* do dedupe. Com o dedupe no lugar, ela passou a ter um efeito
colateral: **um incidente contínuo notifica no máximo 3 vezes e depois fica mudo para sempre**, porque as
ocorrências somam na mesma linha. Prova disso está na própria 157: `occurrences` chegou a 3 e o próximo
incremento a levaria a 4 → silêncio permanente, sem alerta novo. A consolidação que fiz **contorna isso
para o passivo** (não deixa nenhuma linha aberta), mas **não conserta o caso geral** de um incidente novo
que se estenda por 4+ ciclos. Opções, para você escolher:
* **(i)** manter como está — incidente longo fica visível no painel mas para de notificar (é o desenho atual);
* **(ii)** trocar a guarda por um teto por **janela de tempo** (ex.: notificar no máximo 1×/6h sem teto de
  ocorrências) — é a mudança que faz o comportamento bater com a intenção do dedupe;
* **(iii)** resetar `occurrences` ao resolver/encerrar cada incidente (não resolve o incidente longo).
Não implementei nenhuma — é o mesmo arquivo que você corrigiu e a decisão é sua.

---

## 5. PROBLEMA 2 (achado B) — unique constraint das métricas: DIAGNOSTICADO

`app/code/GrupoAwamotos/LogMonitoring/etc/db_schema.xml`:

```xml
<column xsi:type="timestamp" name="created_at" nullable="false" default="CURRENT_TIMESTAMP" .../>
<constraint xsi:type="unique" referenceId="UNQ_AWA_LOG_METRICS_LOG_TYPE_SOURCE_CREATED">
    <column name="log_type"/>
    <column name="source_file"/>
    <column name="created_at"/>
</constraint>
```
`created_at` é `timestamp` — **resolução de 1 segundo**. Dois INSERTs com o mesmo
`(log_type, source_file)` **no mesmo segundo** colidem por construção.

Quem grava (só dois writers):
* `ErpAnalyzer.php:212` → `log_type='erp'` (arquivos `exception.log`, `system.log`);
* `PerformanceAnalyzer.php:231` → `log_type='performance'` (`debug.log`, `performance_monitor.log`, `system.log`).

Cada análise grava **uma linha por arquivo**, todas dentro do mesmo segundo — mas com `source_file`
diferente, então **a operação normal não colide**. A colisão exige **duas execuções da mesma análise no
mesmo segundo** (execução concorrente/duplicada): foi o que aconteceu às **20:34:33**, logo após o deploy
da task-49 (reset de OPcache + cron). Volume real: ~2.791 linhas por par `(log_type, source_file)` em ~30
dias = **~1 a cada 15 min** (o `*/15` do cron) — por isso 1 segundo de resolução "quase sempre" passa.

**PROPOSTA (não aplicada — é schema):** remover a unicidade e manter apenas índice. No
`db_schema.xml`, trocar
```xml
<constraint xsi:type="unique" referenceId="UNQ_AWA_LOG_METRICS_LOG_TYPE_SOURCE_CREATED">…</constraint>
```
por um `<index referenceId="IDX_...">` com as mesmas colunas (o índice `IDX_AWA_LOG_METRICS_LOG_TYPE` já
existe), e aplicar com `setup:upgrade` em **janela de manutenção**. Justificativa: `created_at` com 1s de
resolução **não é chave de unicidade válida** para uma tabela de série temporal; a unicidade não protege
nada que o `PRIMARY KEY` já não proteja. Nota: a tabela tem 13.952 linhas e ~5 MB de espaço desperdiçado
(o próprio monitoramento já sugere `OPTIMIZE TABLE`) — outra razão para programar a janela.

Não reencontrei as 5 linhas exatas do erro nos logs atuais (já rotacionaram) — a prova do diagnóstico é o
schema acima, que é determinístico. Declaro isso em vez de citar uma linha que não consegui reler.

---

## 6. Site e gate

```
home 200 · PLP 200 · PDP 200 · carrinho 200 · login B2B 200
pwsh -NoProfile -File tools\verificar-loja.ps1  ->  33 verificações · 33 PASS · 0 FAIL
```

## 7. Rollback

Backup: **`_backups/logalert-consolidacao-20261011T045555Z/`** (`awa_log_alerts-afetadas.sql` com as 15
linhas originais, `.csv`, `PLANO.txt`).

```bash
# restaura status/occurrences/last_occurrence/notified_at das 15 linhas
mysql < _backups/logalert-consolidacao-20261011T045555Z/awa_log_alerts-afetadas.sql   # INSERTs
# (as linhas ainda existem: use UPDATE a partir do CSV, ou DELETE + INSERT do .sql)
```
O arquivo `.sql` traz os `INSERT` das 15 linhas como estavam antes — o caminho mais seguro é
`DELETE FROM awa_log_alerts WHERE entity_id BETWEEN 143 AND 157;` seguido do `.sql`.
**Nenhuma linha foi apagada**; todas as 157 continuam na tabela (142 acknowledged + 15 resolved).

## 8. Um erro meu — repetido

O script de restauração do `last_occurrence` **não passou no `php -l`**: escrevi
`resolved_*/notified_at` num docblock e o **`*/` fechou o comentário**. É a **segunda vez** que eu caio
nessa (na task-49 foi `*/15 min`). Corrigi e rodei. Fica registrado como regra minha: **em comentário PHP,
nunca deixar a sequência `*/` aparecer** — nem em `a_*/b`.

## 9. O que NÃO consegui validar

1. **As 5 linhas exatas do `Unique constraint violation`** — rotacionaram; o diagnóstico se sustenta no
   schema (determinístico) e no padrão de escrita medido.
2. **A notificação real de um problema novo** — provei por transação revertida (o QA/Owner não deve receber
   um e-mail de teste). O caminho está provado; o e-mail de verdade virá no primeiro incidente real.
3. **O caso geral do incidente longo** (§4) — depende de decisão sua sobre o `occurrences <= 3`.

## 10. Arquivos

* **Nenhum arquivo em `app/code` foi alterado nesta task** (só dados + scripts de evidência).
* Backup: `_backups/logalert-consolidacao-20261011T045555Z/`
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `cons-recon.sh`, `cons-recon2.sh`,
  `cons-recon3.sh`, `consolida-alertas.php`, `cons-prova.php`, `cons-final.sh`, `cons-fix-last.php`
* `cache:flush` nunca usado. Conexão de escrita do ERP nunca usada.
