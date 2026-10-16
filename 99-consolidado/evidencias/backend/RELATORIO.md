# RELATÓRIO — task-37 (DEV-BE-1)

**Frente:** backend · **Autor:** dev-backend · **Data:** 05/10/2026
**Servidor:** `/home/jessessh/htdocs/srv1113343.hstgr.cloud` (via `ssh AWA`)
**Escopo de escrita usado:** `app/code/`, `_backups/`, `99-consolidado/evidencias/backend/`
**Não tocado:** `app/design/`, `vendor/`, `pub/static/`

Origem: itens **181** e **182** de `00-briefing/ESTADO-DO-PROJETO.md`.

---

## 1. Vereditos

| # | Item do enunciado | Veredito | Mudança aplicada |
|---|---|---|---|
| **A** | `Mirasvit_ReportApi` fora do `app/etc/config.php` | **NAO-E-BUG** | **nenhuma** (o estado está correto por desenho) |
| **B** | `GrupoAwamotos_StoreSetup` (ATIVO) depende de `Rokanthemes_Faq` (DESLIGADO) | **PASS** — a tese de "dependência quebrada" foi **REFUTADA**; removida a declaração morta como higiene | 1 linha em `app/code/GrupoAwamotos/StoreSetup/etc/module.xml` |
| **C** | 2 papéis de ACL órfãos (`role_id` 189 e 191) | **PASS** | `DELETE` em `authorization_role` (cascata nas 3 regras) |

Nenhum veredito é "corrigido" sem teste executado. Toda saída neste arquivo é reproduzível pelos
comandos citados; os scripts estão em `_scripts/` e as transcrições cruas em
`EV-ANTES-transcricoes.txt` e `EV-FINAL-verificacao.txt`.

---

## 2. PROBLEMA A — `Mirasvit_ReportApi` fora do `config.php` → **NAO-E-BUG**

### 2.1 O que o enunciado pedia

> descobrir se isso causa funcionalidade faltando (ex.: algum relatório quebrado), e decidir entre
> (a) registrar o módulo no `config.php` ou (b) desinstalar. ANTES de decidir, responda: o `module.xml`
> dele declara sequence/dependências? Ele tem `Setup/` com schema version? Algum `di.xml` dele define
> preference/plugin que o `Report` precisa?

### 2.2 As três perguntas, respondidas com o arquivo na mão

| Pergunta | Resposta | Prova |
|---|---|---|
| `module.xml` declara sequence/dependências? | **NÃO.** O elemento `<module name="Mirasvit_ReportApi">` está **vazio** e sem `setup_version` | `cat app/code/Mirasvit/ReportApi/etc/module.xml` |
| Tem `Setup/` com schema version? | **NÃO existe diretório `Setup/`** em todo o pacote | `find app/code/Mirasvit/ReportApi -type d` |
| Algum `di.xml` define preference/plugin? | **NÃO existe `di.xml`.** O `etc/` dele tem **só 2 arquivos**: `module.xml` e `mst_report.xsd` | `ls app/code/Mirasvit/ReportApi/etc/` |

Sem `di.xml`, sem `events.xml`, sem `webapi.xml`, sem `acl.xml`, sem `db_schema.xml`, sem `Setup/`:
**não há nada que o Magento deixe de carregar.** A premissa do item 181 ("o Magento não carrega os
`etc/di.xml` e `etc/events.xml` dele") é verdadeira e **vazia** — esses arquivos não existem.

### 2.3 Por que ele **nunca** vai aparecer no `config.php`

```
app/code/Mirasvit/ReportApi/registration.php:
    ComponentRegistrar::register(ComponentRegistrar::LIBRARY, 'Mirasvit_ReportApi', __DIR__);
                                 ^^^^^^^^^^^^^^^^^^^^^^^^^ LIBRARY, não MODULE
```

`app/etc/config.php` lista **componentes do tipo MODULE**. Um componente registrado como **LIBRARY**
não entra nessa lista — por decisão do próprio fornecedor. Provado em runtime:

```
ComponentRegistrar MODULE  Mirasvit_ReportApi => NULL
ComponentRegistrar LIBRARY Mirasvit_ReportApi => '.../app/code/Mirasvit/ReportApi'
ComponentRegistrar MODULE  Mirasvit_Report     => '.../app/code/Mirasvit/Report'
$ php bin/magento module:status Mirasvit_ReportApi
Mirasvit_ReportApi : Module does not exist
```

### 2.4 A opção (a) não é apenas inútil — ela **quebraria a loja**

`Magento\Framework\Module\ModuleList\Loader::getModuleConfigs()` itera
`ComponentRegistrar::getPaths(ComponentRegistrar::MODULE)`; um nome em `config.php` **sem** componente
MODULE registrado não tem `etc/` resolvível. Como `ComponentRegistrar::getPath(MODULE, 'Mirasvit_ReportApi')`
é `NULL`, forçar a entrada no `config.php` produziria um módulo "habilitado" sem diretório — a mesma
família de falha do `setup:upgrade` de 03/10 (item do briefing: 503 → 500 → loja sem CSS/JS).
**Não foi testado em produção, de propósito.**

### 2.5 O que o `Mirasvit_Report` (ATIVO) realmente precisa — e já tem

O `Mirasvit_Report/etc/module.xml` declara `<sequence><module name="Mirasvit_ReportApi"/></sequence>`,
e **toda a fiação de DI do ReportApi vive no `di.xml` do `Mirasvit_Report`** (que É um módulo habilitado):

```
app/code/Mirasvit/Report/etc/di.xml:72  <!--ReportApi definitions-->
    <preference for="Mirasvit\ReportApi\Api\SchemaInterface"          type="Mirasvit\ReportApi\Config\Schema"/>
    <preference for="Mirasvit\ReportApi\Api\RequestInterface"         type="Mirasvit\ReportApi\Processor\Request"/>
    <preference for="Mirasvit\ReportApi\Api\RequestBuilderInterface"  type="Mirasvit\ReportApi\Processor\RequestBuilder"/>
    <preference for="Mirasvit\ReportApi\Api\ResponseInterface"        type="Mirasvit\ReportApi\Processor\Response"/>
    <preference for="Mirasvit\ReportApi\Api\Service\TableServiceInterface" type="Mirasvit\ReportApi\Service\TableService"/>
    ... + <type name="Mirasvit\ReportApi\Api\SchemaInterface"> com o mapa de 13 tipos e 12 agregadores
```

E o `xsi:noNamespaceSchemaLocation` do `mst_report.xml` **não importa**: o
`ReportApi/Config/Loader/SchemaLocator` aponta para o XSD por **caminho absoluto local**
(`dirname(dirname(dirname(__FILE__))) . '/etc/mst_report.xsd'`), não pelo URN do componente.

### 2.6 Teste funcional ponta a ponta (o que prova que "não falta funcionalidade")

`php /tmp/diag-report.php` — nenhuma classe do ReportApi foi carregada à mão; tudo veio do DI compilado:

```
OK   Mirasvit\ReportApi\Api\SchemaInterface           => Mirasvit\ReportApi\Config\Schema
OK   Mirasvit\ReportApi\Api\RequestBuilderInterface   => Mirasvit\ReportApi\Processor\RequestBuilder
OK   Mirasvit\ReportApi\Api\RequestInterface          => Mirasvit\ReportApi\Processor\Request
OK   Mirasvit\ReportApi\Api\ResponseInterface         => Mirasvit\ReportApi\Processor\Response
OK   Mirasvit\ReportApi\Api\Service\TableServiceInterface => Mirasvit\ReportApi\Service\TableService
OK   Mirasvit\Report\Api\Repository\ReportRepositoryInterface => Mirasvit\Report\Repository\ReportRepository

OK   Schema->getTables()                  => 16 tabelas
OK   Schema->getTable('sales_order')      => 76 colunas
OK   getType('money')                     => string 'Mirasvit\ReportApi\Config\Type\Money'  (do di.xml)

=== PONTA A PONTA: RequestBuilder -> process() -> linha REAL do banco ===
OK   sales_order: entity_id, increment_id => 1 linhas :: {"sales_order|entity_id":"8","sales_order|increment_id":"000000007"}
```

A última linha é o teste decisivo: **o `RequestBuilderInterface` foi resolvido pelo DI, montou o SQL
pelo Schema (16 tabelas lidas do `mst_report.xml`) e devolveu uma linha real de `sales_order`**. Não
há relatório quebrado por causa do `config.php`.

Os 19 arquivos do `Mirasvit_Report` que referenciam o ReportApi são atendidos normalmente
(`grep -rl 'Mirasvit.ReportApi' app/code/Mirasvit/Report --include=*.php | wc -l` → **19**).

### 2.7 Decisão

**(b) e (a) estão ambas erradas. Não fazer nada é a resposta correta.**
O ReportApi é uma **biblioteca de código** (termo do próprio fornecedor) cujo único conteúdo carregável
é `mst_report.xsd`; nada consome esse XSD por URN. Desinstalar o diretório quebraria os 19 arquivos do
`Mirasvit_Report`. Registrar no `config.php` exigiria editar `registration.php` de terceiro (proibido:
não se mexe em código de fornecedor) e criaria um módulo sem diretório.

**Rollback:** não aplicável — **nenhum arquivo foi alterado**.

---

## 3. PROBLEMA B — `StoreSetup → Rokanthemes_Faq` → **PASS** (higiene; a "dependência quebrada" é REFUTADA)

### 3.1 Onde exatamente o StoreSetup usa `Rokanthemes_Faq`

Varredura do módulo inteiro (43 arquivos) por `Faq`:

```
app/code/GrupoAwamotos/StoreSetup/etc/module.xml:9:   <module name="Rokanthemes_Faq"/>   <-- UNICA declaracao
Setup/Patch/Data/AyoSeedContent.php:278               private function seedFaq($connection)
Setup/Patch/Data/AyoSeedContentV2.php:169             private function seedFaq($connection)
Setup/Patch/Data/AyoSeedContent.php:321               private function getFaqDefinitions()   <-- conteudo CMS/HTML
Setup/Patch/Data/AyoHomepageCmsBlocks.php:424         private function homeFaqQuickContent() <-- conteudo CMS/HTML
Setup/Patch/Data/UpdateInstitutionalPages.php:727     private function contentFaq()          <-- conteudo CMS/HTML
Setup/StoreConfigurator.php:377                       private function cmsPageFaqContent()   <-- conteudo CMS/HTML
```

**Zero referências a qualquer classe `Rokanthemes\Faq` em PHP.** Nenhuma ocorrência em `app/design/`.
E no projeto inteiro (`app/code` + `app/design`), a única linha que cita `Rokanthemes_Faq` é o próprio
`module.xml` do StoreSetup:

```
$ grep -rl 'Rokanthemes_Faq' app/code/ | grep -v 'Rokanthemes/Faq'
app/code/GrupoAwamotos/StoreSetup/etc/module.xml
$ grep -rl 'Rokanthemes_Faq' app/design/
(vazio)
```

### 3.2 É runtime ou setup? — **setup, e já guardado**

O único consumo real é a tabela `rokan_faq`, em dois **data patches**, e ambos se protegem:

```php
private function seedFaq($connection): void
{
    $table = $this->moduleDataSetup->getTable('rokan_faq');
    if (!$connection->isTableExists($table)) {                    // <-- GUARDA
        $this->logger->warning('[AyoSeedContentV2] Tabela rokan_faq nao encontrada.');
        return;                                                    // <-- não quebra
    }
    ...
    try { ... $connection->insert($table, $row); } catch (\Throwable $e) { $this->logger->error(...); }
```

E o cenário "tabela ausente" **não ocorre**: o módulo está instalado no banco e a tabela existe.

```
setup_module:  Rokanthemes_Faq | schema_version 2.4.1 | data_version 2.4.1
rokan_faq total = 5        (seed aplicado em 2026-02-24 03:52:44)
patch_list:    ...\AyoSeedContent   ...\AyoSeedContentV2      (patches JÁ aplicados)
```

### 3.3 A declaração **nem sequer** bloqueia `module:disable`

`Magento\Framework\Module\DependencyChecker::createGraph()` monta o grafo com
`PackageInfo::getRequire()` — que lê **`composer.json`** (`PackageInfo.php:96/117`), **não** o
`<sequence>` do `module.xml`. O StoreSetup **não tem `composer.json`**. Resultado medido com a classe
exata que os comandos CLI usam:

```
checkDependenciesWhenDisableModules(['Rokanthemes_Faq']) => {"Rokanthemes_Faq":[]}
checkDependenciesWhenEnableModules(['GrupoAwamotos_StoreSetup']) => {"GrupoAwamotos_StoreSetup":[]}
```

Ou seja: a "dependência" **não** impede desabilitar o Faq, **não** impede habilitar o StoreSetup e
**não** afeta nada em runtime. A página `/faq` da loja é uma **CMS page** (`cms_page.page_id=26`,
`url_rewrite: faq -> cms/page/view/page_id/26`), com HTML estático — sem `{{block}}` e sem
`Rokanthemes`/`rokan_faq` no conteúdo; `curl -sL https://awamotos.com/faq` → **200**, com
`<h1>Perguntas Frequentes (FAQ)</h1>` e os `div.awa-faq-item`.

### 3.4 O que foi feito, então

Veredito do item 181 ("PROBLEMA REAL — DEPENDÊNCIA QUEBRADA"): **REFUTADO**. Não há quebra.
A opção "reativar o Faq" está **descartada por evidência**: o módulo já está instalado, a página de FAQ
não o usa, e ligá-lo só acrescentaria superfície de frontend (fora do meu escopo e do meu mandato).

Restava a declaração morta no `module.xml`. Ela foi removida — **e a remoção foi provada inócua antes
de ser aceita**, porque `sequence` define **ordem de merge de config**:

* `module.xml` `6b0b871d…` (534 bytes) → `2465e7ad…` (489 bytes); −45 bytes = exatamente a linha removida.
* A lista **habilitada** de módulos continua **idêntica à ordem do `config.php`** (351 = 351,
  `MESMA ORDEM? true`), com `GrupoAwamotos_StoreSetup` no mesmo índice **346** e os mesmos vizinhos
  (`Rokanthemes_Testimonials`, `Rokanthemes_StoreLocator`, `GrupoAwamotos_StoreSetup`,
  `GrupoAwamotos_AbandonedCart`, `Rokanthemes_Toprate`).
* → **a ordem de merge do `di.xml` não mudou ⇒ nenhum `setup:di:compile` é necessário.**

Edição por **comparação de linha exata** (nunca regex gananciosa), com âncora
`<module name="Rokanthemes_Faq"/>`, e **validação do XML em memória (`DOMDocument`) ANTES da escrita**;
`file_put_contents` trunca no lugar → **inode, dono (`jessessh`) e modo (`674`) preservados**.

### 3.5 Comando exato

```bash
scp 99-consolidado/evidencias/backend/_scripts/apply-b.php AWA:/tmp/apply-b.php
ssh AWA "php /tmp/apply-b.php"
# -> linhas removidas = 1 | XML valido: root=config module=GrupoAwamotos_StoreSetup setup_version=1.0.0
# -> sequence agora: Magento_GraphQl, Rokanthemes_Blog, Rokanthemes_SlideBanner, Rokanthemes_Testimonials
# -> sha256 6b0b871d3e020ad9b4c0e7e5d0017d102f53a8112ca0d66fa5e65a67085ce25b
#         -> 2465e7ad647dee920ce6df7c1e227c82dcca0f36803c6b021a3e6392e8167ad1
ssh AWA "cd <root> && php bin/magento cache:clean config"
```

### 3.6 Rollback

Backup: **`_backups/be-task37-20261005T200750Z/StoreSetup-module.xml.orig`** (+ `SHA256SUMS`).

```bash
cat /home/jessessh/htdocs/srv1113343.hstgr.cloud/_backups/be-task37-20261005T200750Z/StoreSetup-module.xml.orig \
  > /home/jessessh/htdocs/srv1113343.hstgr.cloud/app/code/GrupoAwamotos/StoreSetup/etc/module.xml
ssh AWA "cd <root> && php bin/magento cache:clean config"
```

---

## 4. PROBLEMA C — 2 papéis de ACL órfãos → **PASS**

### 4.1 Avaliação de segurança antes de apagar (schema conferido, não adivinhado)

```
### authorization_role (as 2 linhas)
role_id | parent_id | tree_level | sort_order | role_type | user_id | user_type | role_name
189 | 0 | 1 | 0 | U | 2   | 1 | 12
191 | 1 | 2 | 0 | U | 153 | 2 | audit_temp_1783540959

### os admins existem?
189 | 12                     | user_id=2   | admin_existe=NULL
191 | audit_temp_1783540959  | user_id=153 | admin_existe=NULL
### filhos (parent_id IN 189,191)          => (vazio)
### authorization_rule: role 189 => 3 regras | role 191 => 0 regras
### FKs que APONTAM para authorization_role:
authorization_rule.role_id -> authorization_role.role_id  ON DELETE CASCADE   (UNICA)
### FKs de authorization_role => nenhuma | FKs para authorization_rule => nenhuma
```

* `role_type='U'` (papel de usuário) e o `user_id` aponta para `admin_user` **inexistente** → órfãos de verdade.
* Sem filhos na árvore → apagar não deixa `parent_id` pendurado.
* Única FK de entrada é `authorization_rule` com **ON DELETE CASCADE** → as 3 regras do papel 189 saem sozinhas.
* `authorization_rule` não é referenciada por ninguém.
* Nada é referenciado por `role_name` ("12" e `audit_temp_1783540959` são nomes livres, sem uso).

### 4.2 Antes → depois

| | ANTES | DEPOIS | Δ |
|---|---|---|---|
| `authorization_role` | 31 | **29** | −2 |
| `authorization_rule` | 2050 | **2047** | −3 (cascata) |
| papéis `role_type='U'` órfãos | 2 | **0** | −2 |
| papéis `role_type='G'` com `user_id` inexistente | 0 | **0** | 0 |
| papéis com `parent_id` inexistente | 0 | **0** | 0 |
| `admin_user` ativos | 13 | **13** | 0 |

A árvore restante (29 papéis: 9 de grupo + 19 de usuário — sendo 1 `G`+`U` conforme o tipo) ficou com
todos os vínculos válidos. **Nenhum admin ativo foi tocado.**

### 4.3 Comando exato

```bash
scp 99-consolidado/evidencias/backend/_scripts/apply-c.php AWA:/tmp/apply-c.php
ssh AWA "php /tmp/apply-c.php"      # transacional: pre-condicoes -> DELETE -> pos-condicoes
ssh AWA "cd <root> && php bin/magento cache:clean config"
```

O script **aborta antes de escrever** se qualquer pré-condição falhar (papel ≠ `U`, `admin_user`
existente, papel filho presente), e roda o `DELETE` dentro de transação com `rollBack()` em exceção.

### 4.4 Rollback

Backup: **`_backups/be-task37-20261005T200750Z/`**
(`authorization_role-189-191.sql` com 2 `INSERT`, `authorization_rule-189-191.sql` com 3 `INSERT`,
versões `.csv`, `ANTES.json`, `ROLLBACK.md`, `SHA256SUMS`).

```bash
mysql < _backups/be-task37-20261005T200750Z/authorization_role-189-191.sql
mysql < _backups/be-task37-20261005T200750Z/authorization_rule-189-191.sql
ssh AWA "cd <root> && php bin/magento cache:clean config"
```

---

## 5. Validação pós-mudança (executada, não presumida)

| Verificação | Resultado |
|---|---|
| `curl -sL -o /dev/null -w '%{http_code}' https://awamotos.com/` | **200** |
| `curl -sL -o /dev/null -w '%{http_code}' https://awamotos.com/retrovisores.html` | **200** |
| `https://awamotos.com/faq` | **200** |
| `https://awamotos.com/checkout/cart/` | **200** |
| `https://awamotos.com/admin_49fxvZi8FI/` (login do admin, após mexer no ACL) | **200** + form `login[username]`/`login[password]` |
| `php bin/magento module:status GrupoAwamotos_StoreSetup` | `Module is enabled` |
| `php bin/magento module:status Rokanthemes_Faq` | `Module is disabled` (inalterado) |
| `php bin/magento cache:clean config` | `Cleaned cache types: config` |
| `pwsh -File tools\verificar-loja.ps1` (completo, com a série de 30 s do hero) | **26 verificações · 26 PASS · 0 FAIL** (exit 0) |

Amostras do placar (`99-consolidado/evidencias/regressao/regressao.csv`):
`HTTP home 200` · `HTTP plp 200` · `HTTP carrinho 200` · `HTTP login B2B 200` ·
`HTTP rota inexistente 404` · `Sem preço no HTML anônimo (home)` ·
`Hero sem amostra vazia (11x3s) vazias=0/11` · `Hero girando mudancas=3` ·
`Autoplay não pausado paused=false em 11/11` · `Console limpo (home) erros=0 excecoes=0` ·
`Minicart abre painelAberto=True`.
Uma rodada anterior em modo `-Rapido` deu **23/23 PASS · 0 FAIL** (o `-Rapido` pula só as 3
verificações da série de 30 s do hero).

`php -l` / `opcache-reset.sh`: **não se aplicam** — nenhum arquivo `.php` foi editado (a única edição
de código foi em `module.xml`, que não passa por OPcache; o caminho de cache dele é o tipo `config`,
limpo com `cache:clean config`). **`cache:flush` não foi usado em nenhum momento.**

---

## 6. Erros de instrumento meus (registrados)

1. `getType('money')` testado com `get_class()` → `TypeError`. **Erro do meu teste**, não do sistema:
   `getType()` devolve o *nome da classe* (string do mapa `<argument name="types">` do `di.xml`).
   Corrigido em `verify-final.php`.
2. Query em `authorization_rule` usando a coluna `assert_id`, que **não existe** — mesmo erro de
   adivinhação de schema que já custou caro hoje. Colunas reais: `rule_id, role_id, resource_id,
   privileges, permission`. Corrigido; nenhuma query de escrita foi afetada (a falha ocorreu na fase
   de diagnóstico, antes dos `DELETE`).
3. Join em `information_schema` com `TABLE_NAME` ambíguo → `1052`. Corrigido consultando só
   `REFERENTIAL_CONSTRAINTS`.
4. Minha primeira execução do `apply-c.php` morreu **antes** de qualquer `DELETE` (a exceção foi na
   query de FK, que precede a transação). Nada foi meio-apagado; a segunda execução passou por todas
   as pré-condições e refez a contagem de 31/2050.

---

## 7. Arquivos e artefatos

**Alterados (1):**
* `app/code/GrupoAwamotos/StoreSetup/etc/module.xml` — `6b0b871d…` → `2465e7ad…` (534 → 489 bytes)

**Backup:** `_backups/be-task37-20261005T200750Z/`

**Evidências:** `99-consolidado/evidencias/backend/`
* `RELATORIO.md` (este)
* `EV-FINAL-verificacao.txt` — verificação programática pós-mudança (A/B/C)
* `EV-ANTES-transcricoes.txt` — saídas cruas do estado anterior
* `_scripts/` — `backup-task37.php`, `apply-b.php`, `apply-c.php`, `verify-final.php`,
  `diag-report.php`, `diag-dep.php`, `diag-order.php`, `diag-acl.php`, `diag-bc.php`, `diag-autoload.php`

**Não tocados:** `app/design/`, `vendor/`, `pub/static/`, `app/etc/config.php`, `app/etc/env.php`.
