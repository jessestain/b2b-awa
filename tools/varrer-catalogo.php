<?php
/**
 * Varredura de sanidade do catalogo ATIVO com o Jev.
 *
 * POR QUE ISTO EXISTE
 * A regra do escopo (`CCKATIVO='S' AND CKCOMERCIALIZA='S'`) e booleana e nao sabe
 * o que a loja vende. Entre os 27 que o escopo mandava vender havia uma MESA DE
 * CORTE PARA VIDRO e um TRANSFORMADOR DE 225KVA. Se ha 3 fora do ramo entre 27,
 * pode haver mais entre os 606 que ja estao a venda — e ninguem olhou.
 *
 * Este script olha. Ele leva o catalogo ATIVO inteiro ao Jev e pergunta, por
 * produto, se e item que esta loja deveria vender. O que ele NAO faz: desativar
 * nada sozinho. Ele reporta, e o resultado alimenta o NonCatalogProductList e a
 * decisao do dono.
 *
 * Uso:  php tools/varrer-catalogo.php [--csv=arquivo]
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/autoload.php';

$csv = null;
foreach ($argv as $a) { if (str_starts_with($a, '--csv=')) { $csv = substr($a, 6); } }

$chave = getenv('TYPESAFE_API_KEY');
if (!$chave) { fwrite(STDERR, "TYPESAFE_API_KEY nao definida.\n"); exit(2); }

$e = include $root . '/app/etc/env.php';
$d = $e['db']['connection']['default'];
$pdo = new PDO("mysql:host={$d['host']};dbname={$d['dbname']}", $d['username'], $d['password']);
$iaS = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4")->fetchColumn();
$iaN = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4")->fetchColumn();

$produtos = $pdo->query("
    SELECT cpe.sku,
      (SELECT v.value FROM catalog_product_entity_varchar v
        WHERE v.entity_id=cpe.entity_id AND v.store_id=0 AND v.attribute_id=$iaN) AS nome
      FROM catalog_product_entity cpe
      JOIN catalog_product_entity_int s
        ON s.entity_id=cpe.entity_id AND s.store_id=0 AND s.attribute_id=$iaS AND s.value=1
     ORDER BY cpe.sku
")->fetchAll(PDO::FETCH_ASSOC);

printf("Catalogo ativo: %d produtos\n", count($produtos));

// Lotes de 12 — o mesmo tamanho que funcionou nos outros scripts.
$suspeitos = [];
$total = 0;
foreach (array_chunk($produtos, 12) as $n => $lote) {
    $estado = [];
    $perguntas = [];
    foreach ($lote as $i => $p) {
        $estado["p_$i"] = ['sku' => $p['sku'], 'nome' => $p['nome']];
        $perguntas["vender_$i"] = [
            'type' => 'choice',
            'instructions' => 'Contexto: e uma loja brasileira de pecas e acessorios de MOTO, '
                . 'que vende para lojistas e oficinas. O produto "' . $p['nome'] . '" (SKU ' . $p['sku']
                . ') esta ATIVO e a venda. Ele pertence ao ramo da loja?',
            'criteria' => [
                'sim'            => 'Peca, acessorio ou vestuario de moto — pertence ao ramo',
                'nao_e_do_ramo'  => 'Nao e do ramo: equipamento industrial, ferramenta de outra area, item de outro segmento',
                'ambiguo'        => 'Nao da para decidir pelo nome; precisa olhar o produto',
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
    if ($http !== 200) { printf("  lote %d: HTTP %s\n", $n + 1, $http); continue; }

    $ans = json_decode((string)$resp, true)['answers'] ?? [];
    foreach ($lote as $i => $p) {
        $v = $ans["vender_$i"] ?? [];
        $total++;
        if (($v['choice'] ?? '') !== 'sim') {
            $suspeitos[] = [
                'sku' => $p['sku'],
                'nome' => (string) $p['nome'],
                'veredito' => $v['choice'] ?? '?',
                'conf' => $v['confidence'] ?? 0,
            ];
        }
    }
    if (($n + 1) % 10 === 0) { printf("  %d/%d lotes\n", $n + 1, (int) ceil(count($produtos) / 12)); }
}

printf("\nAvaliados: %d\n", $total);
printf("*** Fora do ramo ou ambiguos: %d (%.1f%%) ***\n\n", count($suspeitos), $total ? count($suspeitos) / $total * 100 : 0);

usort($suspeitos, fn($a, $b) => $b['conf'] <=> $a['conf']);
echo "--- confianca de que NAO e do ramo ---\n";
foreach ($suspeitos as $s) {
    printf("  %-14s %-14s conf=%.2f  %s\n", $s['sku'], $s['veredito'], $s['conf'], mb_substr($s['nome'], 0, 46));
}

if ($csv) {
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['sku', 'nome', 'veredito', 'confianca'], ';');
    foreach ($suspeitos as $s) { fputcsv($fh, [$s['sku'], $s['nome'], $s['veredito'], $s['conf']], ';'); }
    fclose($fh);
    printf("\nCSV: %s\n", $csv);
}
