# RELATÓRIO — os 115 produtos desativados: status no ERP Sectra

Pergunta do dono: *** os 115 produtos desativados no Magento estão ATIVOS ou INATIVOS no ERP? ***
Autor: dev-backend · 11/10/2026 · **SOMENTE LEITURA** — nenhum `UPDATE`/`INSERT` no Magento ou no ERP,
nenhum `cache:flush`, nenhuma alteração de arquivo de produção. Conexão de **leitura** do ERP.
CSV para o dono: **`lista1-115-com-status-erp.csv`** (mesma pasta).

---

## 1. RESPOSTA DIRETA

```
ATIVOS no ERP ......... 50   (43%)
INATIVOS no ERP ....... 44   (38%)
NÃO EXISTEM no ERP .... 21   (18%)
indeterminados ........  0
                       ---
                       115
```

**MAS a contagem de 50 "ativos" NÃO significa "reativar 50".** Ao cruzar com a segunda coluna que o ERP
usa para definir o que é vendável (`CKCOMERCIALIZA`), os 50 se dividem — e a divisão é o que decide:

| Situação | Qtd | O que significa |
|---|---|---|
| `CCKATIVO='S'` **+ `CKCOMERCIALIZA='S'`** + preço R$ 0,01 | **20** | **São de fato "para vender"** — mas o preço é placeholder e o guard de reativação os bloqueia |
| `CCKATIVO='S'` + **`CKCOMERCIALIZA='N'`** | **30** | Ativos no cadastro, **mas o próprio ERP não os comercializa** ⇒ fora do escopo ativo |
| `CCKATIVO='N'` | 44 | **Inativos no ERP ⇒ o Magento está CERTO**; o estoque do Magento é resíduo |
| sem linha em `MT_MATERIAL` | 21 | Não existem no ERP (SKUs antigos/locais: 23, 37, 44, 54, 75, 33-49…) |
| **`ATIVO` + comercializa + preço utilizável, e ainda desativado** | **0** | *** Nenhum caso inexplicado *** |

*** O resultado mais importante: ZERO produtos estão "ativos e comercializados, com preço válido, e ainda
desativados na loja". *** Ou seja, o estado do Magento é **consistente com as regras** — não há um lote de
produtos vendáveis escondidos por acidente.

---

## 2. O que isso responde, na prática

* *** Para os 44 INATIVOS no ERP: o Magento está certo. *** O caminho é **limpar o estoque**, não reativar.
* *** Para os 20 ATIVOS+comercializados: NÃO é decisão comercial — é o preço. *** O
  `InactiveProductReactivationGuard` (`ERPIntegration/Model/Product/`) bloqueia reativação de produto com
  preço de placeholder (piso R$ 1,00) e o docblock da própria classe registra o mesmo padrão:
  *"Medido: 21 dos 27 tinham exatamente R$ 0,01 no ERP. Vender a R$ 0,01 é prejuízo certo."*
  ⇒ **o guard está fazendo o trabalho dele.** Para esses 20 venderem, alguém tem que **preencher o preço**.
* *** Para os 30 `CKCOMERCIALIZA='N'`: não são candidatos a reativação *** — o escopo ativo do sync é
  `CCKATIVO='S' AND CKCOMERCIALIZA='S'`. O ERP não os comercializa; se for para vender, a decisão é no ERP.
* *** Para os 21 que não existem no ERP: *** são SKUs que o ERP não conhece. **Não é possível responder pelo
  ERP** — precisam de decisão de cadastro (item morto? SKU local nunca sincronizado?).

### O caso nº 1 da lista — `20050058` (POLAINA TWISTER, 32.872 un.)

```
MT_MATERIAL : CCKATIVO='S'  CKCOMERCIALIZA='S'  CCKSTATUS='S'
              editado em 2026-05-15 14:48:10 por RAFAEL SANTOS
MT_ESTOQUE  : AL/filial 1 = 0 | AL/filial 2 = 17.452          (2 linhas, sem somar nada)
Magento     : qty = 32.872     preço = R$ 0,01
MT_MATERIALLISTA (preço): NENHUMA LINHA para este material
```
⇒ Está **ATIVO e comercializado no ERP**, com **17.452** em estoque real, **sem preço cadastrado** no ERP e
R$ 0,01 no Magento. **É o item mais valioso da lista e está travado por falta de preço — não por decisão
comercial.** A quantidade do Magento (32.872) é **1,88× o saldo do ERP** (17.452): parte é resíduo.

---

## 3. O que o ERP diz da QUANTIDADE — o estoque do Magento é resíduo?

Sim, é resíduo — **o saldo do ERP é menor em todos os casos grandes conferidos**:

| SKU | qty Magento | saldo ERP (`MT_ESTOQUE.QTDEREAL`) | Magento ÷ ERP |
|---|---|---|---|
| 20050058 | 32.872 | 17.452 (AL/2; AL/1 = 0) | **1,88×** |
| 6015 | 6.962 | 5.909 | 1,18× |
| 6011 | 4.215 | 2.366 | 1,78× |
| 2385 | 1.050 | **0** | ∞ |
| 6009 | 3.740 | 153 | 24× |
| 3511 | 1.611 | 152 | 10,6× |

A tabela/coluna de saldo é **`MT_ESTOQUE.QTDEREAL`**, por `FILIAL` + `TPESTOQUE` + `MATERIALLOTE`.
**Ressalva metodológica:** `TPESTOQUE` tem 7 tipos e somar tudo é errado — `DT` sozinho soma 65.491.190 e
`AL` soma 4.991.997; são naturezas diferentes. Conferi que **as linhas dos 115 são todas `AL`**, e nos
números acima somei apenas as linhas existentes de cada SKU (mostradas, sem agregação cega). 22 dos 115
não têm nenhuma linha em `MT_ESTOQUE`.

---

## 4. A coluna e a tabela — e a prova de que é a certa

* Tabela do produto: **`MT_MATERIAL`** (não existe `PRODUTO`/`PRODUTOS`; o ERP usa "MATERIAL").
  Chave: **`MT_MATERIAL.CODIGO`** (varchar 15) = o SKU do Magento.
* Coluna de status: **`CCKATIVO`** (`'S'`/`'N'`). Valores medidos na base inteira (15.098 materiais):
  `S`=12.140 · `N`=2.958. Há também `CKCOMERCIALIZA` (`'S'`=699 · `'N'`=14.399) e `CCKSTATUS`
  (`S`=14.767 · `N`=331 — **não** é o status de venda).
* *** A PROVA de que `CCKATIVO` é a coluna certa *** — o próprio módulo usa exatamente ela para decidir o
  status no Magento:
  ```
  ProductSync.php:418-419   $isActive = ($erpProduct['CCKATIVO'] ?? 'N') === 'S';
  ProductSync.php:492       $product->setStatus($isActive ? STATUS_ENABLED : STATUS_DISABLED);
  ProductSync.php:133/146/172   WHERE m.CCKATIVO = 'S'          (o escopo ativo do sync)
  ProductSync.php:1022      "Active scope is CCKATIVO = S, plus CKCOMERCIALIZA = S when filter_comercializa is on"
  ```
* Trilha de auditoria existente: `MT_MATERIAL.EDITDATE` + `EDITNAME` (ex.: `20050058` editado em
  2026-05-15 por RAFAEL SANTOS). **Ressalva:** isso é a data de alteração do **cadastro do material**,
  não a data em que o produto foi desativado no Magento — **não é prova de decisão comercial**.

---

## 5. Os 20 primeiros da lista do dono (ordem `peso_qty_x_demanda`)

| # | SKU | qty Mag | saldo ERP | **status ERP** | comercializa | preço Mag |
|---|---|---|---|---|---|---|
| 1 | 20050058 | 32.872 | 17.452 | **ATIVO** | S | **0,01** |
| 2 | 6015 | 6.962 | 5.909 | INATIVO | S | 5,2936 |
| 3 | 6011 | 4.215 | 2.366 | INATIVO | S | 6,552 |
| 4 | 2385 | 1.050 | 0 | INATIVO | S | 34,48 |
| 5 | 6009 | 3.740 | 153 | INATIVO | S | 5,2936 |
| 6 | 3511 | 1.611 | 152 | INATIVO | S | 16,7856 |
| 7 | 3510 | 787 | 302 | INATIVO | S | 17,368 |
| 8 | 6012 | 3.120 | 2.446 | INATIVO | S | 5,2936 |
| 9 | 3122 | 110 | 139 | INATIVO | S | 90,688 |
| 10 | 3512 | 1.260 | 181 | INATIVO | S | 19,9992 |
| 11 | 00576 MED | 1.188 | 792 | **ATIVO** | S | **0,01** |
| 12 | 6016 | 1.263 | 3.302 | INATIVO | S | 6,552 |
| 13 | 37 | 1.000 | — | **NÃO EXISTE** | — | 55,12 |
| 14 | 23 | 1.000 | — | **NÃO EXISTE** | — | 78,83 |
| 15 | 4021 CRS | 1.951 | 1.367 | INATIVO | S | 5,3768 |
| 16 | 022.012-6000 | 822 | 1.322 | **ATIVO** | S | **0,01** |
| 17 | 54 | 1.000 | — | **NÃO EXISTE** | — | 58,66 |
| 18 | 75 | 1.000 | — | **NÃO EXISTE** | — | 65,02 |
| 19 | 613 CV | 313 | 0 | INATIVO | N | 23,01 |
| 20 | 4031 CRS | 288 | 241 | INATIVO | S | 10,4624 |

**Leitura:** dos 20 maiores, **só 3 estão ATIVOS no ERP** (20050058, 00576 MED, 022.012-6000) e **os três
têm preço R$ 0,01** — bloqueados pelo guard. **17 dos 20 o ERP diz INATIVO ou inexistente** ⇒ para esses,
reativar seria contrariar o ERP.

---

## 6. A hipótese alternativa ("ativo no ERP, fora da loja por decisão")

Investiguei as duas marcas possíveis de decisão:

1. **`NonCatalogProductList`** (`ERPIntegration/Model/Product/`): existe e é uma lista curada de itens que o
   ERP marca como ativos+comercializados mas não são peça de moto — **contém exatamente 3 SKUs**
   (`50150102` mesa de corte de vidro, `50150162` transformador 225KVA, `55102952` ponta de parafusadeira).
   *** Interseção com os 115: ZERO. *** Não explica nenhum deles.
2. **Preço**: é o bloqueio real dos 20 (acima), e é **mecânico, não uma decisão registrada**.

*** Portanto: NÃO encontrei evidência de decisão comercial. *** O que explica os 115 é a combinação
`CCKATIVO` + `CKCOMERCIALIZA` + preço do próprio ERP — sem sobra. Declaro como **indeterminado** se alguém
decidiu "parar de vender" algum deles por fora: não há registro que prove isso.

---

## 7. O que NÃO consegui determinar

1. **Se alguém decidiu comercialmente não vender algum dos 115.** Não há histórico de alteração de status do
   produto no Magento (a tabela de status não guarda trilha) nem marca no ERP que distinga "decisão" de
   "consequência do cadastro". O `EDITDATE`/`EDITNAME` do ERP é do cadastro do material, não da loja.
2. **A natureza dos 21 SKUs que não existem no ERP** (23, 33-49, 54, 75…): parecem códigos antigos/locais,
   mas o ERP não tem como confirmar isso — **é decisão de cadastro, não dá para responder pelo ERP.**
3. **Por que 22 dos 115 não têm nenhuma linha em `MT_ESTOQUE`** (nem com saldo 0): pode ser item sem estoque
   cadastrado em nenhuma filial, mas não dá para distinguir de "nunca teve estoque".
4. **Qual preço o guard lê exatamente** (ERP ou Magento) — medi o **preço do Magento = R$ 0,01** nos 20, e o
   preço do ERP (`MT_MATERIALLISTA.VLRVDSUG`) é **ausente** para 6 deles (incluindo o `20050058`) e **real**
   para outros (022.012-6000 = 3,89/4,36). As duas coisas apontam para "preço inválido", mas não isolei qual
   das duas fontes o guard consulta.

## 8. Correção de um erro meu no caminho

Grepando pelo método de reativação, procurei `reactivateInactiveProducts` — **nome que eu deduzi de um
docblock** — e não achei nenhum chamador, o que sugeriria "o caminho existe mas nunca roda". **Estava
errado:** o método real é **`reconcileReactivation()`** e ele **É chamado pelo cron**
(`Cron/SyncProducts.php:79`, job `grupoawamotos_erp_sync_products`, a cada 6h) e pelo CLI
(`SyncProductsCommand.php:140`). O caminho roda; quem bloqueia é o guard. Registro porque quase reportei
uma conclusão errada por causa de um nome inventado.

---

## 9. Como reproduzir

Scripts em `99-consolidado/evidencias/backend/_scripts/`: `p115-recon.php`, `p115-recon2.php`,
`p115-recon3.php`, `p115-cruzar.php`, `p115-final.php`, `p115-saldo.php`, `p115-preco.php`, `p115-tri.php`,
`p115-precoerp.php`, `p115-reativ.sh`.
Consulta base do Magento (a mesma que gerou os 115):
```sql
SELECT e.sku FROM catalog_product_entity e
JOIN catalog_product_entity_int s ON s.entity_id=e.entity_id AND s.store_id=0
  AND s.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
JOIN cataloginventory_stock_item i ON i.product_id=e.entity_id
WHERE s.value=2 AND i.qty>0;
```
Consulta base do ERP (leitura):
```sql
SELECT m.CODIGO, m.CCKATIVO, m.CKCOMERCIALIZA, m.EDITDATE, m.EDITNAME
FROM MT_MATERIAL m WHERE m.CODIGO IN (...);
SELECT MATERIAL, FILIAL, TPESTOQUE, QTDEREAL FROM MT_ESTOQUE WHERE MATERIAL IN (...);
```
