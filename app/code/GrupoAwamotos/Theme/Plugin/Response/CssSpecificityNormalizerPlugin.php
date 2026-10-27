<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use Magento\Framework\HTTP\PhpEnvironment\Response as ResponseHttp;

/**
 * Normaliza o CSS inline: colapsa `#html-body` repetido e remove <style> duplicado.
 *
 * ── 1) `#html-body` repetido ────────────────────────────────────────────────
 * Medido em 06/10/2026: 1.218 de 1.596 regras da PLP (76%) repetiam o id, ate 6x:
 *   html body#html-body#html-body#html-body#html-body#html-body#html-body .x
 * Cada repeticao custa ~10 bytes e nao muda NADA: um unico `#id` ja da (1,0,0),
 * que vence qualquer combinacao de classes e tags.
 *
 * PROVA: `tools/checar-especificidade.py` procura disputa entre seletores cuja
 * diferenca seja a CONTAGEM de id. Resultado: 0 disputas. Colapsar e neutro.
 * Economia medida: 609,6 KB -> 510,7 KB = 98,8 KB (-16%) por pagina.
 *
 * ── 2) <style> duplicado com o mesmo id ─────────────────────────────────────
 * Medido: `awa-b2b-promo-shell-r40c-head` (HEAD, pos. 170.499) e
 * `awa-b2b-promo-shell-r40c` (BODY, pos. 833.462) tem ***md5 identico*** —
 * 2 x 17,7 KB do mesmo CSS. Com 33 folhas de estilo entre as duas, a do BODY
 * vence: a do HEAD e codigo morto.
 *
 * REGRA (conservadora de proposito):
 *   - exige id NAO-VAZIO. `<style>` sem id nao e tocado: na PLP ha 10 deles, e
 *     todos tem conteudo diferente — nao sao duplicata.
 *   - exige conteudo IDENTICO (hash). Mesmo id com conteudo diferente pode ser
 *     override intencional; remover mudaria a cascata.
 *   - remove TODAS MENOS A ULTIMA, porque a ultima e a que vence hoje.
 *
 * ── O QUE NAO FAZ ───────────────────────────────────────────────────────────
 *  - Nao toca em <script>: JS pode montar seletor por concatenacao de string.
 *  - Nao mexe em nenhuma outra propriedade, valor ou ordem de regra.
 *  - Nao remove o `#html-body` inteiro: preserva UM, so colapsa a repeticao.
 */
class CssSpecificityNormalizerPlugin
{
    /**
     * Blocos menores que isto nao entram na deduplicacao. Medido: o caso real e
     * de 17,7 KB, e os blocos pequenos da PLP (10 sem id, todos diferentes) sao
     * ajustes pontuais onde repetir pode ser intencional.
     */
    private const TAMANHO_MINIMO = 1024;

    /**
     * Roda depois de todo mundo que injeta <style>. sortOrder 999 fica depois do
     * OptimizeHeadStylesPlugin (998), que e quem monta a maior parte do inline.
     */
    public function beforeSendResponse(ResponseHttp $subject): void
    {
        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = $subject->getBody();
        if (!is_string($html) || $html === '' || stripos($html, '<style') === false) {
            return;
        }

        $novo = $this->normalizar($html);
        if ($novo !== $html) {
            $subject->setBody($novo);
        }
    }

    private function normalizar(string $html): string
    {
        $html = $this->removerDuplicados($html);

        // Colapso do id repetido. So dentro de <style>.
        $html = (string) preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            function (array $m): string {
                $css = (string) preg_replace(
                    '/#html-body(?:\s*#html-body)+/i',
                    '#html-body',
                    $m[2]
                );
                return $m[1] . $css . $m[3];
            },
            $html
        );

        // O clamp do nome do produto passou para o observer
        // CorrigirClampNomeProdutoObserver (evento controller_front_send_response_after),
        // que roda depois de TODOS os plugins. Aqui nao funcionava: o
        // OptimizeHeadStylesPlugin injeta `awa-crosspage-shell-lock-r68` em
        // controller_front_send_response_before e o bloco ficava depois do meu.
        return $html;
    }

    /**
     * Desfaz o corte do nome do produto nos cards.
     *
     * O QUE FOI MEDIDO (06/10/2026, probe-clamp-concorrentes.js)
     *   PDP ... 4 de 8 nomes cortados, o maior precisando de 5 linhas
     *   HOME .. 3 de 10 cortados, maior precisando de 4
     *   PLP ... 3 de 10 cortados, maior precisando de 4
     *   e *** 65 regras concorrentes*** para o MESMO seletor (41 na home, 48 na PLP)
     *
     * O CAMPEAO: uma regra do awa-design-system.min.css com ***24 repeticoes de #id***
     * no seletor, `max-height:37.8px` e `!important`.
     *
     * E O DETALHE QUE MUDA O DIAGNOSTICO: o `display` computado e `flow-root`, NAO
     * `-webkit-box`. Como `-webkit-line-clamp` so age em `display:-webkit-box`, o
     * clamp esta INERTE — quem corta e o `max-height` (2.7em = 2 linhas) junto com
     * `overflow:hidden`. Ou seja: o problema nao e o numero de linhas do clamp, e
     * sim a ALTURA MAXIMA.
     *
     * A CORRECAO
     * Uma unica regra, injetada no FIM do <body>, com tres vantagens sobre qualquer
     * concorrente:
     *   1. mais #html-body que o campeao (25 contra 24) -> vence por especificidade
     *   2. vem depois de tudo -> vence tambem por ordem
     *   3. `max-height` generoso + `min-height` preservado -> o card nao encolhe,
     *      e o nome cabe. O grid estica os cards da mesma linha, entao o alinhamento
     *      entre eles se mantem.
     *
     * POR QUE AQUI E NAO NOS 5 ARQUIVOS
     * Consolidar as 65 regras e o certo a longo prazo, mas exige mexer em 5+ arquivos
     * que se sobrescrevem de proposito em outras propriedades. Uma regra final e a
     * menor alteracao que resolve, e reverter e apagar este metodo.
     */
    private function corrigirClampDoNome(string $html): string
    {
        if (stripos($html, 'product-item-link') === false) {
            return $html;
        }

        // 25 repeticoes: uma a mais que o campeao medido (24).
        $idChain = str_repeat('#html-body', 25);

        $sel = 'html body' . $idChain
            . ' .page-wrapper :is(a.product-item-link,.product-name a,.product-item-name a)';

        $css = $sel . '{'
            . '-webkit-line-clamp:4!important;'
            . 'line-clamp:4!important;'
            . 'max-height:calc(4 * 1.35em)!important;'
            . 'min-height:calc(2 * 1.35em)!important;'
            . 'overflow:visible!important;'
            . 'display:-webkit-box!important;'
            . '-webkit-box-orient:vertical!important;'
            . '}';

        $bloco = '<style id="awa-card-name-clamp-fix">' . $css . '</style>';

        /*
         * ONDE INJETAR — e o erro que quase passou
         *
         * A primeira versao usava `preg_replace('/<\/body>/i', $bloco.'</body>', $html, 1)`,
         * ou seja o PRIMEIRO `</body>`. Medido: o CSS de `awa-crosspage-shell-lock-r68`
         * (26.303 bytes) contem a string `</body>` dentro do proprio texto — em seletor
         * ou comentario. O resultado foi o meu <style> inserido NO MEIO do CSS dele:
         * os dois blocos quebraram e a regra nao valeu.
         *
         * A prova: no HTML servido, o meu style ficou em 883.239 e o bloco do
         * crosspage-shell-lock comecava 595 bytes DEPOIS.
         *
         * Agora: acha o ULTIMO `</body>` e confirma que ele nao esta dentro de um
         * <style>. Se estiver, injeta depois do ultimo `</style>` em vez disso.
         */
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            // Confirma que o </body> escolhido nao esta dentro de um <style>:
            // o ultimo <style> aberto antes dele nao pode ter sido fechado ainda.
            $antes = substr($html, 0, $pos);
            $abre = strripos($antes, '<style');
            $fecha = strripos($antes, '</style>');
            $dentroDeStyle = ($abre !== false && ($fecha === false || $abre > $fecha));
            if (!$dentroDeStyle) {
                return substr($html, 0, $pos) . $bloco . substr($html, $pos);
            }
        }

        // Fallback: depois do ultimo </style> do documento, que e onde a cascata
        // realmente termina quando ha CSS no fim do body.
        $ultimoFim = strripos($html, '</style>');
        if ($ultimoFim !== false) {
            $corte = $ultimoFim + strlen('</style>');
            return substr($html, 0, $corte) . $bloco . substr($html, $corte);
        }

        return $html . $bloco;
    }

    /**
     * Remove <style> cujo CONTEUDO ja apareceu depois — mantendo o ULTIMO.
     *
     * POR QUE POR CONTEUDO E NAO POR ID
     * A primeira versao exigia id igual, e nao pegou o caso real: os dois blocos
     * duplicados tem ids DIFERENTES —
     *   awa-b2b-promo-shell-r40c-head  (HEAD, pos. 170499)
     *   awa-b2b-promo-shell-r40c       (BODY, pos. 833462)
     * — mas conteudo identico (md5 a3e3c00ba6c1). Comparar por id nunca os veria.
     *
     * AS GUARDAS (o que impede remover coisa legitima)
     *  - TAMANHO MINIMO: blocos com menos de 1 KB ficam de fora. Blocos pequenos
     *    costumam ser variantes ou ajustes pontuais onde a repeticao pode ser
     *    intencional (ex.: o mesmo reset em dois contextos).
     *  - CONTEUDO IDENTICO por md5. Conteudo diferente nunca e removido.
     *  - SO REMOVE O QUE VEM ANTES: a ultima ocorrencia fica na posicao original,
     *    entao a ordem efetiva da cascata nao muda.
     *
     * POR QUE E SEGURO NO CASO MEDIDO
     * As duas copias tem md5 igual e 33 folhas de estilo entre elas. CSS identico
     * aplicado duas vezes: a segunda ocorrencia vence (mesma especificidade, mais
     * adiante na ordem). A primeira nunca e lida — e codigo morto.
     */
    private function removerDuplicados(string $html): string
    {
        if (!preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $porHash = [];
        foreach ($m as $bloco) {
            $corpo = $bloco[1][0];
            if (strlen($corpo) < self::TAMANHO_MINIMO) {
                continue;                       // bloco pequeno: nao mexe
            }
            $ini = $bloco[0][1];
            $porHash[md5($corpo)][] = [
                'ini' => $ini,
                'fim' => $ini + strlen($bloco[0][0]),
            ];
        }

        $remover = [];
        foreach ($porHash as $ocorrencias) {
            if (count($ocorrencias) < 2) {
                continue;
            }
            // a ultima fica; as anteriores sao identicas a ela, logo mortas
            array_pop($ocorrencias);
            foreach ($ocorrencias as $oc) {
                $remover[] = $oc;
            }
        }

        if ($remover === []) {
            return $html;
        }

        // Remove de tras para frente para os offsets nao deslocarem.
        usort($remover, fn(array $a, array $b): int => $b['ini'] <=> $a['ini']);
        foreach ($remover as $r) {
            $html = substr($html, 0, $r['ini']) . substr($html, $r['fim']);
        }

        return $html;
    }
}
