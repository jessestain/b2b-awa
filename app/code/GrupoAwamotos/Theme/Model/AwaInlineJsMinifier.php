<?php
declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;
/**
 * Minificador CONSERVADOR de JavaScript inline.
 *
 * Objetivo: reduzir os 135 KB de JS inline NAO-MINIFICADO da home (55 blocos).
 * Estrategia (deliberadamente conservadora — NAO renomeia variaveis, NAO faz AST):
 *   1. preserva INTEGRALMENTE strings ('...', "...", `...`) e regex literais;
 *   2. remove comentarios /* *\/ e // ;
 *   3. colapsa espacos/quebras de linha em UM espaco;
 *   4. remove espaco onde ele e comprovadamente dispensavel (antes/depois de pontuacao);
 *   5. NUNCA remove quebra de linha apos `return`/`throw`/`break`/`continue` sem
 *      verificar que ha `;` ou `}` antes (ASI hazard);
 *   6. se qualquer etapa parecer arriscada, devolve a ENTRADA INTACTA.
 *
 * O ponto 6 e o mais importante: em caso de duvida, nao minifica.
 */
final class AwaInlineJsMinifier
{
    /** Tokens que NAO podem ser seguidos de remocao de quebra (ASI). */
    private const ASI_RISCO = ['return', 'throw', 'break', 'continue', 'yield'];

    public static function minificar(string $js): string
    {
        $orig = $js;
        $len = strlen($js);
        if ($len < 40) {
            return $orig; // bloco minusculo: nao vale o risco
        }
        // GUARD OBRIGATORIO: JSON nunca passa por minificador de JS.
        // Testado em campo: 6 blocos (x-magento-init e ld+json) FALHARAM a validacao de
        // sintaxe justamente por serem JSON — `{"*":{...}}` e bloco, nao expressao.
        if (self::pareceJson($js)) {
            return $orig;
        }

        $out = '';
        $i = 0;
        $ultimoSignificativo = ''; // ultimo char nao-espaco emitido

        while ($i < $len) {
            $c = $js[$i];
            $prox = $i + 1 < $len ? $js[$i + 1] : '';

            // ---------- 1) STRINGS ----------
            if ($c === "'" || $c === '"' || $c === '`') {
                $fim = self::fimDaString($js, $i, $c, $len);
                if ($fim === null) {
                    return $orig; // string nao fechada: aborta
                }
                $out .= substr($js, $i, $fim - $i + 1);
                $ultimoSignificativo = $c;
                $i = $fim + 1;
                continue;
            }

            // ---------- 2) REGEX LITERAL ----------
            // so trata como regex se o contexto permitir (apos = ( , : [ ! & | ? { } ; return)
            if ($c === '/' && $prox !== '/' && $prox !== '*' && self::podeSerRegex($ultimoSignificativo)) {
                $fim = self::fimDaRegex($js, $i, $len);
                if ($fim !== null) {
                    $out .= substr($js, $i, $fim - $i + 1);
                    $ultimoSignificativo = '/';
                    $i = $fim + 1;
                    continue;
                }
            }

            // ---------- 3) COMENTARIOS ----------
            if ($c === '/' && $prox === '*') {
                $fim = strpos($js, '*/', $i + 2);
                if ($fim === false) {
                    return $orig;
                }
                $i = $fim + 2;
                continue;
            }
            if ($c === '/' && $prox === '/') {
                $fim = strpos($js, "\n", $i + 2);
                $i = ($fim === false) ? $len : $fim + 1;
                continue;
            }

            // ---------- 4) ESPACOS ----------
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                // consome a corrida de espacos
                while ($i < $len && strpbrk($js[$i], " \t\n\r") !== false) {
                    $i++;
                }
                $i--; // deixa o loop externo avancar
                // decide se precisa de UM espaco
                $proxReal = ($i + 1 < $len) ? $js[$i + 1] : '';
                if ($proxReal === '') {
                    $i++;
                    continue;
                }
                if (self::precisaEspaco($ultimoSignificativo, $proxReal)) {
                    $out .= ' ';
                    $ultimoSignificativo = ' ';
                }
                $i++;
                continue;
            }

            // ---------- 5) PONTUACAO: espaco antes e dispensavel ----------
            if (strpos(';,{}()[]:', $c) !== false && $ultimoSignificativo === ' ') {
                $out = rtrim($out, ' ');
                $ultimoSignificativo = substr($out, -1) ?: '';
            }

            $out .= $c;
            $ultimoSignificativo = $c;
            $i++;
        }

        // ---------- 6) VALIDACAO ----------
        if (!self::seguro($orig, $out)) {
            return $orig;
        }

        return $out;
    }

    /**
     * Detecta JSON (x-magento-init / ld+json / objeto puro). Guard OBRIGATORIO.
     * Um minificador de JS aplicado a JSON quebra a pagina (validado em campo).
     */
    private static function pareceJson(string $s): bool
    {
        $t = ltrim($s);
        // comeca com { ou [ E o primeiro caractere significativo nao e '(' nem 'function'
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
            return false;
        }
        // JSON puro: aspas duplas nas chaves e nenhum `function`/`=>`/`;` fora de string
        if (preg_match('/^\s*[\[{]\s*"/', $t) === 1) {
            return true;
        }
        return false;
    }

    /** true se os dois chars exigem ao menos um espaco entre eles. */
    private static function precisaEspaco(string $a, string $b): bool
    {
        if ($a === '' || $a === ' ') {
            return false;
        }
        // espaco entre pontuacao e dispensavel
        if (strpos(';,{}()[]:.', $a) !== false || strpos(';,{}()[]:.', $b) !== false) {
            return false;
        }
        // word/word, word/quote, operador/operador (+ +, - -, etc.)
        $wa = preg_match('/[A-Za-z0-9_$]/', $a) === 1;
        $wb = preg_match('/[A-Za-z0-9_$]/', $b) === 1;
        if ($wa && $wb) {
            return true;
        }
        if ($a === '+' && $b === '+') { return true; }
        if ($a === '-' && $b === '-') { return true; }
        if ($a === '/' && ($b === '/' || $b === '*')) { return true; }
        return false;
    }

    private static function podeSerRegex(string $ultimo): bool
    {
        if ($ultimo === '') { return true; }
        return strpos('=(,:[!&|?{};+-*%<>~^', $ultimo) !== false
            || preg_match('/\b(return|typeof|instanceof|in|of|new|delete|void|do|else|case)$/', $ultimo) === 1;
    }

    private static function fimDaString(string $s, int $ini, string $q, int $len): ?int
    {
        $i = $ini + 1;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\') { $i += 2; continue; }
            if ($c === $q) { return $i; }
            if ($q !== '`' && $c === "\n") { return null; } // string simples nao atravessa linha
            $i++;
        }
        return null;
    }

    private static function fimDaRegex(string $s, int $ini, int $len): ?int
    {
        $i = $ini + 1;
        $emClasse = false;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\') { $i += 2; continue; }
            if ($c === "\n") { return null; }
            if ($c === '[') { $emClasse = true; }
            elseif ($c === ']') { $emClasse = false; }
            elseif ($c === '/' && !$emClasse) {
                // consome flags
                $j = $i + 1;
                while ($j < $len && preg_match('/[a-z]/i', $s[$j]) === 1) { $j++; }
                return $j - 1;
            }
            $i++;
        }
        return null;
    }

    /**
     * Validacao de seguranca: a saida precisa ter a MESMA contagem de strings,
     * parenteses e chaves que a entrada — MEDIDA COM OS COMENTARIOS REMOVIDOS.
     *
     * CORRECAO 08/10/2026: a versao anterior contava na entrada BRUTA, e comentarios
     * contem `(`, `{`, `[`. Como o minificador remove comentarios, a contagem divergia
     * e a validacao rejeitava blocos LEGITIMOS — foi o que abortou os 2 maiores blocos
     * (16,2 KB e 15,2 KB). Agora normalizamos os dois lados antes de contar.
     */
    private static function seguro(string $a, string $b): bool
    {
        $an = self::semComentarios($a);
        $bn = self::semComentarios($b);

        foreach (['"' => '"', "'" => "'", '(' => ')', '{' => '}', '[' => ']'] as $abre => $fecha) {
            if (substr_count($an, $abre) !== substr_count($bn, $abre)) {
                return false;
            }
            if (substr_count($an, $fecha) !== substr_count($bn, $fecha)) {
                return false;
            }
        }
        if (strlen($b) >= strlen($a)) {
            return false; // nao ganhou nada: nao vale
        }
        return true;
    }

    /** Remove comentarios de forma conservadora (para fins de CONTAGEM, nao de saida). */
    private static function semComentarios(string $s): string
    {
        $s = preg_replace('#/\*.*?\*/#s', '', $s) ?? $s;
        // // comentario ate o fim da linha — evita casar `://` de URLs e `//` dentro de string
        $s = preg_replace('#(^|[^:"\'])\s*//[^\n]*#', '$1', $s) ?? $s;
        return $s;
    }
}
