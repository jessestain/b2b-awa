# task-42 — `Template file 'header.html' is not found` — e-mail transacional

Autor: dev-backend · 05/10/2026 · servidor `/home/jessessh/htdocs/srv1113343.hstgr.cloud`
Escopo: `app/code/`, `_backups/`, `99-consolidado/evidencias/backend/` — `app/design/` NÃO tocado.

## VEREDITO: **PASS** — bug real, causa-raiz provada, corrigido e testado com envio de verdade.

**E a hipótese do enunciado está REFUTADA nas duas pontas:** criar os 2 templates no banco NÃO resolveria
(e é inútil por desenho), e limpar as linhas `core_config_data` 3406/3407 **piora** (troca a exceção por
outra). O defeito estava em **uma única palavra numa única linha de app/code**.

---

## (A) COMO O MAGENTO RESOLVE `design/email/header_template` — arquivo:linha

O valor **não** é um template de banco. É um **id de template declarado em XML**, e o que ele aponta é um
**ARQUIVO**:

```
vendor/magento/module-email/etc/config.xml:12
    <header_template>design_email_header_template</header_template>

vendor/magento/module-email/etc/email_templates.xml:9
    <template id="design_email_header_template" label="Header" file="header.html"
              type="html" module="Magento_Email" area="frontend"/>
```

A cadeia, do texto do e-mail até a exceção:

| # | Onde | O que faz |
|---|---|---|
| 1 | `Magento\Framework\Filter\DirectiveProcessor\TemplateDirective::process()` :59-73 | vê `{{template config_path="design/email/header_template"}}` e chama `call_user_func(getTemplateProcessor(), $configPath, ...)` |
| 2 | `Magento\Email\Model\AbstractTemplate::getTemplateContent()` :231-244 | cria o template filho e chama `loadByConfigPath($configPath)` |
| 3 | **`AbstractTemplate::loadByConfigPath()` :275-286** | `$templateId = scopeConfig->getValue($configPath)`; **`if (is_numeric($templateId)) { $this->load($templateId); } else { $this->loadDefault($templateId); }`** |
| 4 | `AbstractTemplate::loadDefault()` :288-291 | `$designParams = $this->getDesignParams(); $templateFile = $emailConfig->getTemplateFilename($templateId, $designParams);` |
| 5 | `AbstractTemplate::getDesignParams()` :585-595 | `'area' => $this->getDesignConfig()->getArea()` e `'themeModel' => $this->design->getDesignTheme()` |
| 6 | `Magento\Email\Model\Template\Config::getTemplateFilename()` :202-212 | lê `file` (= `header.html`) e `module` (= `Magento_Email`) da declaração XML e chama `getFilename()` |
| 7 | `Magento\Email\Model\Template\Config::getFilename()` :250-257 | `viewFileSystem->getEmailTemplateFileName($file, $designParams, $module)`; se vier `false` → **`throw new \UnexpectedValueException("Template file '{$file}' is not found.")`** ← a linha **255** do log |
| 8 | `Magento\Framework\View\FileSystem::getEmailTemplateFileName()` :157-161 | delega para `EmailTemplateFile::getFile($area, $themeModel, $locale, $file, $module)` |
| 9 | `Magento\Framework\View\Design\Fallback\RulePool::createEmailTemplateFileRule()` | regra = Composite de **`<theme_dir>/<module_name>/email`** **e** **`<module_dir>/view/<area>/email`** |

### O que muda quando o valor está vazio vs preenchido

* **Preenchido e NÃO numérico** (`design_email_header_template`, o caso desta loja): vai para
  `loadDefault()` → **resolução de ARQUIVO** (passos 4-9). É o caminho de desenho.
* **Preenchido e NUMÉRICO** (ex.: `12`): `load($templateId)` → **aí sim** o banco (`email_template.template_id`).
  É o único momento em que a tabela entra nesse fluxo.
* **Vazio/ausente**: `loadDefault('')` → `Config::_getInfo('')` → `!isset($data[''])` →
  `UnexpectedTemplateIdValueException("Email template is not defined.")` — **outra exceção, mesmo fracasso**.

### ⇒ Refutação das duas opções do enunciado

* **(i) criar `design_email_header_template`/`design_email_footer_template` no banco: INÚTIL.**
  O valor não é numérico, então `loadByConfigPath()` **nunca** consulta o banco nesse caminho. As 2 linhas
  não existem em `email_template` e **nada as procura**. Criá-las não mudaria um byte do resultado.
* **(ii) limpar as linhas `core_config_data` 3406/3407: PIOR QUE INÚTIL.** Apagar as linhas **não zera o
  valor** — entra o default de `module-email/etc/config.xml:12`, que é **a mesma string**. E gravar string
  vazia cai no terceiro caso acima, gerando `Email template is not defined.` em vez de `header.html`.

---

## (B) QUEM DISPARAVA — identificado, sem invenção

O stack trace do `exception.log` (05/10 22:16:58) aponta o chamador exato:

```
#15 TransportBuilder.php(380)  -> Template->processTemplate()
#16 TransportBuilder.php(336)  -> prepareMessage()
#17 app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php(349): TransportBuilder->getTransport()
#18 app/code/GrupoAwamotos/B2B/Observer/CustomerRegisterObserver.php(102): CustomerApproval->notifyAdminNewCustomer()
#19 InvokerDefault.php(88)     -> CustomerRegisterObserver->execute()
```

**E-mail: `grupoawamotos_b2b_admin_new_customer` — "B2B - Novo Cliente Aguardando Aprovação",
a notificação ao ADMIN de que entrou um cliente esperando aprovação.** Disparado no cadastro B2B
(observer `customer_register_success`).

O template é declarado em `app/code/GrupoAwamotos/B2B/etc/email_templates.xml:11-16`:

```xml
<template id="grupoawamotos_b2b_admin_new_customer" file="admin_new_customer.html"
          type="html" module="GrupoAwamotos_B2B" area="frontend"/>
```
e o arquivo existe: `app/code/GrupoAwamotos/B2B/view/frontend/email/admin_new_customer.html` (3.623 bytes).
Ele contém `{{template config_path="design/email/header_template"}}`, que é o que estoura.

---

## (C) IMPACTO REAL — o e-mail NÃO sai; o cadastro NÃO quebra

* **O e-mail não é enviado.** `getTransport()` lança em `prepareMessage()` e `sendMessage()` (linha 351)
  nunca é alcançado. Confirmei que **ninguém engole a exceção no caminho do filtro**:
  `Magento\Framework\Filter\Template::processDirectives()` (:256) e `filter()` (:195) **não têm try/catch**,
  e `TemplateDirective::process()` também não. A exceção sobe até o `catch (\Exception)` de
  `notifyAdminNewCustomer()` → `return false`.
* **O cadastro do cliente continua.** No log do mesmo evento:
  `22:16:58.207883 main.CRITICAL: ... Template file 'header.html' is not found` e, **1 segundo depois**,
  `22:16:59.096312 main.INFO: B2B: Novo cliente #9303 registrado como pendente de aprovação`.
  O observer chegou ao fim; o cliente vê a mensagem de "pendente" normalmente. Não há 500 para o cliente.
* **Quem perde:** o **administrador** — ele não é avisado de que existe cliente novo aguardando aprovação.
  Frequência: **2 entradas no log atual** (05/10 22:16:58 e 22:33:55 — ou seja, está acontecendo agora, em
  cadastros reais) e **2 entradas** em `exception.log.2.gz` (03/10 16:18:50 e mais uma).
* **Outros e-mails não são afetados.** Os 31 templates de banco (`grupoawamotos_*_ptbr`: pedido, fatura,
  envio, cliente, newsletter) usam o mesmo `{{template config_path="design/email/header_template"}}`, mas
  são enviados com `area=frontend` por todos os outros emissores e por isso resolvem normalmente.
* **INCONCLUSIVO (declarado):** não consegui pinçar qual chamada escreveu a entrada `main.CRITICAL`
  (o `{"exception": ...}` indica um `logger->error($msg, ['exception' => $e])`, que é o formato do catch do
  **observer**, mas o `INFO` de sucesso do mesmo observer veio depois). Não afeta o diagnóstico nem o fix:
  o que importa é que `getTransport()` lança antes do envio. Não vou afirmar o ator sem prova.

---

## A CAUSA-RAIZ (uma palavra)

`CustomerApproval.php:334` mandava o e-mail com **`area = AREA_ADMINHTML`**:

```php
->setTemplateOptions([
    'area' => \Magento\Framework\App\Area::AREA_ADMINHTML,   // <-- aqui
    'store' => $store->getId(),
])
```

Com `area=adminhtml`, o segundo padrão da regra de fallback vira
`Magento_Email/view/adminhtml/email/header.html` — **e esse diretório NÃO EXISTE**:

```
vendor/magento/module-email/view/frontend/email/   -> footer.html, header.html   EXISTE
vendor/magento/module-email/view/adminhtml/email/  -> *** NAO EXISTE ***
```
(o tema administrativo `adminhtml/Magento/backend` também não tem `Magento_Email/email/`, então o primeiro
padrão da regra também não salva.)

O template, porém, é declarado **`area="frontend"`** e o arquivo vive em **`view/frontend/email/`**.
A `area` estava simplesmente errada — e é um caso isolado: **todos os outros emissores do projeto usam
`AREA_FRONTEND`**, incluindo as **outras duas** chamadas do MESMO arquivo
(`CustomerApproval.php:373` e `:409`), o `B2B/Cron/NotifyPendingApprovals.php:72`, o
`B2B/Model/Email/Sender.php:197` (`'frontend'`), o `AbandonedCart/EmailSender` (:128 e :204), o
`ProductIntelligence/EmailNotifier:174` e o `LogMonitoring/NotificationService:140`.

### Experimento decisivo (matriz area × resolução)

Rodei `getDesignParams()` + o resolver real do Magento:

```
=== setDesignConfig(area=frontend) ===
  getDesignParams()['area']       = frontend
  resolucao de header.html        = .../vendor/magento/module-email/view/frontend/email/header.html   OK
=== setDesignConfig(area=adminhtml) ===
  getDesignParams()['area']       = adminhtml
  resolucao de header.html        = *** FALSE ***   <-- o que dispara Config.php:255
```
Com o tema da loja (`AWA_Custom/ayo_home5_child`, que TEM `Magento_Email/email/header.html`), a resolução
passa por qualquer área; quando o tema não contribui, quem decide é o padrão de módulo — e aí a área é
**a** variável. `area=frontend` resolve nos 3 cenários testados.

---

## (D) A CORREÇÃO

```bash
scp _scripts/fix-email-area.php AWA:/tmp/ && ssh AWA "php /tmp/fix-email-area.php --apply"
ssh AWA "cd <root> && php -l app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php"   # OK, SO DEPOIS:
ssh AWA "cd <root> && bash scripts/opcache-reset.sh"                                  # Opcache reset: OK
```
Edição por **âncora de texto exata** (1 ocorrência), XML/estrutura intacta, `php -l` **antes** do opcache.

```
-                    'area' => \Magento\Framework\App\Area::AREA_ADMINHTML,
+                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND, // task-42: area=adminhtml nao resolve header.html/footer.html

sha256 7eb5042f00320b18de4214ee1d53a4adfa50f1aacb2223319a1c45c9706cbd68
    -> a7dbb8d3c60a2c1956387d393d6dd5a918f782ea5cab50d655b4bdfc060d8e58   (20.896 -> 20.958 bytes)
dono/grupo/modo preservados: 1004/33/664 · AREA_ADMINHTML restantes no arquivo: 0
```
`cache:flush` não foi usado em momento algum.

---

## (E) TESTE DE VERDADE — e-mail disparado

Chamei o **método implantado**, sem simulação:

```php
$approval = $om->get('GrupoAwamotos\B2B\Model\CustomerApproval');
$ok = $approval->notifyAdminNewCustomer(9293);   // o metodo real, com TransportBuilder real
```

```
exception.log ANTES: 14290 bytes (mtime 2026-10-05T22:33:55+00:00)
chamando notifyAdminNewCustomer(9293) — o metodo REAL, sem simulacao...

*** RESULTADO: TRUE — o e-mail foi ENVIADO ***
duracao: 958 ms

exception.log DEPOIS: 14290 bytes
cresceu? NAO (nenhuma excecao nova)
```

`TRUE` só é retornado **depois** de `$transport->sendMessage()` (linha 353) — ou seja, o SMTP (MagePal)
aceitou a mensagem. 958 ms é a ida-e-volta real do envio, não um curto-circuito.
**E a exceção não voltou ao log** (tamanho idêntico, byte a byte).
Destinatário: o admin configurado (`Config::getAdminEmail()`) — é exatamente quem deveria ter recebido.

---

## VALIDAÇÃO

```
php -l no alvo                                   -> No syntax errors detected
bash scripts/opcache-reset.sh                    -> Opcache reset: OK
notifyAdminNewCustomer() real                    -> TRUE, 958 ms, exception.log sem crescer
pwsh -File tools\verificar-loja.ps1              -> 32 verificações · 32 PASS · 0 FAIL
```

## ROLLBACK

Backup: **`_backups/email-header-20261005T223638Z/`** (`CustomerApproval.php.orig` + `ANTES.txt`).

```bash
cat _backups/email-header-20261005T223638Z/CustomerApproval.php.orig \
  > app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php
ssh AWA "cd <root> && php -l app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php"
ssh AWA "cd <root> && bash scripts/opcache-reset.sh"
```
(Se o rollback for aplicado, o e-mail volta a falhar — o bug original volta junto.)

## ARQUIVOS

* Alterado: `app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php` (1 linha, +62 bytes de comentário)
* Backup: `_backups/email-header-20261005T223638Z/`
* Scripts: `99-consolidado/evidencias/backend/_scripts/` — `email-diag.sh`, `email-trace.sh`,
  `email-prova.php`, `email-prova2.sh`, `email-experimento.php`, `email-temas.php`, `email-impacto*.sh`,
  `email-catch*.sh`, `fix-email-area.php`, `fix-email-aplicar.sh`, `email-teste-real.php`
* **`vendor/` NÃO foi tocado. `app/design/` NÃO foi tocado. `cache:flush` nunca usado.**
