<?php
declare(strict_types=1);

/**
 * Exporta os dados de compatibilidade (fitment) para o pipeline do Jev.
 *
 * SOMENTE LEITURA. Nao escreve nada no Magento nem no ERP.
 * Saida: JSON em stdout.
 *
 * Uso: php tools/jev-fitment/exportar-dados.php [--limite=N]
 */

use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../app/bootstrap.php';

$boot = Bootstrap::create(BP, $_SERVER);
$om = $boot->getObjectManager();
$res = $om->get(\Magento\Framework\App\ResourceConnection::class);
$md = $res->getConnection();

$limite = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--limite=(\d+)$/', $arg, $m)) {
        $limite = (int) $m[1];
    }
}

$attr = static function (string $code) use ($md): int {
    return (int) $md->fetchOne(
        "SELECT attribute_id FROM eav_attribute WHERE attribute_code = ? AND entity_type_id = 4",
        [$code]
    );
};

$idModelo = $attr('modelo_moto');
$idAno = $attr('ano_moto');
$idMarca = $attr('marca_moto');

$sql = "SELECT e.sku, e.entity_id, st.value AS status,
               mo.value AS modelo_moto, an.value AS ano_moto, ma.value AS marca_moto
        FROM catalog_product_entity e
        LEFT JOIN catalog_product_entity_int st
               ON st.entity_id = e.entity_id AND st.store_id = 0
              AND st.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='status' AND entity_type_id=4)
        LEFT JOIN catalog_product_entity_varchar mo
               ON mo.entity_id = e.entity_id AND mo.store_id = 0 AND mo.attribute_id = :am
        LEFT JOIN catalog_product_entity_varchar an
               ON an.entity_id = e.entity_id AND an.store_id = 0 AND an.attribute_id = :aa
        LEFT JOIN catalog_product_entity_varchar ma
               ON ma.entity_id = e.entity_id AND ma.store_id = 0 AND ma.attribute_id = :ac
        ORDER BY e.entity_id";
if ($limite > 0) {
    $sql .= " LIMIT " . $limite;
}
$rows = $md->fetchAll($sql, [':am' => $idModelo, ':aa' => $idAno, ':ac' => $idMarca]);

$erp = $om->get(\GrupoAwamotos\ERPIntegration\Api\ConnectionInterface::class);
$skus = array_values(array_filter(array_map(static fn($r) => trim((string) $r['sku']), $rows)));
$erpData = [];
foreach (array_chunk($skus, 400) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $q = "SELECT m.CODIGO, m.DESCRICAO, m.COMPLEMENTO, m.CCKATIVO, m.CKCOMERCIALIZA
          FROM MT_MATERIAL m WHERE m.CODIGO IN ($in)";
    foreach ($erp->query($q, $chunk) as $r) {
        $erpData[trim((string) $r['CODIGO'])] = $r;
    }
}

$out = [];
foreach ($rows as $r) {
    $sku = trim((string) $r['sku']);
    $e = $erpData[$sku] ?? null;
    $desc = $e ? trim((string) $e['DESCRICAO']) : '';
    $compl = $e ? trim((string) $e['COMPLEMENTO']) : '';
    $texto = trim($desc . ' ' . $compl);
    $out[] = [
        'sku' => $sku,
        'status' => (int) ($r['status'] ?? 0),
        'magento' => [
            'modelo_moto' => (string) ($r['modelo_moto'] ?? ''),
            'ano_moto' => (string) ($r['ano_moto'] ?? ''),
            'marca_moto' => (string) ($r['marca_moto'] ?? ''),
        ],
        'erp' => [
            'texto' => $texto,
            'descricao' => $desc,
            'complemento' => $compl,
            'cckativo' => $e ? trim((string) $e['CCKATIVO']) : null,
            'ckcomercializa' => $e ? trim((string) $e['CKCOMERCIALIZA']) : null,
            'existe' => $e !== null,
        ],
    ];
}

echo json_encode([
    'gerado_em' => gmdate('c'),
    'total' => count($out),
    'produtos' => $out,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";

