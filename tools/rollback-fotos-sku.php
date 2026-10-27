<?php

/**
 * AWA Motos — rollback-fotos-sku.php  (task-39, CAT-FOTOS-1)
 *
 * Reverte uma importação feita por `tools/importar-fotos-sku.php`.
 * Lê o `backup.json` gerado no apply e, para cada produto:
 *   1. apaga as linhas de galeria CRIADAS (value_to_entity -> value -> media_gallery, nessa ordem);
 *   2. apaga os ARQUIVOS copiados para pub/media/catalog/product/...;
 *   3. restaura `image`/`small_image`/`thumbnail` ao valor anterior
 *      (UPDATE quando a linha existia; DELETE quando não existia — para não deixar valor "fantasma").
 *
 * USO
 *   php tools/rollback-fotos-sku.php --backup=_backups/import-fotos-20261005_1900            # --dry-run (padrão)
 *   php tools/rollback-fotos-sku.php --backup=_backups/import-fotos-20261005_1900 --apply
 *   php tools/rollback-fotos-sku.php --backup=... --apply --apenas=2053,2055                 # só alguns produtos
 *
 * Nunca usa cache:flush.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$opts = getopt('', ['backup:', 'apply', 'apenas::']);
$backupDir = (string) ($opts['backup'] ?? '');
$aplicar = isset($opts['apply']);
$apenas = array_filter(array_map('intval', explode(',', (string) ($opts['apenas'] ?? ''))));

$RAIZ = dirname(__DIR__);
if (!is_file($RAIZ . '/app/bootstrap.php')) {
    fwrite(STDERR, "ERRO: rode de dentro do docroot do Magento.\n");
    exit(1);
}
if ($backupDir === '' || !is_file(rtrim($backupDir, '/') . '/backup.json')) {
    fwrite(STDERR, "ERRO: informe --backup=<dir> contendo backup.json\n");
    exit(1);
}

require $RAIZ . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create($RAIZ, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$mediaAbs = rtrim($om->get(\Magento\Framework\Filesystem::class)
    ->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath(), '/');

$backup = json_decode((string) file_get_contents(rtrim($backupDir, '/') . '/backup.json'), true);
if (!is_array($backup) || !isset($backup['produtos'])) {
    fwrite(STDERR, "ERRO: backup.json inválido.\n");
    exit(1);
}

printf("=== rollback-fotos-sku.php ===\n");
printf("modo    : %s\n", $aplicar ? 'APPLY' : 'DRY-RUN (não altera nada)');
printf("backup  : %s\n", $backupDir);
printf("gerado  : %s\n", $backup['gerado_em'] ?? '?');
printf("produtos: %d\n\n", count($backup['produtos']));

$resumo = ['galeria_apagada' => 0, 'arquivos_apagados' => 0, 'valores_restaurados' => 0, 'linhas_removidas' => 0, 'erros' => 0];
foreach ($backup['produtos'] as $eid => $p) {
    $eid = (int) $eid;
    if ($apenas && !in_array($eid, $apenas, true)) {
        continue;
    }
    printf("SKU %-18s entity %-5d\n", $p['sku'] ?? '?', $eid);
    // 1) galeria criada
    $novos = $backup['novos_por_produto'][$eid] ?? [];
    foreach ($novos as $n) {
        printf("   - galeria: value_id=%d (%s)\n", $n['value_id'], $n['value']);
        if ($aplicar) {
            $conn->delete('catalog_product_entity_media_gallery_value_to_entity', ['value_id = ?' => $n['value_id']]);
            $conn->delete('catalog_product_entity_media_gallery_value', ['value_id = ?' => $n['value_id']]);
            $conn->delete('catalog_product_entity_media_gallery', ['value_id = ?' => $n['value_id']]);
            $resumo['galeria_apagada']++;
        }
        // 2) arquivo — ATENÇÃO: o valor da galeria é relativo a pub/media/catalog/product/
        //    (ex.: `/G/P/GP3000.png`). O caminho absoluto é mediaAbs + catalog/product + valor.
        $arquivo = $mediaAbs . '/catalog/product/' . ltrim((string) $n['value'], '/');
        if (is_file($arquivo)) {
            printf("   - arquivo: %s\n", $arquivo);
            if ($aplicar) {
                @unlink($arquivo);
                $resumo['arquivos_apagados']++;
            }
        } else {
            printf("   - arquivo: %s (já não existia)\n", $arquivo);
        }
    }
    // 3) atributos
    foreach (['image', 'small_image', 'thumbnail'] as $code) {
        $valorAntes = (string) ($p['valores_antes'][$code] ?? '');
        $existia = (bool) ($p['linhas_antes'][$code] ?? false);
        printf("   - %-12s antes=%s (%s)\n", $code, $valorAntes !== '' ? $valorAntes : '(vazio)',
            $existia ? 'UPDATE' : 'DELETE (linha não existia)');
        if (!$aplicar) {
            continue;
        }
        $params = [
            'entity_id = ?' => $eid,
            'attribute_id = (SELECT attribute_id FROM eav_attribute WHERE entity_type_id=4 AND attribute_code=?)' => $code,
            'store_id = ?' => 0,
        ];
        if ($existia) {
            $conn->update('catalog_product_entity_varchar', ['value' => $valorAntes], $params);
            $resumo['valores_restaurados']++;
        } else {
            $conn->delete('catalog_product_entity_varchar', $params);
            $resumo['linhas_removidas']++;
        }
    }
    echo "\n";
}

printf("--- RESUMO ---\n");
foreach ($resumo as $k => $v) {
    printf("  %-22s %d\n", $k, $v);
}
if ($aplicar) {
    echo "\nAgora: php bin/magento cache:clean block_html full_page\n";
} else {
    echo "\nDRY-RUN: nada foi alterado. Para reverter: --apply\n";
}

