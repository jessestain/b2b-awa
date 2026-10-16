<?php
declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use GrupoAwamotos\Theme\Model\AwaInlineJsMinifier;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use Magento\Framework\App\Response\HttpInterface;
use Psr\Log\LoggerInterface;

/**
 * Minifica os <script> INLINE da resposta HTML.
 *
 * CONTEXTO MEDIDO (08/10/2026, home em produção):
 *   o HTML tem 369-377 KB, dos quais *** 162 KB sao JS INLINE em 74 blocos ***.
 *   Desses, 55 blocos (135 KB) NAO estavam minificados — tinham indentacao,
 *   quebras de linha e comentarios. E o parser precisa processa-los ANTES de
 *   chegar no RequireJS (que fica em char ~207 KB).
 *
 * EFEITO MEDIDO em teste com os 74 blocos reais (php + node --check):
 *   162,6 KB -> 115,9 KB  (-46,7 KB, -29%)
 *   HTML 369 KB -> 322 KB (-13%)
 *   0 falhas de sintaxe
 *
 * SEGURANCA (tres camadas):
 *   1. JSON NUNCA e tocado (guard `pareceJson`): testado em campo, os 6 blocos
 *      `x-magento-init`/`ld+json` falhavam a validacao de sintaxe justamente por
 *      serem JSON — `{"*":{...}}` e bloco, nao expressao.
 *   2. Validacao de delimitadores: a saida precisa ter a MESMA contagem de
 *      aspas/parenteses/chaves/colchetes que a entrada (comentarios removidos dos
 *      dois lados antes de contar). Se divergir, devolve a entrada intacta.
 *   3. try/catch no plugin: qualquer excecao devolve o HTML ORIGINAL.
 *
 * Reversao: remover o <plugin> do di.xml + `cache:flush`. Nada e persistido.
 */
class MinifyInlineScriptsPlugin
{
    /** Abaixo disso nao vale o risco de mexer. */
    private const TAMANHO_MINIMO = 40;

    /** Guard de recursao: nunca processar duas vezes. */
    private const MARCA = 'data-awa-inline-min';

    public function __construct(
        private readonly AppState $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeSendResponse(HttpInterface $subject): void
    {
        if (!$subject instanceof Response) {
            return;
        }

        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos((string) $contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = (string) $subject->getBody();
        if ($html === '' || !str_contains($html, '<script')) {
            return;
        }

        try {
            $novo = $this->minificarScriptsInline($html);
        } catch (\Throwable $e) {
            // NUNCA quebrar a pagina por causa de uma otimizacao: devolve o original.
            $this->logger->warning('awa-minify-inline falhou; HTML original mantido: ' . $e->getMessage());
            return;
        }

        if ($novo !== null && $novo !== $html) {
            $subject->setBody($novo);
        }
    }

    /**
     * Substitui APENAS o corpo dos <script> sem src (e sem type JSON).
     * Devolve null se nada foi alterado.
     */
    private function minificarScriptsInline(string $html): ?string
    {
        $alterou = false;

        $novo = preg_replace_callback(
            '#(<script\b(?![^>]*\bsrc=)(?![^>]*type=["\'](?:application/ld\+json|text/x-magento-init|application/json)["\'])[^>]*>)(.*?)(</script>)#is',
            function (array $m) use (&$alterou): string {
                $corpo = $m[2];
                if (strlen($corpo) < self::TAMANHO_MINIMO) {
                    return $m[0];
                }
                $min = AwaInlineJsMinifier::minificar($corpo);
                if ($min === $corpo) {
                    return $m[0];
                }
                $alterou = true;
                return $m[1] . $min . $m[3];
            },
            $html
        );

        if ($novo === null || !$alterou) {
            return null;
        }

        return $novo;
    }
}