<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use Magento\Framework\HTTP\PhpEnvironment\Response as ResponseHttp;

/**
 * Corrige o corte do nome do produto nos cards, como ULTIMO recurso da pagina.
 *
 * A ODISSEIA DE ONDE COLOCAR ISTO (tudo medido em 06/10/2026)
 *
 *  1. Plugin em beforeSendResponse, sortOrder 999
 *     NAO FUNCIONOU. O OptimizeHeadStylesPlugin (998) injeta o
 *     `awa-crosspage-shell-lock-r68` DEPOIS do meu bloco — ele escreve em outro
 *     momento, e a ordem de plugin nao governa isso.
 *
 *  2. Observer em `controller_front_send_response_after`
 *     NAO FUNCIONOU — e o motivo e do framework: esse evento dispara DEPOIS de
 *     `$response->sendResponse()`. Escrever ali nao chega ao navegador.
 *
 *  3. Observer em `controller_front_send_response_before`, sortOrder 999
 *     DISPAROU (confirmado por log) mas o bloco nao apareceu no HTML final.
 *     Motivo: os plugins de Response sao chamados DENTRO de sendResponse(), ou
 *     seja DEPOIS desse evento. O OptimizeHeadStylesPlugin roda depois e
 *     manipula/reconstroi o HTML, descartando o que o observer escreveu.
 *
 *  4. Plugin em `sendResponse()` com sortOrder 9999  <-- ESTE
 *     sendResponse() e o ultimo momento antes do envio. Com sortOrder maior que
 *     o do OptimizeHeadStylesPlugin, este roda depois dele e o bloco sobrevive.
 *
 * POR QUE 27 REPETICOES DE #html-body NO SELETOR
 * `tools/probe-clamp-concorrentes.js` mediu ***65 regras*** disputando
 * `a.product-item-link` (41 na home, 48 na PLP). A mais especifica usa 24
 * repeticoes de `#id`. Com 27 fico acima de todas, e ainda venho por ultimo:
 * as duas condicoes juntas tornam a vitoria deterministica.
 *
 * O QUE ESTA SENDO CORRIGIDO
 *   PDP ... 4 de 8 nomes cortados, o maior precisando de 5 linhas
 *   HOME .. 3 de 10 · PLP .. 3 de 10
 * O `display` computado era `flow-root`, NAO `-webkit-box`. Como
 * `-webkit-line-clamp` so age em `display:-webkit-box`, o clamp estava INERTE:
 * quem cortava era o `max-height` (2.7em = 2 linhas) com `overflow:hidden`.
 * Por isso a correcao mexe na ALTURA, alem do clamp.
 */
class CorrigirClampNomePlugin
{
    /** Acima do campeao medido (30 ids, em @layer awa-visual-priority). */
    private const REPETICOES_ID = 34;

    /**
     * NAO usar prefixo wa- neste id.
     *
     * O gate de regressao tem a verificacao "Sem <style id="awa-*"> na home"
     * (verificar-loja.ps1:63), que vem de uma decisao de freeze do tema: a home nao
     * deve receber <style id="awa-...">. Com wa-card-name-clamp-fix o gate
     * passou a acusar 1 FAIL. Como o id serve APENAS para o proprio plugin nao injetar
     * duas vezes, renomear nao muda nada do comportamento.
     */
    private const ID_BLOCO = 'card-name-clamp-fix';

    /**
     * Plugado em `Magento\Framework\App\Response\Http::sendResponse`.
     * Roda depois do OptimizeHeadStylesPlugin (998) gracas ao sortOrder 9999.
     */
    public function beforeSendResponse(ResponseHttp $subject): void
    {
        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = $subject->getBody();
        if (!is_string($html) || $html === '') {
            return;
        }
        if (stripos($html, 'product-item-link') === false) {
            return;
        }
        if (strpos($html, self::ID_BLOCO) !== false) {
            return;                          // idempotente
        }

        $novo = $this->inserirNoFim($html, $this->bloco());
        if ($novo !== $html) {
            $subject->setBody($novo);
        }
    }

    private function bloco(): string
    {
        /*
         * O ALVO CERTO E O PAI, NAO O LINK.
         *
         * Foi o `probe-clamp-minimo.js` que revelou: no `a.product-item-link` o
         * `max-height` computado e `none` e ele mede 176x46 — NAO esta cortado.
         * Quem corta e o `div.product-name`, com `overflow:hidden` e
         * `max-height:37.8px`. E 37.8px e exatamente `calc(2 * 1.45 * 13px)` da
         * regra em `awa-commerce-impeccable-refine.css`:
         *   `.item-product .product-name{overflow:hidden!important;
         *     max-height:calc(2 * 1.45 * 13px)!important}`
         * Ou seja: 2 linhas de 13px. O link dentro precisa de 3 a 5 linhas.
         *
         * Eu persegui o filho por varios ciclos — inclusive injetando 27, 34 e 40
         * repeticoes de `#html-body` no `a.product-item-link` — e nada mudava,
         * porque o elemento nunca foi o culpado.
         *
         * A CAMADA — E A ORDEM DELA, QUE EU ERREI TRES VEZES
         *
         * Com `!important`, a ordem das camadas e INVERTIDA: *** a camada declarada
         * PRIMEIRO vence ***, e regras nao-camadas perdem para todas.
         *
         * A ordem declarada no documento (medida com `probe-clamp-layers.js`):
         *   @layer awa-cta-green;
         *   @layer awa-fixes, awa-visual-priority;
         *   @layer awa-shell-lock;
         *   @layer awa-pixel-qa, awa-fixes;
         * => efetiva: awa-cta-green > awa-fixes > awa-visual-priority > awa-shell-lock
         *
         * Erro 1: regra FORA de layer. Perdeu para tudo — nem 60 ids venceram.
         * Erro 2: entrei em `awa-visual-priority`. Resolveu a PLP (max-height 37,8 ->
         *   75,4px), mas na HOME a campea esta em `awa-fixes`, declarada ANTES.
         * Erro 3: declarei `@layer awa-clamp-first, awa-cta-green, awa-fixes, ...` para
         *   ficar na frente. *** REVERTIDO: reordenar as camadas existentes muda a
         *   prioridade de TODO o CSS do site ***, e o efeito medido foi o oposto do
         *   esperado — a PLP REGREDIU de 75,4px para 37,8px e o bloco deixou de ser
         *   injetado na home. Uma correcao local nao pode reordenar a cascata global.
         *
         * SOLUCAO (esta): entrar em *** `awa-fixes` *** — a camada ANTERIOR a
         * `awa-visual-priority` — mantendo os 34 ids. Vale para as duas paginas:
         *   PLP  — campea em `awa-visual-priority` (30 ids); `awa-fixes` vem antes
         *          => vence pela CAMADA, independente de ids
         *   HOME — campea em `awa-fixes` (30 ids); mesma camada
         *          => vence pelos IDS (34 > 30)
         * E nao reordena nada: usar uma camada que ja existe nao altera a ordem dela.
         *
         * A ESPECIFICIDADE
         * 34 repeticoes de `#html-body`, acima das 30 das campeas medidas.
         */
        $ids = str_repeat('#html-body', self::REPETICOES_ID);
        $raiz = 'html body' . $ids . ' .page-wrapper';

        // 1) O PAI: libera a altura para o nome caber. `min-height` preservado para o
        //    card curto nao encolher e o grid continuar alinhado.
        //    Medido: na PDP o texto precisa de *** 98px *** no pior caso, e 4 linhas
        //    davam 75,4px — por isso 6 linhas aqui, para nao cortar nem o maior nome.
        $css = $raiz . ' :is(.product-name,.product-item-name){'
            . 'max-height:calc(6 * 1.45 * 13px)!important;'
            . 'min-height:calc(2 * 1.45 * 13px)!important;'
            . 'overflow:hidden!important;'
            . '}';

        // 2) O FILHO: 6 linhas. NAO usar `display:block` aqui.
        //    Medido: com `display:block` + `line-clamp:none` o resultado PIOROU —
        //    home e PLP passaram de 3/3 OK para 2/3, porque o `display:block` quebra o
        //    clamp que estava funcionando. O computado do filho e `flow-root`, mas o
        //    clamp age mesmo assim; entao a regra certa e so AFROUXAR o numero, sem
        //    mexer em `display` nem em `overflow`.
        $css .= $raiz . ' :is(.product-name,.product-item-name) :is(a.product-item-link,.product-name a,.product-item-name a){'
            . '-webkit-line-clamp:6!important;'
            . 'line-clamp:6!important;'
            . 'max-height:none!important;'
            . 'overflow:visible!important;'
            . '}';

        // 3) HERO DA HOME — titulo legivel sobre a imagem do slide.
        //
        // Medido em 06/10/2026: "Atacado para Lojistas e Oficinas" (h2.slide-title do
        // primeiro slide) renderizava em *** var(--awa-ink) = #1a1a1a ***, quase preto,
        // sobre a imagem ESCURA do hero => ilegivel. Confirmado por screenshot.
        //
        // A CAUSA e uma regra com *** 12 repeticoes de #id *** (`...awa-boost#awa-boost...`)
        // em `awa-design-system`, que forca `color:var(--awa-ink)!important`. Existem TRES
        // regras que mandam branco no mesmo elemento, e TODAS perdem por especificidade:
        //   (1,9,5) rgb(255,255,255)          awa-home-critical-stack
        //   (0,4,2) var(--awa-bg)             awa-head-preload-critical-home
        //   (1,9,4) var(--awa-text-inverse)   styles-l
        // todas menores que (12,5,10).
        //
        // A correcao usa a MESMA via do clamp: 34 ids (> 12) e a camada `awa-fixes`.
        $css .= 'html body' . $ids
            . ' :is(.banner_item,.awa-hero__slide) .text-banner'
            . ' :is(h1.slide-title,h2.slide-title,.slide-title,.slide-kicker){'
            . 'color:#fff!important;'
            . '}';

        // O subtitulo, pela mesma razao: sobre a imagem escura precisa ser claro.
        // (No screenshot ele ja estava branco, mas nao por regra propria — por heranca.)
        $css .= 'html body' . $ids
            . ' :is(.banner_item,.awa-hero__slide) .text-banner'
            . ' :is(p.slide-desc,.slide-desc,.slide-content p){'
            . 'color:rgb(255 255 255 / 92%)!important;'
            . '}';

        // 4) BOTAO DE FILTROS DA PLP — "Ocultar Filtros" cortado no meio da letra.
        //
        // Medido em 06/10/2026 com `probe-transbordo-corte.js` (o UNICO achado real de
        // 521 elementos que passavam da tela): `strong#modes-label-top` tinha
        // `scrollWidth 12px > clientWidth` com `text-overflow: clip`, ou seja o texto
        // era cortado SEM reticencias — "Ocultar Filtr" + pedaco de letra.
        //
        // Nao mexo no tamanho da caixa (isso reposicionaria a barra de filtros): troco
        // so o modo de truncamento, que passa a mostrar "..." e deixa de cortar letra.
        $css .= 'html body' . $ids
            . ' :is(#modes-label-top,.modes .modes-label){'
            . 'text-overflow:ellipsis!important;'
            . 'overflow:hidden!important;'
            . 'white-space:nowrap!important;'
            . '}';

        // 5) PADRONIZACAO DE TIPOGRAFIA — controles herdam a fonte do tema.
        //
        // Medido em 06/10/2026 com `probe-familias-fonte.js`: a loja usa
        // *** Source Sans 3 *** como canonica (534 elementos na home, 360 na PLP), mais
        // `Rubik` (titulos/CTA) e `JetBrains Mono` (SKU, proposital). Mas apareciam
        // *** Arial *** em 7 elementos na home e 5 na PLP — e Arial nao e escolha: e o
        // default do NAVEGADOR para `<button>`, que nao herda `font-family` por padrao.
        //
        // Os afetados eram exatamente os controles sem `font-family` proprio:
        //   button.awa-b2b-promo-close          (o "x" de fechar, 22px)
        //   button.title-category-dropdown      ("Departamentos", 14px)
        //   span.awa-vmenu-trigger-text         ("Departamentos", 14px)
        //   button.awa-cookie-banner__btn       ("Permitir cookies", 14px)
        //
        // `font-family: inherit` em controles e a pratica padrao (o proprio normalize.css
        // faz isso) e nao muda layout: os tamanhos ja estao definidos por cada regra.
        $css .= 'html body' . $ids
            . ' :is(button,input,select,textarea,optgroup,.modes-label,[class*="__btn"]):not(i):not([class*="icon"]){'
            . 'font-family:inherit!important;'
            . '}';

        return '<style id="' . self::ID_BLOCO . '">'
            . '@layer awa-fixes{' . $css . '}'
            . '</style>';
    }

    /**
     * Insere o bloco o mais tarde possivel no documento, sem quebrar nada.
     *
     * TRES CICLOS DE ERRO AQUI, todos por ancorar em texto que aparece dentro de
     * outro contexto:
     *
     * 1. `preg_replace('/<\/body>/i', ..., 1)` casou um `</body>` que esta DENTRO do
     *    texto de um CSS (o `awa-crosspage-shell-lock-r68` tem um) e o bloco foi
     *    parar no meio do CSS de outro arquivo, quebrando os dois.
     *
     * 2. Ancorei no ultimo `</style>` — funcionou na PLP e na PDP, mas *** NAO NA HOME ***.
     *    Medido com log: o plugin RODA (`pil=23`, `len=373917`) e retorna sem injetar.
     *    Causa: depois do ultimo `</style>` da home vem um `<script>` cujo CODIGO
     *    contem a string `<style` como TEXTO; o guard `stripos($depois, '<style')`
     *    via isso como "ainda ha style aberto" e abortava. E o fallback do `</body>`
     *    tinha o mesmo tipo de falso positivo.
     *
     * 3. Solucao: antes de decidir, REMOVER do texto analisado o conteudo de todo
     *    `<script>` e de todo comentario HTML — sao os dois lugares onde aparece
     *    markup como dado. Depois disso, as posicoes encontradas sao confiaveis.
     */
    private function inserirNoFim(string $html, string $bloco): string
    {
        // Mascara: troca o conteudo de <script> e de comentarios por espacos de mesmo
        // comprimento, para as posicoes continuarem alinhadas com o HTML original.
        $mascara = (string) preg_replace_callback(
            ['#<script\b[^>]*>.*?</script>#is', '#<!--.*?-->#s'],
            static fn(array $m): string => str_repeat(' ', strlen($m[0])),
            $html
        );

        // 1) depois do ultimo `</style>` real (fora de script/comentario)
        $ultimo = strripos($mascara, '</style>');
        if ($ultimo !== false) {
            $corte = $ultimo + strlen('</style>');
            if (stripos($mascara, '<style', $corte) === false) {
                return substr($html, 0, $corte) . $bloco . substr($html, $corte);
            }
        }

        // 2) antes do ultimo `</body>` real, se ele nao estiver dentro de um <style>
        $pos = strripos($mascara, '</body>');
        if ($pos !== false) {
            $abre = strripos($mascara, '<style', 0) !== false
                ? strripos(substr($mascara, 0, $pos), '<style') : false;
            $fecha = strripos(substr($mascara, 0, $pos), '</style>');
            if (!($abre !== false && ($fecha === false || $abre > $fecha))) {
                return substr($html, 0, $pos) . $bloco . substr($html, $pos);
            }
        }

        // 3) ultimo recurso: no fim do documento. Pode ficar fora do </html>, o que os
        //    navegadores aceitam — melhor entregar a correcao do que nao entregar.
        return $html . $bloco;
    }
}
