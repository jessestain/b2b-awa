# task-74 — AlertProcessor: teto de ocorrências → teto por JANELA DE TEMPO

Autor: dev-backend · 11/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo: `app/code/GrupoAwamotos/LogMonitoring/` + `_backups/`. Tema, `B2B/`, config, schema e ERP: intocados.
Continuação direta da **task-72 §4** (opção **ii**, decidida pelo Lead).

## VEREDITO: **PASS** — guarda removida DEPOIS de provar a contenção. **Sem `setup:di:compile`.**

---

## 1. O diff (1 linha de código + comentário)

`app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php:76` → agora `:89`

```diff
-                if ($alert->getStatus() === 'open' && $alert->getOccurrences() <= 3) {
+                // TETO POR JANELA DE TEMPO — 11/10/2026, task-74.
+                // A condicao "occurrences <= 3" existia para conter o flood ANTES do cooldown.
+                // Depois do dedupe dos analyzers (task-49) ela virou TETO DE OCORRENCIAS e passou
+                // a SILENCIAR incidente longo: ... (comentário completo no arquivo)
+                if ($alert->getStatus() === 'open') {
```

**O que NÃO mudou:** `NOTIFY_COOLDOWN_HOURS = 6` (1 ocorrência no arquivo), `isWithinNotificationCooldown()`
(2 referências), a escrita de `notified_at` e o `save()` — é o cooldown que garante o 1×/6h. Nenhum
construtor mudou: `grep -c 'function __construct'` = **1 → 1** ⇒ **sem `setup:di:compile`**.
sha256 `a2a97ee705c8c987fbb15d0d3fb51ccec64adb6033ea6d3b60afba7cf7f583da`, 4.866 bytes, dono/grupo/modo `1004/33/674`.
Backup: `_backups/alert-window-20261011T050601Z/AlertProcessor.php.orig`.

---

## 2. PRÉ-CONDIÇÃO DO LEAD: a matriz de contenção (feita ANTES de remover)

**Constantes medidas no servidor** (não supostas): cron `*/5` (do `crontab.xml`) ⇒ **12 rodadas/hora,
72 por janela de 6h**; cooldown `NOTIFY_COOLDOWN_HOURS = 6` (do código).

### A matriz — "N alertas abertos × rodadas de 5 min"

| N alertas abertos | rodadas na janela | e-mails/janela **ANTES** (occ≤3 + cooldown) | e-mails/janela **DEPOIS** (só cooldown) | e-mails/hora (pior) |
|---|---|---|---|---|
| 1 | 72 | 1 (e **0** se occ>3) | 1 | 0,17 |
| 3 | 72 | 3 (e 0 se occ>3) | 3 | 0,50 |
| 5 | 72 | 5 (e 0 se occ>3) | 5 | 0,83 |
| 15 (o passivo pré-dedupe) | 72 | 15 (e 0 se occ>3) | 15 | 2,50 |

**As 3 conclusões que sustentam a remoção:**

1. **As rodadas de 5 min NÃO multiplicam.** Com o cooldown, o número de rodadas é irrelevante: cada alerta
   dispara **no máximo 1× por janela de 6h**. (Antes do cooldown eram 13 alertas × 12 rodadas/hora = 247/dia.)
2. **Para todo alerta com `occurrences <= 3`, ANTES = DEPOIS, exatamente.** A guarda removida só *reduzia*
   volume — e o fazia **silenciando**. Logo, removê-la **não pode aumentar** o volume de um incidente novo;
   ela só deixava de silenciar o incidente longo.
3. **N é limitado pelo dedupe (task-49)**: no máximo **1 linha aberta por `(alert_type, severity, source)`**.
   O módulo só pode emitir **6 combos** (lidos no código), dos quais **5 passam** pelo filtro
   `severity IN (critical,high)` do `getCriticalAlerts()`:

| # | alert_type | severity | source | notificável |
|---|---|---|---|---|
| 1 | `nosuchentity_spike` | high | erp_analyzer | sim |
| 2 | `erp_sync_errors` | critical | erp_analyzer | sim |
| 3 | `high_cache_miss_rate` | high | performance_analyzer | sim |
| 4 | `slow_queries_detected` | medium | performance_analyzer | não |
| 5 | `memory_warnings` | critical | performance_analyzer | sim |
| 6 | `agent_termination` | (da classificação) | antigravity | sim |

**⇒ PIOR CASO DEPOIS: 5 linhas notificáveis × 1 por janela = ≤ 5 e-mails/6h = ≤ 20/dia** (teto matemático).
**Medido hoje: 0 alertas abertos ⇒ 0 e-mails/dia.** Comparativo: 247/dia antes do cooldown; 60/dia com o
passivo de 15 duplicatas.

**⇒ O cooldown sozinho CONTÉM o volume. A pré-condição está satisfeita — por isso removi a guarda.**

**Risco residual que declaro:** a guarda removida era também um *backstop* caso o dedupe regredisse (um
escritor novo que sempre cria). Se isso acontecesse, o volume voltaria a escalar com o número de duplicatas
(N×4/dia). Mitigação: o dedupe foi provado empiricamente (task-72: **0 linhas novas** criadas depois do fix
de 10/10 20:33:50) e os 3 escritores do módulo foram auditados (2 corrigidos na task-49, `DiagnosticReportService`
já correto). Mesmo num cenário de regressão, o cooldown continua limitando **cada linha** a 4 e-mails/dia.

---

## 3. PROVA DIRIGIDA — `execute()` REAL, `NotificationService` STUBADO, transação revertida

O instrumento: invoco o **`AlertProcessor::execute()` verdadeiro**, com um `NotificationService` substituído
por uma subclasse anônima que **registra** a chamada e devolve sucesso **sem enviar nada** — e tudo dentro de
uma **transação revertida**, para que nada persista. Ou seja: testa o código de produção, sem risco de e-mail real.

| Cenário | **ANTES** | **DEPOIS** |
|---|---|---|
| (c) passivo real, 0 alertas abertos | 0 notificações | **0** ✓ |
| (b) incidente NOVO (`occ=1`, `notified_at NULL`) | notifica | **notifica** ✓ |
| incidente LONGO (`occ=10`, acima do teto antigo) | **NÃO notifica** (silêncio) | **NOTIFICA** ✓ |
| alerta DENTRO do cooldown (`notified_at` = agora−1h) | não notifica | **não notifica** ✓ (cooldown intacto) |
| total do cenário (1) | **1** | **2** |
| (a) **2ª JANELA** (`notified_at` recuado para agora−7h) | **0** — mudo para sempre | **1 — notifica de novo** ✓ |
| linhas de teste após o ROLLBACK | 0 | **0** ✓ |

Saída literal do DEPOIS:
```
(0) PASSIVO ATUAL: notificacoes disparadas: 0 (esperado 0) | alertas open na tabela: 0
(1) notificacoes: 2  -> MARCA:novo  -> MARCA:longo
    notified_at: longo='2026-10-11 05:06:09' | novo='2026-10-11 05:06:09' | cooldown='2026-10-11 04:06:09'
(2) SEGUNDA JANELA: notificacoes na 2a janela: 1  -> MARCA:longo
    o incidente longo continua aberto? true
    ROLLBACK executado. linhas de teste apos o rollback: 0 | total de alertas: 157
```
O incidente longo era **exatamente** o caso que a guarda antiga matava (o alerta 157 chegou a `occurrences=3`;
o próximo incremento o deixaria mudo para sempre) — agora ele notifica na 1ª **e** na 2ª janela.

---

## 4. Site e gate

```
home 200 · PLP 200 · PDP 200 · carrinho 200 · login B2B 200
pwsh -NoProfile -File tools\verificar-loja.ps1  ->  33 verificações · 33 PASS · 0 FAIL
```

## 5. Rollback

```bash
R=/home/jessessh/htdocs/srv1113343.hstgr.cloud
cp -p $R/_backups/alert-window-20261011T050601Z/AlertProcessor.php.orig \
      $R/app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php
php -l $R/app/code/GrupoAwamotos/LogMonitoring/Cron/AlertProcessor.php
bash $R/scripts/opcache-reset.sh
```
(reverter devolve o silêncio do incidente longo; o flood NÃO volta, porque o cooldown permanece.)

## 6. Achado (B) da task-72 — a proposta fica registrada, NÃO aplicada (conforme sua instrução)

`etc/db_schema.xml`: `<constraint xsi:type="unique" referenceId="UNQ_AWA_LOG_METRICS_LOG_TYPE_SOURCE_CREATED">`
sobre `(log_type, source_file, created_at)` com **`created_at` de resolução de 1 segundo** ⇒ dois INSERTs do
mesmo `(log_type, source_file)` no mesmo segundo colidem por construção. Writers: `ErpAnalyzer.php:212`
(`log_type='erp'`) e `PerformanceAnalyzer.php:231` (`log_type='performance'`), uma linha por arquivo por
análise — a operação normal não colide; a colisão exige **duas execuções no mesmo segundo** (foi o 20:34:33,
pós-deploy). Volume: ~2.791 linhas por par em ~30 dias ≈ 1 a cada 15 min, por isso "quase sempre" passa.

**Proposta para o dono decidir em janela de manutenção:** trocar o `constraint unique` por um `index` com as
mesmas colunas (o `IDX_AWA_LOG_METRICS_LOG_TYPE` já existe) e aplicar com `setup:upgrade`. Argumento extra
para programar a janela: a tabela tem **13.952 linhas e ~5 MB de espaço desperdiçado** — o próprio
monitoramento já sugere `OPTIMIZE TABLE`. `created_at` com 1s não é chave de unicidade válida para série temporal.

## 7. Registro do erro repetido do `*/` (conforme sua instrução)

Na task-72, o script de restauração do `last_occurrence` **não passou no `php -l`** porque escrevi
`resolved_*/notified_at` dentro de um docblock e a sequência **`*/` fechou o comentário** — a **2ª vez** que
caio nisso (a 1ª foi `*/15 min`, na task-49, onde o lint também pegou antes de qualquer escrita).

**Regra que adoto:** em comentário PHP (e em qualquer bloco `/* */`), **nunca deixar a sequência `*/`
aparecer** — nem em `a_*/b`, nem em `resolved_*/x`. Vale para comentários, docblocks e textos embutidos.
É a mesma família do guardrail de `.phtml` do projeto: uma sequência de 2 caracteres que o parser lê como
fechamento de bloco e que o olho humano não vê. Nos dois casos o gate (`php -l`) segurou **antes** de
qualquer arquivo ser escrito — o processo funcionou.

## 8. Arquivos

* Alterado: `LogMonitoring/Cron/AlertProcessor.php` (`a2a97ee7…`, 4.866 bytes) — 1 condição + comentário.
* Backup: `_backups/alert-window-20261011T050601Z/`
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `ap-recon.sh`, `ap-tipos.sh`, `ap-prova.php`,
  `ap-matriz.sh`, `fix-alertprocessor.php`, `fix-alertprocessor-aplicar.sh`
* `cache:flush` nunca usado. Conexão de escrita do ERP nunca usada.
