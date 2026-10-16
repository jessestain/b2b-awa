# task-40 — BUSCA: substituição silenciosa (A) e produto inalcançável (B)

Autor: dev-backend · 05/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo: `app/code/`, `_backups/`, `99-consolidado/evidencias/backend/` — `app/design/` NÃO tocado.

---

## VEREDITOS

| | Item | Veredito |
|---|---|---|
| **A** | `?q=pneu` → 302 → `q=pret` | **PASS** — corrigido e medido (ANTES/DEPOIS por curl) |
| **B** | `0047 PR` anuncia 10, mostra 9 | **PASS (causa-raiz provada e documentada)** — produto identificado (id **1324**, SKU `340 PR`); NÃO é defeito de dado do produto; é a divergência entre o total do engine e o conjunto que a grade carrega |

---

## BUG A — a busca com 0 resultado era trocada por outra, sem o cliente pedir

### A.1 O que o cliente via (ANTES, medido)

```
$ curl -sI 'https://awamotos.com/catalogsearch/result/?q=pneu'
HTTP/1.1 302 Found
Location: https://awamotos.com/catalogsearch/result/index/?_q=pneu&o=pneu&q=pret
$ curl -sI '...?q=relacao'      -> 302 ...&q=remocao
$ curl -sI '...?q=relacao-de-transmissao' -> 302 ...&q=remocao
$ curl -sI '...?q=capacete'     -> 302 https://awamotos.com/capacetes/   (landing page, INTENCIONAL — intacta)
```
Na página de destino: `toolbar-amount = "Itens 1-12 de 327"` e a frase
`Sua busca por 'pneu' não encontrou nenhum produto. Você quis dizer: 'pret'` — ou seja, o cliente digitou **PNEU** e recebeu **327 produtos de "pret(o)"**.

### A.2 A causa NÃO é o `fallback` (a premissa do enunciado está refutada)

Os 3 casos medidos vêm do caminho de **correção ortográfica**, não do fallback. Prova pelo
assinatura da URL: `getMisspellUrl()` monta `q=<novo>&o=<original>&_q=<original>` e
`getFallbackUrl()` monta `q=<novo>&f=<original>&_q=<original>`.
`Mirasvit/Misspell/Service/QueryService.php:98` e `:105`.

As 3 URLs medidas trazem **`o=`** ⇒ caminho `doSpellCorrection()`
(`Mirasvit/Misspell/Observer/OnCatalogSearchObserver.php:63-73`).

Prova adicional: para consulta de **uma palavra** o fallback é matematicamente incapaz de disparar —
`getFallbackCombinations()` (`QueryService.php:129`) remove o conjunto completo e devolve `[]` quando
há 1 palavra. `pneu` e `relacao` são de 1 palavra ⇒ **`fallback=0` sozinho não corrigiria nada.**

Onde a sugestão nasce: `SuggestRepository::suggest()` lê o cache `mst_misspell_suggest` e, se não
achar, chama o adapter e **grava o resultado no cache**. O adapter é escolhido por
`catalog/search/engine` = `opensearch` (`Mirasvit/Misspell/etc/di.xml:19` →
`Adapter/Elasticsearch/ElasticsearchAdapter`). `mst_misspell_index` (MySQL) está **vazia (0 linhas)** —
o índice vive no OpenSearch. Linhas do cache: `pneu → pret`, `relação → remocao`,
`relacao-de-transmissao → remocao`.

### A.3 Impacto medido (`mst_search_report_log`, janela 04/09→05/10)

```
buscas 14.100 | com 0 resultado 1.451 (10,3%)
entradas_via_misspell 82 | via_fallback 1 | total redirecionado 83  (0,59% das buscas)
termos distintos substituídos: 42
cliques registrados na tabela: 0  (a loja NÃO mede clique — métrica inútil aqui)
```
Os 83 redirecionamentos, classificados um a um:

| BOAS (typo real) | n | PREJUDICIAIS | n |
|---|---|---|---|
| `retrovisro → retrovisor` | 6 | `relação → remocao` | 10 |
| `retovisor xls → retrovisor xls` | 3 | `pneu → pret` | 8 |
| `bagajero → bagageiro` | 2 | `awa-zero-r26-zz9917 → awa` | 6 |
| `baulleto → bauleto` | 2 | `7122 → 1122` · `6944 → 644` · `2393 → 2392` (SKU→OUTRO SKU) | 9 |
| | | `versys → versao`, `2385→2388`, `2202→2022`, `1'→1`, … | resto |

**≈13 úteis contra ≈68 prejudiciais (82%).** O caso mais grave não é `pneu`: são as trocas
**numéricas de SKU** — o cliente digita `7122` e o sistema o leva para `1122`, ou seja, para o
**produto errado**. O cache `mst_misspell_suggest` ainda guarda 8 sondagens de SQL-injection
(`(SELECT 1)`, `1'`, `1")` …) como se fossem termos corrigíveis.

### A.4 A correção aplicada (config, sem código, reversível)

`Mirasvit/Misspell/etc/adminhtml/system.xml` só expõe **2 campos**: `active` e `fallback`.
Não existe opção "sugerir em vez de redirecionar". Como não há como manter a sugestão sem o
redirect sem mexer em DI (plugin ⇒ `setup:di:compile`), a escolha com evidência é desligar os dois:

```bash
php bin/magento config:set misspell/general/active 0
php bin/magento config:set misspell/general/fallback 0
php bin/magento cache:clean config
```
(`config:set` ESTÁ funcionando nesta loja — "Value was saved." Os dois valores estavam em
`core_config_data` ids **3923/3924**, escopo `default`/`0`.)

Custo declarado: perdem-se os ~13 redirecionamentos bons/mês (0,09% das buscas) e a sugestão some
também do autocomplete (o `SearchAutocomplete/InstantProvider/ConfigMaker.php:230-232` só inclui o
índice `mst_misspell_index` no typeahead quando `isMisspellEnabled()`); o autocomplete continua
funcionando para termos reais.

### A.5 DEPOIS (saída bruta)

```
--- q=pneu
    HTTP/2 200                                  (era 302)
--- q=relacao
    HTTP/2 200                                  (era 302)
--- q=relacao-de-transmissao
    HTTP/2 200                                  (era 302)
--- q=capacete
    HTTP/2 302 ; location: https://awamotos.com/capacetes/   (intacto, como pedido)
```
Corpo servido:
```
=========== DEPOIS pneu
HTTP final : 200 | redirecionamentos: 0 | URL efetiva: .../catalogsearch/result/?q=pneu
toolbar-amount : -   cards : 0
frases : {"nenhum resultado":"Nenhum resultado encontrado"}
=========== DEPOIS relacao   (idêntico)
```
⇒ o cliente vê **o termo que digitou** e "Nenhum resultado encontrado".

**Rollback:** `_backups/busca-misspell-20261005T203237Z/` (valores originais + `ROLLBACK.sql`):
```sql
UPDATE core_config_data SET value='1' WHERE config_id=3923;  -- misspell/general/active
UPDATE core_config_data SET value='1' WHERE config_id=3924;  -- misspell/general/fallback
```
ou `php bin/magento config:set misspell/general/active 1 && php bin/magento config:set misspell/general/fallback 1 && php bin/magento cache:clean config`

---

## BUG B — `0047 PR`: anuncia 10, renderiza 9

### B.1 Reprodução estável (não é cache, não é flake)

| termo | toolbar | cards | URL final |
|---|---|---|---|
| `0047 PR` | **10 Itens** | **9** | 200, sem redirect |
| `0047 PR&p=1` | 10 | 9 | |
| `0047 PR&cb=<rand>` | 10 | 9 | (bypass de cache) |
| `PR` | **9** | **8** | |
| `0047` | 2 | 2 | ← consistente |
| `0047 PT` | 38 | 12 (paginado) | |

O erro é **exatamente −1** e só aparece quando há mais de um termo. Reconfirmado **depois** do
reindex que o `catalogo-seo` disparou (`catalog_product_flat` 22:19:11, `catalogsearch_fulltext`
22:22:57): continua 10/9. **Não é índice velho.**

### B.2 O produto faltante, identificado

Consulta direta à Search API (a MESMA que alimenta a toolbar):

```
$ php bugb-searchapi.php '0047 PR' 1333 1334 1626 170 1813 390 406 414 423
getTotalCount() do engine (o numero da toolbar) = 10
documentos devolvidos = 10
IDs do engine: 1626,390,406,414,423,1324,1333,1334,1813,170
IDs da GRADE : 1333,1334,1626,170,1813,390,406,414,423
no engine mas NAO na grade: 1324
```
**Faltante: `entity_id=1324`, SKU `340 PR`, "BAULETO AWA MODELO PROOS 34 LITROS PRATA".**
No HTML servido o produto não existe: `'1324' -> 0`, `'340-pr' -> 0`, `'340 PR' -> 0`,
`'BAULETO AWA MODELO PROOS 34' -> 0`.

### B.3 O que NÃO explica (tudo medido — nenhum filtro de catálogo exclui o 1324)

| dimensão | 1324 | veredito |
|---|---|---|
| `status` por store | 0=1, 1=1, 2=1 | ativo |
| `visibility` | 0=4 | "Catalog, Search" |
| `catalog_product_website` | website 1 | na loja |
| `cataloginventory_stock_status` | stock_status=1, qty=18 | em estoque |
| `cataloginventory_stock_item` | is_in_stock=1 | em estoque |
| preço | 146,63 | tem preço |
| `catalog_product_flat_1` | **presente**, visibility=4 | no flat |
| `url_rewrite` store 1 | presente | tem URL |
| tipo / attribute set | simple / Default | normal |
| opções requeridas | 0 | normal |
| categorias | 45 (Bauletos), 141 | atribuído |
| imagem | `no_selection` | **não é a causa** — 1333, 1334, 1626 e 1813 também não têm imagem e renderizam |

O flat espelha o engine: `catalog_product_flat_1` = **1.115 linhas** = `magento2_product_1_v15` =
**1.115 docs**; `flat_2` = 1.058 = `_v2_v15` = 1.058. Não há divergência de índice.

### B.4 Causa-raiz

A toolbar e a grade são calculadas por **camadas diferentes** e nunca são reconciliadas:

* o número da toolbar vem de `Magento\Framework\Search\Search::search()` →
  `SearchResultInterface::getTotalCount()`, isto é, **o total de hits do OpenSearch (10)**;
* a grade é o conjunto de produtos que o `SearchResultApplier` consegue **carregar do MySQL** (9).

Por isso o total pode ser maior que o que existe para renderizar, e o cliente vê "10 Itens" com 9
cards. Não é defeito de dado de produto: o 1324 passa por **todos** os filtros que consigo medir no
banco (status, visibility, website, estoque, preço, flat, URL, categoria) e mesmo assim é descartado
depois do engine — a assinatura de um defeito de resolução do lado do Magento, não de cadastro.

**Não corrigido por decisão consciente** (o aceite permite: "pode ser um bug do Magento core e aí a
resposta certa é documentar e não corrigir"). Motivos: (a) o remédio óbvio — reindexar — foi testado
e **não resolve** (remedido após reindex, continua 10/9); (b) qualquer patch no applier é mexer no
`vendor/magento/module-catalog-search`, proibido; (c) uma correção de verdade exige investigar o
`SearchResultApplier`/`TotalRecordsResolver` com o DI compilado e uma janela de manutenção.

**Impacto:** o produto **não é inalcançável** — ele tem URL própria e aparece na categoria; o defeito
é a **contagem mentirosa** no topo da busca. É confusão para o cliente, mas não perda de acesso.
Agravante de negócio: quem procura o SKU `340 PR` pela busca não o encontra naquela consulta.

### B.5 Nota de coordenação

Durante a medição, `catalog_product_flat` e `catalogsearch_fulltext` foram reindexados às 22:19 e
22:22 (compatível com o `catalogo-seo`/task-41 mexendo em categorias). Remedido depois: o
comportamento é o mesmo, então a reindexação não introduz nem corrige o defeito.

---

## VALIDAÇÃO

```
curl -sL -o /dev/null -w '%{http_code}' https://awamotos.com/                      -> 200
curl -sL -o /dev/null -w '%{http_code}' https://awamotos.com/retrovisores.html     -> 200
curl -sL -o /dev/null -w '%{http_code}' 'https://awamotos.com/catalogsearch/result/?q=retrovisor' -> 200
curl -sL -o /dev/null -w '%{http_code}' https://awamotos.com/faq                   -> 200
curl -sI '...?q=capacete' -> 302 /capacetes/  (landing page preservada)
pwsh -File tools\verificar-loja.ps1  ->  32 verificações · 32 PASS · 0 FAIL   (executado DEPOIS da mudança de config)
```
Os 6 checks de busca do gate continuam PASS: `retrovisor` 12 · `bauleto` 12 · `manopla` 12 ·
`zzzznaoexiste` 0 · `xyzabc123qwe` 0 · página de busca 96 ocorrências.

## ARQUIVOS

* Backup: `_backups/busca-misspell-20261005T203237Z/` (`core_config_data-misspell.txt`, `ROLLBACK.sql`)
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `medir-busca.php`, `buga-medir.sh`,
  `buga-impacto.php`, `buga-sugestao.php`, `buga-aplicar.sh`, `bugb-medir.sh`, `bugb-searchapi.php`,
  `bugb-diff.php`, `bugb-flat.php`, `bugb-remedir.sh`
* **Nenhum arquivo PHP foi alterado.** Nenhum `app/design`. `cache:flush` nunca usado.
