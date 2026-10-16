<?php
/**
 * AWA Motos — reconciliacao SKU-a-SKU: Magento x ERP Sectra.
 *
 * Responde, produto por produto, o que o CLIENTE ve na loja x o que o VENDEDOR
 * ve no ERP. SOMENTE LEITURA nos dois lados.
 *
 * Uso:  php tools/reconciliar-skus.php [--amostra=N] [--csv=caminho.csv]
 *
 * Saidas:
 *   - resumo por categoria de divergencia
 *   - amostra de cada categoria
 *   - CSV completo (opcional)
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/autoload.php';
$om = Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();

$opcoes = getopt('', ['amostra::', 'csv::']);
$tamAmostra = (int)($opcoes['amostra'] ?? 15);
$csv = $opcoes['csv'] ?? null;

$erp = $om->get(GrupoAwamotos\ERPIntegration\Api\ConnectionInterface::class);
$res = $om->get(Magento\Framework\App\ResourceConnection::class)->getConnection();
$FILIAL = 2; // BOOMERANG

// ── 1. LADO MAGENTO ──────────────────────────────────────────────────────────
$iaStatus = $res->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4");
$iaPrice  = $res->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4");
$iaName   = $res->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4");

$magento = [];
foreach ($res->fetchAll("
    SELECT cpe.entity_id, cpe.sku,
           s.value AS status,
           (SELECT v.value FROM catalog_product_entity_varchar v
             WHERE v.entity_id=cpe.entity_id AND v.store_id=0 AND v.attribute_id=$iaName) AS nome,
           (SELECT d.value FROM catalog_product_entity_decimal d
             WHERE d.entity_id=cpe.entity_id AND d.store_id=0 AND d.attribute_id=$iaPrice) AS preco
      FROM catalog_product_entity cpe
      JOIN catalog_product_entity_int s ON s.entity_id=cpe.entity_id AND s.store_id=0 AND s.attribute_id=$iaStatus
     WHERE s.value=1") as $r) {
    $magento[strtoupper(trim($r['sku']))] = $r;
}
printf("MAGENTO: %d SKUs ativos\n", count($magento));

// ── 2. LADO ERP ──────────────────────────────────────────────────────────────
$erpMat = [];
foreach ($erp->query("SELECT CODIGO, DESCRICAO, CCKATIVO, CKCOMERCIALIZA FROM MT_MATERIAL") as $r) {
    $erpMat[strtoupper(trim($r['CODIGO']))] = [
        'descricao' => $r['DESCRICAO'],
        'ativo' => trim((string)$r['CCKATIVO']) === 'S',
        'comercializa' => trim((string)$r['CKCOMERCIALIZA']) === 'S',
    ];
}
printf("ERP: %d materiais\n", count($erpMat));

// *** CRITICO: filtrar pelo FATORPRECO certo ***
// MT_MATERIALLISTA tem ~48 linhas por material (uma por lista de preco). Sem o
// filtro, o loop sobrescreve e pega o preco de OUTRA lista -> falso alarme de
// divergencia. O fator desta loja e o 24 "NACIONAL", confirmado em:
//   config  grupoawamotos_erp/sync_prices/default_price_list = 24
//   codigo  CustomerPriceProvider.php:19 (fallback NACIONAL #24)
//   teste   8 de 10 SKUs aleatorios batem EXATAMENTE com ele
$FATOR = 24;
$erpPreco = [];
foreach ($erp->query("SELECT MATERIAL, VLRVDSUG, VLRVDMIN FROM MT_MATERIALLISTA WHERE FILIAL=$FILIAL AND FATORPRECO=$FATOR") as $r) {
    $k = strtoupper(trim($r['MATERIAL']));
    $sug = (float)$r['VLRVDSUG'];
    if ($sug <= 0) $sug = (float)$r['VLRVDMIN'];
    if ($sug > 0) $erpPreco[$k] = $sug;
}
printf("ERP: %d materiais com preco na filial %d na lista %d\n", count($erpPreco), $FILIAL, $FATOR);

$erpEstoque = [];
foreach ($erp->query("SELECT MATERIAL, SUM(QTDEREAL) AS q FROM MT_ESTOQUE WHERE FILIAL=$FILIAL GROUP BY MATERIAL") as $r) {
    $erpEstoque[strtoupper(trim($r['MATERIAL']))] = (float)$r['q'];
}
printf("ERP: %d materiais com estoque na filial %d\n\n", count($erpEstoque), $FILIAL);

// ── 3. RECONCILIACAO ─────────────────────────────────────────────────────────
$cats = [
    'OK'                       => [],
    'NAO_EXISTE_NO_ERP'        => [],
    'INATIVO_NO_ERP'           => [],
    'NAO_COMERCIALIZA_NO_ERP'  => [],
    'SEM_PRECO_NO_ERP'         => [],
    'SEM_ESTOQUE_NO_ERP'       => [],
    'PRECO_DIVERGE'            => [],
];

foreach ($magento as $sku => $m) {
    $e = $erpMat[$sku] ?? null;
    if (!$e) { $cats['NAO_EXISTE_NO_ERP'][] = ['sku' => $sku, 'nome' => $m['nome']]; continue; }
    if (!$e['ativo']) { $cats['INATIVO_NO_ERP'][] = ['sku' => $sku, 'nome' => $m['nome'], 'erp' => $e['descricao']]; continue; }
    if (!$e['comercializa']) { $cats['NAO_COMERCIALIZA_NO_ERP'][] = ['sku' => $sku, 'nome' => $m['nome']]; continue; }

    $pErp = $erpPreco[$sku] ?? null;
    $pMag = $m['preco'] !== null ? (float)$m['preco'] : null;

    if ($pErp === null) { $cats['SEM_PRECO_NO_ERP'][] = ['sku' => $sku, 'nome' => $m['nome'], 'mag' => $pMag]; continue; }
    if (!isset($erpEstoque[$sku])) { $cats['SEM_ESTOQUE_NO_ERP'][] = ['sku' => $sku, 'nome' => $m['nome'], 'mag' => $pMag, 'erp' => $pErp]; continue; }

    if ($pMag === null) { $cats['PRECO_DIVERGE'][] = ['sku' => $sku, 'mag' => null, 'erp' => $pErp, 'diff' => null, 'nome' => $m['nome']]; continue; }
    $diffPct = $pErp > 0 ? (($pMag - $pErp) / $pErp) * 100 : 0;
    if (abs($diffPct) > 1.0) {
        $cats['PRECO_DIVERGE'][] = ['sku' => $sku, 'mag' => $pMag, 'erp' => $pErp, 'diff' => $diffPct, 'nome' => $m['nome']];
    } else {
        $cats['OK'][] = ['sku' => $sku];
    }
}

// ── 4. RELATORIO ─────────────────────────────────────────────────────────────
echo "======== RESULTADO DA RECONCILIACAO ========\n";
$total = count($magento);
foreach ($cats as $nome => $itens) {
    $pct = $total > 0 ? count($itens) / $total * 100 : 0;
    printf("  %-26s %5d  (%5.1f%%)\n", $nome, count($itens), $pct);
}

foreach ($cats as $nome => $itens) {
    if ($nome === 'OK' || !$itens) continue;
    printf("\n--- %s (%d) — amostra de %d ---\n", $nome, count($itens), min($tamAmostra, count($itens)));
    foreach (array_slice($itens, 0, $tamAmostra) as $i) {
        $extra = '';
        if (isset($i['mag'])) $extra .= sprintf(" mag=%s", $i['mag'] === null ? 'NULL' : number_format($i['mag'], 2, ',', '.'));
        if (isset($i['erp'])) $extra .= sprintf(" erp=%s", number_format($i['erp'], 2, ',', '.'));
        if (isset($i['diff']) && $i['diff'] !== null) $extra .= sprintf(" diff=%+.1f%%", $i['diff']);
        printf("    %-16s %s%s\n", $i['sku'], substr((string)($i['nome'] ?? ''), 0, 42), $extra);
    }
}

if ($csv) {
    $fh = fopen($csv, 'w');
    fputcsv($fh, ['categoria', 'sku', 'nome', 'magento_preco', 'erp_preco', 'diff_pct'], ';');
    foreach ($cats as $nome => $itens) {
        foreach ($itens as $i) {
            fputcsv($fh, [$nome, $i['sku'], $i['nome'] ?? '', $i['mag'] ?? '', $i['erp'] ?? '',
                isset($i['diff']) && $i['diff'] !== null ? round($i['diff'], 2) : ''], ';');
        }
    }
    fclose($fh);
    printf("\nCSV: %s\n", $csv);
}
