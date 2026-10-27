<?php
/**
 * AWA Motos — recuperacao de buscas sem resultado, com julgamento do Jev.
 *
 * PROBLEMA: 317 buscas de clientes reais (14,3%) voltaram vazias. Cada uma e uma
 * venda perdida — mas nao do mesmo jeito. "retrovisro" e um erro de digitacao de
 * "retrovisor" (198 resultados); "capacete" e uma linha que a loja nao tem;
 * "1'" e lixo. Tratar os tres igual desperdica esforco.
 *
 * DIVISAO DO TRABALHO (codigo manda, Jev julga):
 *   CODIGO  coleta os termos, remove ruido obvio, busca candidatos REAIS do
 *           catalogo por similaridade, e age conforme a classificacao.
 *   JEV     julga a intencao de cada termo e escolhe, entre os candidatos que o
 *           codigo ofereceu, qual o cliente queria. Nao inventa: escolhe ou diz
 *           que nenhum serve.
 *
 * Uso:
 *   php tools/recuperar-buscas.php                 # analisa (nao escreve nada)
 *   php tools/recuperar-buscas.php --csv=/tmp/x.csv
 *   php tools/recuperar-buscas.php --limite=40
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/autoload.php';
$om = Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();

$opcoes = getopt('', ['csv::', 'limite::', 'dry-run']);
$limite = (int)($opcoes['limite'] ?? 0);
$csv = $opcoes['csv'] ?? null;

$chave = getenv('TYPESAFE_API_KEY');
if (!$chave) {
    fwrite(STDERR, "TYPESAFE_API_KEY nao definida no ambiente.\n");
    exit(2);
}

$pdo = (function () use ($root) {
    $e = include $root . '/app/etc/env.php';
    $d = $e['db']['connection']['default'];
    return new PDO("mysql:host={$d['host']};dbname={$d['dbname']}", $d['username'], $d['password']);
})();

// ─────────────────────────────────────────────────────────────────────────────
// CODIGO — 1. Coleta os termos sem resultado (so clientes reais)
// ─────────────────────────────────────────────────────────────────────────────
$termos = $pdo->query("
    SELECT query AS termo, COUNT(*) AS buscas, MAX(created_at) AS ultima
      FROM mst_search_report_log
     WHERE results = 0
       AND ip NOT LIKE '127.0.0.1%'
       AND query <> ''
     GROUP BY query
     ORDER BY buscas DESC, ultima DESC
")->fetchAll(PDO::FETCH_ASSOC);

printf("Termos sem resultado (clientes reais): %d\n", count($termos));

// ─────────────────────────────────────────────────────────────────────────────
// CODIGO — 2. Descarta ruido obvio ANTES de gastar token do Jev
//    (lixo de teclado, tentativas de injecao, buscas de 1 caractere)
// ─────────────────────────────────────────────────────────────────────────────
$ruido = [];
$uteis = [];
foreach ($termos as $t) {
    $q = trim($t['termo']);
    $ehRuido =
        mb_strlen($q) < 3
        || preg_match('/^[^a-zA-Z0-9]+$/', $q)                       // so simbolos: / ' " )
        || preg_match('/^(zzz|xyz|asdf|qwerty|blabla|zz\d|test|aaa)/i', $q)
        || preg_match('/(naoexiste|semresultado|qqq|xxx)/i', $q);
    if ($ehRuido) { $ruido[] = $t; continue; }
    $uteis[] = $t;
}
printf("  descartados como ruido obvio ....... %d\n", count($ruido));
printf("  seguem para o Jev ................. %d\n", count($uteis));

if ($limite > 0) {
    $uteis = array_slice($uteis, 0, $limite);
    printf("  (limitado a %d para este teste)\n", $limite);
}

// ─────────────────────────────────────────────────────────────────────────────
// CODIGO — 3. Para cada termo, busca CANDIDATOS REAIS no catalogo
//    O Jev so pode escolher o que ve (documentacao explicita sobre cobertura).
// ─────────────────────────────────────────────────────────────────────────────
$indice = $pdo->query("
    SELECT cpe.sku, v.value AS nome
      FROM catalog_product_entity cpe
      JOIN catalog_product_entity_varchar v
        ON v.entity_id = cpe.entity_id AND v.store_id = 0
       AND v.attribute_id = (SELECT attribute_id FROM eav_attribute
                              WHERE attribute_code='name' AND entity_type_id=4)
      JOIN catalog_product_entity_int s
        ON s.entity_id = cpe.entity_id AND s.store_id = 0 AND s.value = 1
       AND s.attribute_id = (SELECT attribute_id FROM eav_attribute
                              WHERE attribute_code='status' AND entity_type_id=4)
")->fetchAll(PDO::FETCH_ASSOC);
printf("Catalogo ativo indexado: %d produtos\n\n", count($indice));

/** Candidatos por similaridade. Ordenados: o codigo decide quem entra, o Jev escolhe entre eles. */
function candidatos(string $termo, array $indice, int $max = 14): array
{
    $alvo = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $termo));
    if ($alvo === '') { return []; }
    $achados = [];

    foreach ($indice as $p) {
        $nome = mb_strtolower($p['nome']);
        $sku  = mb_strtolower(trim($p['sku']));
        $score = 0;

        // SKU: match exato ou contido e o sinal mais forte
        if ($alvo === $sku) { $score += 20; }
        elseif (strlen($alvo) >= 3 && strpos($sku, $alvo) !== false) { $score += 10; }

        // prefixo de 5: pega "retrovisro" vs "retrovisor"
        if (strlen($alvo) >= 5 && strpos($nome, mb_substr($alvo, 0, 5)) !== false) { $score += 4; }
        if (strpos($nome, $alvo) !== false) { $score += 6; }

        // Levenshtein contra o PRIMEIRO token do nome (o nucleo: "retrovisor", "bauleto")
        $tokens = preg_split('/\s+/', $nome) ?: [];
        foreach (array_slice($tokens, 0, 3) as $pos => $tok) {
            $tok = preg_replace('/[^a-z0-9]/', '', $tok);
            if (strlen($tok) < 4) { continue; }
            $d = levenshtein($alvo, $tok);
            if ($d <= 3) { $score += (6 - $d * 2) + ($pos === 0 ? 3 : 0); }
        }

        // Levenshtein contra o nome inteiro sem espacos (pega "1004lade" vs "1004 LA DE")
        $nomeSemEspaco = preg_replace('/[^a-z0-9]/', '', $nome);
        $dNome = levenshtein($alvo, mb_substr($nomeSemEspaco, 0, strlen($alvo) + 4));
        if ($dNome <= 3) { $score += (5 - $dNome); }

        if ($score > 0) { $achados[] = ['sku' => $p['sku'], 'nome' => $p['nome'], 'score' => $score]; }
    }

    usort($achados, fn($a, $b) => $b['score'] <=> $a['score']);

    // Descarta cauda fraca: evita oferecer candidato irrelevante (foi o que gerou "SKU 0").
    if ($achados) {
        $piso = max(4, (int)round($achados[0]['score'] * 0.45));
        $achados = array_values(array_filter($achados, fn($a) => $a['score'] >= $piso));
    }
    return array_slice($achados, 0, $max);
}

// ─────────────────────────────────────────────────────────────────────────────
// JEV — julga intencao e escolhe o candidato (em LOTES, como a doc recomenda)
// ─────────────────────────────────────────────────────────────────────────────
$resultados = [];
$loteTam = 8; // termos por chamada

foreach (array_chunk($uteis, $loteTam) as $lote) {
    $estado = [];
    foreach ($lote as $i => $t) {
        $cand = candidatos($t['termo'], $indice);
        $estado["termo_$i"] = [
            'busca_do_cliente' => $t['termo'],
            'vezes_buscado'    => (int)$t['buscas'],
            'candidatos_do_catalogo' => array_map(
                fn($c, $pos) => [
                    'sku' => $c['sku'],
                    'nome' => $c['nome'],
                    'posicao' => $pos + 1,
                    'proximidade' => $c['score'],
                ],
                $cand,
                array_keys($cand)
            ),
        ];
    }

    // Uma pergunta por termo, todas no mesmo request (rodam em paralelo).
    $perguntas = [];
    foreach ($lote as $i => $t) {
        $cand = candidatos($t['termo'], $indice);
        $temCandidato = $cand !== [];

        $perguntas["intencao_$i"] = [
            'type' => 'choice',
            'instructions' => 'O que o cliente provavelmente queria ao buscar "' . $t['termo']
                . '"? Os candidatos_do_catalogo sao o que a loja realmente tem, ja ordenados por '
                . 'proximidade textual (proximidade alta = mais parecido com o que foi digitado). '
                . 'Considere que esta loja vende pecas e acessorios de moto no Brasil: '
                . 'relacao, pisca, manete, guidao, bauleto, retrovisor, estribo, carenagem, escapamento '
                . 'e nomes de motos (Titan, Fan, Bros, XRE, Factor, Biz, Twister) sao produtos legitimos. '
                . 'Escolha "erro_de_digitacao" SOMENTE se um candidato for de fato o que ele quis. '
                . 'Escolha "produto_ausente" se for produto real de moto que NAO esta nos candidatos. '
                . 'Escolha "peca_de_outra_moto" se for peca de moto de marca/aplicacao fora do catalogo. '
                . 'Escolha "termo_generico" se for categoria ampla, nao um produto especifico. '
                . 'Escolha "ruido" so se nao for busca de produto de moto.',
            'criteria' => [
                'erro_de_digitacao'  => 'Digitou errado um produto que a loja TEM (um candidato e claramente o alvo)',
                'produto_ausente'    => 'Produto real de moto que a loja NAO tem',
                'peca_de_outra_moto' => 'Peca de moto, mas de marca/aplicacao fora do catalogo',
                'termo_generico'     => 'Categoria ampla (ex.: uma palavra generica), nao um produto',
                'ruido'              => 'Nao e busca de produto de moto',
            ],
        ];

        $perguntas["qual_candidato_$i"] = [
            'type' => 'choice',
            'instructions' => 'A busca "' . $t['termo'] . '" foi um erro de digitacao de um produto que a '
                . 'loja tem? Se sim, qual? Se NAO for erro de digitacao, escolha "nenhum". '
                . ($temCandidato
                    ? 'Os candidatos estao ordenados por proximidade textual.'
                    : 'Nao ha candidatos para este termo, entao a resposta e "nenhum".'),
            'criteria' => array_merge(
                ['nenhum' => 'Nao foi erro de digitacao, ou nenhum candidato e o que o cliente quis'],
                array_reduce(
                    $cand,
                    /* Prefixo obrigatorio: chave numerica em PHP vira int, e um array
                     * com chave 0 e serializado como LISTA — o Jev entao responde o
                     * INDICE em vez do SKU e nos devolve "0". Custou uma rodada inteira
                     * de candidatos invalidos ("SKU 0") em 06/10/2026. */
                    function ($acc, $c) {
                        $acc['sku_' . (string) $c['sku']] = 'SKU ' . $c['sku'] . ' — ' . mb_substr($c['nome'], 0, 70);
                        return $acc;
                    },
                    []
                )
            ),
        ];
    }

    $corpo = json_encode([
        'state' => $estado,
        'model' => 'jev-latest',
        'questions' => $perguntas,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.typesafe.ai/v1/systemone');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $corpo,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        printf("  *** lote falhou HTTP %s: %s ***\n", $http, substr((string)$resp, 0, 200));
        continue;
    }
    $json = json_decode((string)$resp, true);
    $ans = $json['answers'] ?? [];

    foreach ($lote as $i => $t) {
        $int = $ans["intencao_$i"] ?? null;
        $cnd = $ans["qual_candidato_$i"] ?? null;
        $resultados[] = [
            'termo'      => $t['termo'],
            'buscas'     => (int)$t['buscas'],
            'intencao'   => $int['choice'] ?? '?',
            'confianca'  => $int['confidence'] ?? null,
            'candidato'  => in_array($cnd['choice'] ?? 'nenhum', ['nenhum', null], true)
                ? ''
                : preg_replace('/^sku_/', '', (string) $cnd['choice']),
            'conf_cand'  => $cnd['confidence'] ?? null,
        ];
    }
    printf("  lote processado: %d termos\n", count($lote));
}

// ─────────────────────────────────────────────────────────────────────────────
// CODIGO — 4. Agrupa por ACAO e ordena por perda (buscas x acionabilidade)
// ─────────────────────────────────────────────────────────────────────────────
$acoes = ['erro_de_digitacao' => [], 'produto_ausente' => [], 'peca_de_outra_moto' => [], 'termo_generico' => [], 'ruido' => [], '?' => []];
foreach ($resultados as $r) {
    $acoes[$r['intencao']][] = $r;
}

echo "\n================ RESULTADO ================\n";
foreach ($acoes as $nome => $itens) {
    if (!$itens) { continue; }
    $perdidas = array_sum(array_column($itens, 'buscas'));
    printf("  %-20s %3d termos · %4d buscas perdidas\n", $nome, count($itens), $perdidas);
}

echo "\n--- AÇÃO 1: CORRIGIR (typo com candidato identificado) ---\n";
$corrigir = array_filter($acoes['erro_de_digitacao'], fn($r) => $r['candidato'] !== '');
usort($corrigir, fn($a, $b) => $b['buscas'] <=> $a['buscas']);
foreach ($corrigir as $r) {
    printf("  %-24s x%-3d -> SKU %-14s (conf %.2f)\n", $r['termo'], $r['buscas'], $r['candidato'], $r['conf_cand'] ?? 0);
}

echo "\n--- AÇÃO 2: AVALIAR CADASTRO (produto real que não temos) ---\n";
usort($acoes['produto_ausente'], fn($a, $b) => $b['buscas'] <=> $a['buscas']);
foreach (array_slice($acoes['produto_ausente'], 0, 20) as $r) {
    printf("  %-24s x%-3d (conf %.2f)\n", $r['termo'], $r['buscas'], $r['confianca'] ?? 0);
}

if ($csv) {
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['termo', 'buscas', 'intencao', 'confianca', 'candidato', 'conf_candidato'], ';');
    foreach ($resultados as $r) {
        fputcsv($fh, [$r['termo'], $r['buscas'], $r['intencao'], $r['confianca'], $r['candidato'], $r['conf_cand']], ';');
    }
    fclose($fh);
    printf("\nCSV: %s\n", $csv);
}
