<?php
/**
 * AWA Motos — corrigir os typos reais dos clientes usando os grupos de sinonimos.
 *
 * O QUE JA EXISTE
 * A loja tem 36 grupos em `search_synonyms`, feitos a mao, e eles FUNCIONAM:
 * `espelho` acha os retrovisores, `bau` acha os bauletos. Mas os erros de digitacao
 * que os clientes de fato cometem nao estao la: `retrovisro`, `guidam`, `picas`.
 * Medido em 06/10/2026: 27 buscas sem resultado em 19 termos que sao claramente
 * variacoes de algo que a loja tem.
 *
 * POR QUE SINONIMO E MELHOR QUE REDIRECT
 * Um redirect leva o cliente a uma URL fixa. Um sinonimo faz a BUSCA achar os
 * produtos — inclusive quando o termo aparece junto com outros. E aditivo: nao
 * muda nenhuma busca que ja funciona.
 *
 * DIVISAO DO TRABALHO (o mesmo padrao dos outros scripts)
 *   CODIGO  pega o termo e o SKU candidato, descobre o NOME do produto, extrai a
 *           palavra-nucleo, e localiza qual grupo de sinonimos ja contem essa palavra.
 *   JEV     confirma se o termo e mesmo uma variacao daquele produto e daquela
 *           palavra — ou se e outra coisa, e nao deve entrar no grupo.
 *
 * Uso:
 *   php tools/corrigir-sinonimos.php              # analisa
 *   php tools/corrigir-sinonimos.php --aplicar
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/autoload.php';

$aplicar = in_array('--aplicar', $argv, true);
$entrada = null;
foreach ($argv as $a) { if (str_starts_with($a, '--csv=')) { $entrada = substr($a, 6); } }

$chave = getenv('TYPESAFE_API_KEY');
if (!$chave) { fwrite(STDERR, "TYPESAFE_API_KEY nao definida.\n"); exit(2); }

$e = include $root . '/app/etc/env.php';
$d = $e['db']['connection']['default'];
$pdo = new PDO("mysql:host={$d['host']};dbname={$d['dbname']}", $d['username'], $d['password']);
$iaN = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4")->fetchColumn();

// ── CODIGO: a lista de correcoes que o Jev produziu em recuperar-buscas.php ──
// Formato esperado do CSV: termo;buscas;intencao;confianca;candidato;conf_candidato
if (!$entrada || !is_readable($entrada)) {
    fwrite(STDERR, "Informe --csv=<arquivo> produzido por tools/recuperar-buscas.php\n");
    exit(2);
}
$linhas = array_map('str_getcsv', file($entrada), array_fill(0, count(file($entrada)), ';'));
$cabecalho = array_shift($linhas);
$idx = array_flip($cabecalho);

$alvos = [];
foreach ($linhas as $l) {
    if (count($l) < count($cabecalho)) { continue; }
    $intencao = $l[$idx['intencao']] ?? '';
    $cand = trim((string) ($l[$idx['candidato']] ?? ''));
    if ($intencao !== 'erro_de_digitacao' || $cand === '') { continue; }
    $alvos[] = ['termo' => $l[$idx['termo']], 'sku' => $cand, 'buscas' => (int) $l[$idx['buscas']]];
}
printf("Correcoes candidatas (typo + SKU identificado): %d\n\n", count($alvos));
if (!$alvos) { exit(0); }

// ── CODIGO: nome do produto e a palavra-nucleo ──────────────────────────────
$grupos = $pdo->query("SELECT group_id, synonyms FROM search_synonyms WHERE store_id=0 AND website_id=0")->fetchAll(PDO::FETCH_ASSOC);
$porPalavra = [];
foreach ($grupos as $g) {
    foreach (array_map('trim', explode(',', mb_strtolower($g['synonyms']))) as $s) {
        if ($s !== '') { $porPalavra[$s] = (int) $g['group_id']; }
    }
}

$propostas = [];
foreach ($alvos as $a) {
    $nome = (string) $pdo->query(
        "SELECT v.value FROM catalog_product_entity cpe
           JOIN catalog_product_entity_varchar v
             ON v.entity_id=cpe.entity_id AND v.store_id=0 AND v.attribute_id=$iaN
          WHERE cpe.sku=" . $pdo->quote($a['sku'])
    )->fetchColumn();
    if ($nome === '') { continue; }

    // Palavra-nucleo: o primeiro token com 4+ letras do nome do produto.
    $nucleo = '';
    $candidatosNucleo = [];
    foreach (preg_split('/\s+/', mb_strtolower($nome)) as $tok) {
        $tok = preg_replace('/[^a-z0-9]/u', '', $tok);
        if (mb_strlen($tok) >= 4) { $candidatosNucleo[] = $tok; }
    }
    // Prefere um token que JA exista na tabela de sinonimos.
    foreach ($candidatosNucleo as $tok) {
        if (isset($porPalavra[$tok])) { $nucleo = $tok; break; }
    }
    if ($nucleo === '' && $candidatosNucleo) { $nucleo = $candidatosNucleo[0]; }

    $propostas[] = [
        'termo' => $a['termo'],
        'sku' => $a['sku'],
        'buscas' => $a['buscas'],
        'nome' => $nome,
        'nucleo' => $nucleo,
        'grupo' => $porPalavra[$nucleo] ?? null,
        'ja_existe' => isset($porPalavra[mb_strtolower($a['termo'])]),
    ];
}

// ── JEV: confirma cada proposta, todas num request ──────────────────────────
$perguntas = [];
$estado = [];
foreach ($propostas as $i => $p) {
    $estado["c_$i"] = [
        'termo_digitado' => $p['termo'],
        'produto_encontrado' => $p['nome'],
        'sku' => $p['sku'],
        'palavra_nucleo' => $p['nucleo'],
        'grupo_sinonimos_existente' => $p['grupo'],
    ];
    $perguntas["confirmar_$i"] = [
        'type' => 'choice',
        'instructions' => 'Um cliente buscou "' . $p['termo'] . '" na loja e nao achou nada. '
            . 'O candidato mais proximo do catalogo e "' . $p['nome'] . '" (SKU ' . $p['sku'] . '). '
            . 'A loja tem um grupo de sinonimos de busca cuja palavra-nucleo e "' . $p['nucleo'] . '". '
            . 'Decida: o termo digitado e uma variacao (erro de digitacao, abreviacao ou apelido) '
            . 'desse produto, a ponto de valer adiciona-lo ao grupo de sinonimos "' . $p['nucleo'] . '"?',
        'criteria' => [
            'adicionar'          => 'Sim: "termo_digitado" e inequivocamente uma variacao de "palavra_nucleo"',
            'adicionar_com_duvida' => 'Provavelmente sim, mas o termo e ambiguo e pode significar outra coisa',
            'nao_adicionar'      => 'Nao: o termo significa outra coisa, ou o candidato nao corresponde',
        ],
    ];
}

$ch = curl_init('https://api.typesafe.ai/v1/systemone');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['state' => $estado, 'model' => 'jev-latest', 'questions' => $perguntas], JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200) { fwrite(STDERR, "HTTP $http: " . substr((string)$resp, 0, 300) . "\n"); exit(1); }
$ans = json_decode((string)$resp, true)['answers'] ?? [];

// ── CODIGO: aplica so o inequivoco ──────────────────────────────────────────
$aplicarLista = [];
echo "================ PROPOSTAS ================\n";
foreach ($propostas as $i => $p) {
    $v = $ans["confirmar_$i"] ?? [];
    $decisao = $v['choice'] ?? '?';
    $conf = $v['confidence'] ?? 0;
    printf("  %-14s x%-3d -> %-26s nucleo=%-12s grupo=%-5s %s (conf %.2f)\n",
        $p['termo'], $p['buscas'], mb_substr($p['nome'], 0, 25), $p['nucleo'],
        $p['grupo'] ?? '—', strtoupper($decisao), $conf);
    if ($decisao === 'adicionar' && $p['grupo'] !== null && !$p['ja_existe']) {
        $aplicarLista[] = $p + ['grupo' => $p['grupo']];
    }
}

printf("\nVao para os sinonimos: %d termos (%d buscas)\n", count($aplicarLista), array_sum(array_column($aplicarLista, 'buscas')));

if ($aplicar && $aplicarLista) {
    echo "\n================ APLICANDO ================\n";
    $porGrupo = [];
    /* trim() e obrigatorio: o Magento faz explode(',') sem trim no SynonymReader, e
     * um termo com espaco no fim NUNCA casa — foi o que deixou 'picas ' inerte no
     * grupo 9 enquanto 'retrovisro' (sem espaco) funcionava. */
    foreach ($aplicarLista as $p) { $porGrupo[$p['grupo']][] = mb_strtolower(trim($p['termo'])); }

    $backup = [];
    foreach ($porGrupo as $gid => $termos) {
        $atual = (string) $pdo->query("SELECT synonyms FROM search_synonyms WHERE group_id=" . (int) $gid)->fetchColumn();
        $backup[$gid] = $atual;
        $novo = $atual . ',' . implode(',', $termos);
        $pdo->exec("UPDATE search_synonyms SET synonyms=" . $pdo->quote($novo) . " WHERE group_id=" . (int) $gid);
        printf("  grupo %-3s +%d termo(s)  ->  %s\n", $gid, count($termos), mb_substr($novo, 0, 90));
    }
    file_put_contents('/tmp/sinonimos-backup.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\n  backup do valor anterior: /tmp/sinonimos-backup.json\n";
}
