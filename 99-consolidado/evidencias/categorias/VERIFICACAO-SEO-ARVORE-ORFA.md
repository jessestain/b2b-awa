VERIFICACAO: a arvore de categorias orfa causa dano de SEO?
============================================================
Pergunta: a arvore #140 "Catalogo ERP" (orfa, fora da raiz da loja) vaza para
o Google ou cria conteudo duplicado indexavel?

RESPOSTA: *** NAO. O SEO ESTA PROTEGIDO. ***
Eu suspeitei de dano e a medicao refutou a suspeita. Registro a nao-noticia
porque ela tambem e resultado: evita que alguem gaste tempo aqui depois.

EVIDENCIA
  sitemap (pub/sitemap.xml, gerado 05/10 05:02, 418.049 bytes, 739 <url>):
      ocorrencias de 'erp-' .............. *** 0 ***
      as URLs da arvore orfa NAO entram no sitemap
  as URLs -erp- respondem:
      /bauletos-erp-256 ......... HTTP 404
      /retrovisores-erp-274 ..... HTTP 404
      /guidoes-erp-280 .......... HTTP 404
  canonical da categoria real:
      /bauletos -> <link rel="canonical" href="https://awamotos.com/bauletos.html">  OK

POR QUE NAO VAZA: a raiz #140 nao e a root category da store (#2), entao o
Magento nao gera url_rewrite navegavel nem inclui no sitemap. As categorias
duplicadas TEM url_path proprio (ex.: #141 = "bauletos-erp-256"), mas esse
caminho da 404.

O DANO QUE SOBRA (e e grande, mas e interno)
  1) *** 374 produtos ATIVOS so existem na arvore #140 ***
     criterio: "ativo cujo UNICO caminho de categoria e a arvore #140"
     1.034 dos 1.035 ativos tem ligacao na #140 (ela e o espelho completo do catalogo)
     660 estao tambem na #2 (visiveis) e 374 estao SO la (invisiveis na navegacao)
     => o cliente que navega por categoria nunca os ve; so aparecem na BUSCA
  2) *** 9 CATEGORIAS VAZIAS NO MENU ***
     #136 VERIFICAR ....... 0 produtos (o briefing ja citava esta)
     #54  Suportes ........ 0
     #43  Protetor De Carenagem ... 0
     #3   Eletronicos ..... 0  <- categoria DEMO do Magento
     #4   Moda ............ 0  <- DEMO
     #5   Casa e Decoracao  0  <- DEMO
     #6   Esportes ........ 0  <- DEMO
     #138 Ofertas ......... 0
     #139 Atacado B2B ..... 0
     => o cliente clica e ve pagina vazia
     => #54 e #43 estao vazias JUSTAMENTE porque seus produtos estao na arvore orfa
        (#171 SUPORTES. tem 1 ativo; #201 PROTETOR DE CARENAGEM tem 3)

METODOLOGIA — EU ERREI 3 VEZES O MESMO NUMERO ANTES DE FIXAR O CRITERIO
   340  -> contei so as categorias de nivel 2, esqueci os 20 NETOS (nivel 3)
   479  -> contei "ativos sem categoria em 1/2/%", que inclui quem TAMBEM esta na #140
   *** 374 *** -> criterio fixado: "ativo cujo UNICO caminho de categoria e a arvore #140"
   A arvore #140 tem *** 93 categorias *** (1 + 72 + 20), nao 72.
   Licao: FIXAR O CRITERIO ANTES DE MEDIR e escreve-lo junto com o numero.
   Foi a sexta vez no dia que um contador meu saiu errado.
