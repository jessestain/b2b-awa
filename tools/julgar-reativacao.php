<?php
/**
 * AWA Motos — julgar os 27 produtos que o Sectra manda vender mas estao inativos.
 *
 * POR QUE ISTO EXISTE
 * O sync desativa quem sai do escopo (CCKATIVO='S' AND CKCOMERCIALIZA='S'), mas
 * NUNCA reativa quem volta. Encontramos 27 produtos nessa situacao: o Sectra diz
 * "venda", a loja diz "inativo". Reativar todos as cegas seria errado — a lista
 * inclui uma mesa de corte para vidro de 3200mm, um transformador de 225KVA e 24
 * itens com preco R$ 0,01. Isso e uma decisao caso a caso, e e onde o julgamento
 * semantico vale mais que uma regra.
 *
 * DIVISAO DO TRABALHO:
 *   CODIGO  coleta os 27, o nome, o preco, a foto, e se o SKU tem cara de codigo
 *           de fabricante. Decide o que fazer com cada veredito.
 *   JEV     julga, por produto, se e item que esta loja deve vender, e por que nao.
 *           Nao gera texto: escolhe entre opcoes fechadas.
 *
 * Uso:  php tools/julgar-reativacao.php [--aplicar]
 */
declare(strict_types=1);

$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/autoload.php';
$om = Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();

$aplicar = in_array('--aplicar', $argv, true);
$csv = null;
foreach ($argv as $a) { if (str_starts_with($a, '--csv=')) { $csv = substr($a, 6); } }

$chave = getenv('TYPESAFE_API_KEY');
if (!$chave) { fwrite(STDERR, "TYPESAFE_API_KEY nao definida.\n"); exit(2); }

$e = include $root . '/app/etc/env.php';
$d = $e['db']['connection']['default'];
$pdo = new PDO("mysql:host={$d['host']};dbname={$d['dbname']}", $d['username'], $d['password']);
$erp = $om->get(GrupoAwamotos\ERPIntegration\Api\ConnectionInterface::class);

$iaS = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4")->fetchColumn();
$iaN = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4")->fetchColumn();
$iaP = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4")->fetchColumn();
$iaI = $pdo->query("SELECT attribute_id FROM eav_attribute WHERE attribute_code='image' AND entity_type_id=4")->fetchColumn();

// ── CODIGO: o escopo que o dono definiu ──────────────────────────────────────
$escopo = [];
foreach ($erp->query("SELECT CODIGO FROM MT_MATERIAL WHERE CCKATIVO='S' AND CKCOMERCIALIZA='S'") as $r) {
    $escopo[strtoupper(trim($r['CODIGO']))] = true;
}

$inativos = $pdo->query("
    SELECT cpe.sku, cpe.entity_id,
      (SELECT v.value FROM catalog_product_entity_varchar v WHERE v.entity_id=cpe.entity_id AND v.store_id=0 AND v.attribute_id=$iaN) nome,
      (SELECT d.value FROM catalog_product_entity_decimal d WHERE d.entity_id=cpe.entity_id AND d.store_id=0 AND d.attribute_id=$iaP) preco,
      (SELECT v.value FROM catalog_product_entity_varchar v WHERE v.entity_id=cpe.entity_id AND v.store_id=0 AND v.attribute_id=$iaI) img
    FROM catalog_product_entity cpe
    JOIN catalog_product_entity_int s ON s.entity_id=cpe.entity_id AND s.store_id=0 AND s.attribute_id=$iaS
   WHERE s.value=2
")->fetchAll(PDO::FETCH_ASSOC);

$alvos = [];
foreach ($inativos as $r) {
    if (!isset($escopo[strtoupper(trim($r['sku']))])) { continue; }
    $alvos[] = [
        'sku'   => $r['sku'],
        'id'    => (int)$r['entity_id'],
        'nome'  => (string)$r['nome'],
        'preco' => $r['preco'] !== null ? (float)$r['preco'] : null,
        'foto'  => ($r['img'] && $r['img'] !== 'no_selection'),
    ];
}
printf("Alvos: %d produtos inativos que o Sectra manda vender\n\n", count($alvos));

// ── JEV: julga em um unico request (as perguntas rodam em paralelo) ──────────
$estado = [];
$perguntas = [];
foreach ($alvos as $i => $a) {
    $estado["p_$i"] = [
        'sku'   => $a['sku'],
        'nome'  => $a['nome'],
        'preco' => $a['preco'],
        'tem_foto' => $a['foto'],
    ];

    $perguntas["vender_$i"] = [
        'type' => 'choice',
        'instructions' => 'Contexto: e uma loja brasileira de pecas e acessorios de MOTO, '
            . 'que vende para lojistas e oficinas (B2B). O produto abaixo esta inativo na loja, '
            . 'mas o ERP da empresa o marca como ATIVO e COMERCIALIZADO, ou seja, o ERP manda vende-lo. '
            . 'Decida qual a acao correta para o produto "' . $a['nome'] . '" (SKU ' . $a['sku']
            . ', preco R$ ' . ($a['preco'] !== null ? number_format($a['preco'], 2, ',', '.') : 'sem preco')
            . ', ' . ($a['foto'] ? 'com foto' : 'sem foto') . ').',
        'criteria' => [
            'reativar'              => 'Item legitimo de moto, com preco plausivel — deve voltar a venda agora',
            'reativar_apos_preco'   => 'Item legitimo de moto, mas o preco esta claramente errado/placeholder (ex.: R$ 0,01) — corrigir o preco no ERP antes de vender',
            'reativar_apos_foto'    => 'Item legitimo de moto e com preco ok, mas sem foto — so vender depois de ter imagem',
            'nao_vender_nao_e_moto' => 'O item nao e peca nem acessorio de moto (ex.: equipamento industrial, ferramenta de outra area)',
            'nao_vender_revisar'    => 'Nao da para decidir com a informacao disponivel; um humano precisa olhar no ERP',
        ],
    ];
}

$corpo = json_encode(['state' => $estado, 'model' => 'jev-latest', 'questions' => $perguntas], JSON_UNESCAPED_UNICODE);
$ch = curl_init('https://api.typesafe.ai/v1/systemone');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $corpo,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200) { fwrite(STDERR, "HTTP $http: " . substr((string)$resp, 0, 300) . "\n"); exit(1); }
$ans = json_decode((string)$resp, true)['answers'] ?? [];

// ── CODIGO: agrupa por acao ──────────────────────────────────────────────────
$porAcao = [];
foreach ($alvos as $i => $a) {
    $v = $ans["vender_$i"] ?? [];
    $porAcao[$v['choice'] ?? '?'][] = $a + ['conf' => $v['confidence'] ?? null];
}

echo "================ VEREDITO ================\n";
foreach ($porAcao as $acao => $itens) {
    printf("  %-24s %2d\n", $acao, count($itens));
}

$ordem = ['reativar', 'reativar_apos_preco', 'reativar_apos_foto', 'nao_vender_nao_e_moto', 'nao_vender_revisar', '?'];
foreach ($ordem as $acao) {
    if (empty($porAcao[$acao])) { continue; }
    printf("\n--- %s (%d) ---\n", strtoupper($acao), count($porAcao[$acao]));
    foreach ($porAcao[$acao] as $a) {
        printf("  %-14s R$ %-9s %-14s conf=%.2f  %s\n",
            $a['sku'],
            $a['preco'] !== null ? number_format($a['preco'], 2, ',', '.') : '(sem)',
            $a['foto'] ? 'foto' : '*** SEM FOTO ***',
            $a['conf'] ?? 0,
            mb_substr($a['nome'], 0, 42));
    }
}

// ── CODIGO: aplica somente o que e seguro (reativar com preco E foto) ────────
$seguros = $porAcao['reativar'] ?? [];
if ($aplicar && $seguros) {
    echo "\n================ APLICANDO ================\n";
    $ok = 0;
    foreach ($seguros as $a) {
        try {
            $prod = $om->get(Magento\Catalog\Api\ProductRepositoryInterface::class)->getById($a['id'], true, 0, true);
            $prod->setStoreId(0);
            $prod->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
            $om->get(Magento\Catalog\Api\ProductRepositoryInterface::class)->save($prod);
            $ok++;
            printf("  reativado: %-14s %s\n", $a['sku'], mb_substr($a['nome'], 0, 38));
        } catch (Throwable $ex) {
            printf("  *** falhou %s: %s\n", $a['sku'], substr($ex->getMessage(), 0, 90));
        }
    }
    printf("\n  reativados: %d de %d\n", $ok, count($seguros));
} elseif ($seguros) {
    printf("\n(seriam reativados %d — rode com --aplicar para valer)\n", count($seguros));
}

if ($csv) {
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['sku', 'nome', 'preco', 'foto', 'acao', 'confianca'], ';');
    foreach ($porAcao as $acao => $itens) {
        foreach ($itens as $a) {
            fputcsv($fh, [$a['sku'], $a['nome'], $a['preco'], $a['foto'] ? 'sim' : 'nao', $acao, $a['conf']], ';');
        }
    }
    fclose($fh);
    printf("\nCSV: %s\n", $csv);
}
