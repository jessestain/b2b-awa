VARREDURA DE MODULOS — awamotos.com (05/10/2026)
=================================================
Metodo: 3 scripts sistematicos (tools/varrer-modulos{1,2,3}.sh) + verificacao
manual de cada suspeito. Os criterios automaticos erraram 3 vezes e foram
corrigidos ANTES de virar conclusao (registrado abaixo).

INVENTARIO
  app/code (custom) ................ 82 modulos  (65 ativos, 17 desligados)
  vendor declarados ................ 374 modulos
  config.php ....................... 451 entradas (351 ativos, 100 desligados)
  arquivos PHP nos custom .......... 2.918  -> *** 0 com erro de sintaxe ***
  peso dos desligados .............. 17 custom + 76 vendor ~= 30 MB

============================================================
CRITICO — SEGURANCA (precisa de decisao do dono)
============================================================
1) *** 2FA DESLIGADO, COM 18 ADMINS ATIVOS ***
   Magento_TwoFactorAuth ............... desligado
   Magento_AdminAdobeImsTwoFactorAuth .. desligado
   Magento_AdobeIms .................... desligado
   => NENHUM provedor de 2FA ativo
   => no Magento 2.4 o 2FA e OBRIGATORIO por padrao; alguem desligou
   => risco: uma senha vazada da acesso a 9.172 clientes, pedidos e CNPJ
   NAO ATIVADO POR MIM: ligar 2FA forca os 18 admins a configurar o app,
   e quem nao configurar PERDE O ACESSO. E decisao do dono.

2) *** MSP_SecuritySuiteCommon ESTA ORFao ***
   o ACL dele declara o recurso `MSP_SecuritySuite::config`
   mas o modulo `MSP_SecuritySuite` (o pai) NAO existe - so o "Common" foi instalado
   => ACL apontando para recurso inexistente
   => o "Security Suite" nao protege nada

============================================================
PROBLEMAS REAIS DE MODULO
============================================================
3) *** Mirasvit_ReportApi FORA DO config.php ***
   app/code/Mirasvit/ReportApi - 81 arquivos, 75 PHP
   NAO esta em app/etc/config.php => o Magento nao carrega seus di.xml/events.xml
   MAS o Mirasvit_Report o USA (8+ arquivos: Report/Ui/, Report/Service/, Report/Repository/)
   => funciona por acidente (autoload PSR-4), mas sem os di.xml dele
   => estado inconsistente: pode faltar funcionalidade silenciosamente

4) *** DEPENDENCIA QUEBRADA ***
   GrupoAwamotos_StoreSetup (ATIVO) depende de Rokanthemes_Faq (DESLIGADO)
   => e a UNICA dependencia quebrada dos 82 custom

5) *** SOBREPOSICAO DE RESPONSABILIDADE ***
   CatalogFix ......... 2 ATIVOS: Awa_CatalogFix + GrupoAwamotos_CatalogFix
   Schema/dados estruturados 2 ATIVOS: GrupoAwamotos_SchemaEnhancer + SchemaOrg
   Dashboard .......... 2 ATIVOS: Awa_DashboardFix + Awa_RealTimeDashboard
   Visual fixes ....... 2 ATIVOS: AWA_VisualFixes + GrupoAwamotos_LayoutFix
   Busca .............. *** 11 MODULOS ***, 6 ativos, 5 desligados:
        ativos: Mirasvit_Search, SearchAutocomplete, SearchElastic,
                SearchLanding, SearchReport, SearchUltimate
        desligados: SearchGraphQl, SearchMysql, SearchSphinx,
                    Rokanthemes_SearchSuiteAutocomplete, SearchbyCat
   Meta ............... 5 modulos, 2 ativos (BusinessExtension, Conversion)
   Chat ............... 3 modulos, 2 ativos (AiAssistant, WhatsAppCommerce)

6) *** PESO MORTO NO DISCO ***
   custom desligados: Apptrian_ImageOptimizer 4,5 MB (o otimizador de imagem!)
                      Mirasvit_SearchSphinx 8,8 MB
                      Rokanthemes_PriceCountdown 566 KB
                      GrupoAwamotos_MarketingIntelligence 345 KB (94 arquivos)
                      Mirasvit_SearchMysql 185 KB
   vendor desligados: 76 modulos = 21,4 MB
        Magento_AdminAdobeIms 9,0 MB · Magento_PaymentServicesPaypal 2,0 MB
        Magento_Swagger 1,6 MB (desligado = bom) · Magento_TwoFactorAuth 0,8 MB

7) 52 modulos custom SEM composer.json
   (normal em app/code, mas nao e o padrao Composer do Magento 2)

============================================================
O QUE ESTA BEM
============================================================
  *** 2.918 arquivos PHP nos custom, 0 erros de sintaxe ***
  nenhum modulo custom realmente vazio (os 4 suspeitos atuam por <preference>)
  nenhum modulo ativo depende de desligado, exceto o caso 4
  Magento_Swagger desligado (nao expoe a API)
  captcha ativo no admin
  Magento_Security, Securitytxt e WebapiSecurity ativos
  app:config:status = "Config files are up to date"

============================================================
METODOLOGIA — 3 CRITERIOS MEUS QUE ERRARAM E FORAM CORRIGIDOS
============================================================
a) v1 listou ~380 "modulos fora do config" no vendor
   -> ERRO MEU: pacote `magento/module-catalog` vira o modulo `Magento_Catalog`,
      nao `module-catalog`. Corrigido o mapeamento. Sobrou 1 real: ReportApi.
b) "33 modulos ativos sem referencia no tema"
   -> CRITERIO RUIM: modulo age por di.xml/events.xml/crontab.xml, sem tocar no tema.
      (mesma licao do item 178: "nao achado no tema" != "nao usado")
c) "4 modulos ativos que nao fazem nada"
   -> FALSO: meu grep so contava <plugin>, nao <preference>.
      Awa_DashboardFix, SmtpFix, MSP e SearchUltimate todos atuam.
   -> ZERO modulos realmente vazios.
d) "219 plugins apontando para classe inexistente"
   -> HEURISTICA RUIM: meu calculo de path falhou para interfaces
      (Magento\Customer\Api\CustomerRepositoryInterface existe).
      Descartado, nao virou conclusao.
