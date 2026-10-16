<?php

/**
 * AWA Motos — importar-fotos-sku.php  (task-39, CAT-FOTOS-1)
 *
 * Importa fotos de produto para o Magento a partir de uma PASTA com arquivos nomeados pelo SKU.
 * Ferramenta IRMÃ: `tools/rollback-fotos-sku.php` (gera e executa a reversão do backup).
 *
 * COMO O NOME DO ARQUIVO VIRA SKU
 *   O SKU pode ter caracteres que não existem em nome de arquivo (`022.012-6009`, `502/SOLMAK`,
 *   `00576 MED`, `T290 AM`). Regra: trocar TODO caractere que não seja letra/número por `_` e
 *   comparar sem diferenciar maiúscula/minúscula.
 *     `502/SOLMAK`   -> `502_SOLMAK.jpg`
 *     `022.012-6009` -> `022_012_6009.jpg`
 *     `00576 MED`    -> `00576_MED.jpg`
 *   Várias fotos do mesmo SKU: sufixo numérico — `SKU_1.jpg` (principal), `SKU_2.jpg`, `SKU_3.jpg`.
 *   Sem sufixo (`SKU.jpg`) também vale e equivale à principal.
 *
 * API OFICIAL (nenhum SQL manual para gravar mídia)
 *   Product::addImageToMediaGallery() -> Catalog\Model\Product\Gallery\Processor::addImage()
 *   - o arquivo precisa estar DENTRO de pub/media (a API usa mediaDirectory->getRelativePath());
 *     por isso este script copia a foto para pub/media/<staging>/ antes de chamar a API;
 *   - aceita APENAS jpg, jpeg, gif e png (WEBP é rejeitado pela própria API);
 *   - grava em pub/media/catalog/product/<1a letra>/<2a letra>/<arquivo> (dispersion path do Magento),
 *     cria as linhas de galeria e marca os papéis quando solicitado;
 *   - se já existir arquivo com o mesmo nome, o Magento renomeia (`arquivo_1.jpg`) em vez de sobrescrever.
 *
 * SEGURANÇA
 *   --dry-run é o PADRÃO; só grava com --apply.
 *   Produto que já tem imagem é PULADO, a não ser com --force.
 *   Antes de gravar: backup em _backups/import-fotos-<timestamp>/ com backup.json (estado anterior dos
 *   atributos e das linhas de galeria) + ROLLBACK.md. A reversão é `tools/rollback-fotos-sku.php --backup=<dir>`.
 *   Nunca usa cache:flush.
 *
 * USO
 *   php tools/importar-fotos-sku.php --pasta=/caminho/das/fotos                 # dry-run
 *   php tools/importar-fotos-sku.php --pasta=/caminho/das/fotos --limit=2       # dry-run com 2 arquivos
 *   php tools/importar-fotos-sku.php --pasta=/caminho/das/fotos --apply         # grava
 *   php tools/importar-fotos-sku.php --pasta=/caminho/das/fotos --apply --force
 *   php tools/importar-fotos-sku.php --pasta=/caminho/das/fotos --listar-skus   # confere o mapa nome->SKU
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$opts = getopt('', ['pasta:', 'apply', 'force', 'limit::', 'listar-skus', 'incluir-desabilitados',
                    'backup-dir::', 'relatorio::', 'staging::', 'extensoes::']);
$pasta = (string) ($opts['pasta'] ?? '');
$aplicar = isset($opts['apply']);
$force = isset($opts['force']);
$limite = isset($opts['limit']) && $opts['limit'] !== '' ? (int) $opts['limit'] : 0;
$listar = isset($opts['listar-skus']);
$incluirDesabilitados = isset($opts['incluir-desabilitados']);
$extensoes = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['extensoes'] ?? 'jpg,jpeg,png,gif')))));

$RAIZ = dirname(__DIR__);
if (!is_file($RAIZ . '/app/bootstrap.php')) {
    fwrite(STDERR, "ERRO: rode de dentro do docroot (app/bootstrap.php não encontrado em {$RAIZ}).\n");
    exit(1);
}
require $RAIZ . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create($RAIZ, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$mediaAbs = rtrim($om->get(\Magento\Framework\Filesystem::class)
    ->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath(), '/');

$stagingAbs = $mediaAbs . '/' . trim((string) ($opts['staging'] ?? 'awa-import'), '/');
$ts = date('Ymd_His');
$backupDir = (string) ($opts['backup-dir'] ?? $RAIZ . '/_backups/import-fotos-' . $ts);
$relatorio = (string) ($opts['relatorio'] ?? $RAIZ . '/var/awa-import-fotos-' . $ts . '.csv');

// ---------------------------------------------------------------- helpers
function normalizaSku(string $s): string
{
    return (string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($s)));
}

/** `SKU_2.jpg` -> [SKU normalizado, 2]; `SKU.jpg` -> [SKU, 1]. */
function skuDoArquivo(string $basename): array
{
    $semExt = (string) pathinfo($basename, PATHINFO_FILENAME);
    $ordem = 1;
    if (preg_match('/^(.*?)[_-](\d{1,2})$/', $semExt, $m)) {
        $semExt = $m[1];
        $ordem = (int) $m[2];
    }
    return [normalizaSku($semExt), max(1, $ordem)];
}

printf("=== importar-fotos-sku.php ===\n");
printf("modo      : %s\n", $aplicar ? 'APPLY (grava)' : 'DRY-RUN (não grava nada)');
printf("pasta     : %s\n", $pasta !== '' ? $pasta : '(não informada)');
printf("force     : %s | limite: %s | extensões aceitas: %s\n", $force ? 'SIM' : 'não', $limite ?: 'sem limite', implode(',', $extensoes));
printf("staging   : %s\n", $stagingAbs);
printf("backup    : %s\n", $backupDir);
printf("relatório : %s\n\n", $relatorio);

if ($pasta === '' || !is_dir($pasta)) {
    fwrite(STDERR, "ERRO: --pasta=<diretório> é obrigatório e precisa existir.\n");
    exit(1);
}

// ---------------------------------------------------------------- índice de SKUs
$skus = [];
foreach ($conn->fetchAll('SELECT entity_id, sku FROM catalog_product_entity') as $r) {
    $skus[normalizaSku((string) $r['sku'])] = ['entity_id' => (int) $r['entity_id'], 'sku' => (string) $r['sku']];
}
printf("SKUs no catálogo: %d\n", count($skus));

$arquivos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) {
        $arquivos[] = $f;
    }
}
usort($arquivos, static fn($a, $b) => strcmp($a->getFilename(), $b->getFilename()));

if ($listar) {
    $achados = 0;
    foreach ($arquivos as $f) {
        [$norm, $ordem] = skuDoArquivo($f->getFilename());
        if (isset($skus[$norm])) {
            printf("  %-44s -> SKU %-18s (entity %d) foto %d\n", $f->getFilename(), $skus[$norm]['sku'], $skus[$norm]['entity_id'], $ordem);
            $achados++;
        } else {
            printf("  %-44s -> SEM SKU correspondente (normalizado: %s)\n", $f->getFilename(), $norm);
        }
    }
    printf("\nArquivos que casam: %d de %d\n", $achados, count($arquivos));
    exit(0);
}

// ---------------------------------------------------------------- plano
$plano = [];
$contagem = [];
foreach ($arquivos as $f) {
    $arq = $f->getPathname();
    $ext = strtolower((string) $f->getExtension());
    [$norm, $ordem] = skuDoArquivo($f->getFilename());
    $linha = ['arquivo' => $f->getFilename(), 'caminho' => $arq, 'sku' => '', 'entity_id' => 0,
              'ordem' => $ordem, 'acao' => '', 'destino' => '', 'detalhe' => ''];

    if (!in_array($ext, $extensoes, true)) {
        $linha['acao'] = 'PULAR_EXTENSAO';
        $linha['detalhe'] = "extensão .{$ext} (a API oficial aceita só jpg/jpeg/png/gif)";
    } elseif (!isset($skus[$norm])) {
        $linha['acao'] = 'PULAR_SKU_INEXISTENTE';
        $linha['detalhe'] = "nenhum SKU normaliza para '{$norm}'";
    } elseif (@getimagesize($arq) === false) {
        $linha['acao'] = 'PULAR_ARQUIVO_INVALIDO';
        $linha['detalhe'] = 'getimagesize() falhou: não é imagem legível';
    } else {
        $linha['sku'] = $skus[$norm]['sku'];
        $linha['entity_id'] = $skus[$norm]['entity_id'];
        $linha['destino'] = 'pub/media/catalog/product/' . substr($f->getFilename(), 0, 1) . '/' . substr($f->getFilename(), 1, 1) . '/' . $f->getFilename();
    }
    $plano[] = $linha;
}
usort($plano, static fn(array $a, array $b) => [$a['sku'], $a['ordem']] <=> [$b['sku'], $b['ordem']]);

// ---------------------------------------------------------------- estado atual
$imgAid = (int) $conn->fetchOne("SELECT attribute_id FROM eav_attribute WHERE entity_type_id=4 AND attribute_code='image'");
$statusAid = (int) $conn->fetchOne("SELECT attribute_id FROM eav_attribute WHERE entity_type_id=4 AND attribute_code='status'");
$estado = [];
foreach ($plano as $l) {
    $eid = $l['entity_id'];
    if ($eid === 0 || isset($estado[$eid])) {
        continue;
    }
    $valores = [];
    $linhas = [];
    foreach (['image', 'small_image', 'thumbnail'] as $code) {
        $row = $conn->fetchRow(
            'SELECT v.value FROM catalog_product_entity_varchar v
             JOIN eav_attribute a ON a.attribute_id = v.attribute_id
             WHERE v.entity_id = ? AND a.attribute_code = ? AND v.store_id = 0',
            [$eid, $code]
        );
        $valores[$code] = $row ? (string) $row['value'] : '';
        $linhas[$code] = (bool) $row;
    }
    $estado[$eid] = [
        'valores' => $valores,
        'linhas' => $linhas,
        'galeria' => $conn->fetchAll(
            'SELECT g.value_id, g.value FROM catalog_product_entity_media_gallery g
             JOIN catalog_product_entity_media_gallery_value_to_entity gve ON gve.value_id = g.value_id
             WHERE gve.entity_id = ? ORDER BY g.value_id',
            [$eid]
        ),
        'ja_tem_imagem' => trim($valores['image']) !== '' && trim($valores['image']) !== 'no_selection',
        'status' => (int) $conn->fetchOne(
            'SELECT value FROM catalog_product_entity_int WHERE entity_id = ? AND attribute_id = ? AND store_id = 0',
            [$eid, $statusAid]
        ),
    ];
}

// ---------------------------------------------------------------- classificação
$principalDoEid = [];
foreach ($plano as $i => $l) {
    if ($l['acao'] !== '') {
        continue;
    }
    $eid = $l['entity_id'];
    $st = $estado[$eid];
    if (!$incluirDesabilitados && $st['status'] !== 1) {
        $plano[$i]['acao'] = 'PULAR_DESABILITADO';
        $plano[$i]['detalhe'] = "status={$st['status']} (use --incluir-desabilitados)";
    } elseif ($st['ja_tem_imagem'] && !$force) {
        $plano[$i]['acao'] = 'PULAR_JA_TEM_IMAGEM';
        $plano[$i]['detalhe'] = "image atual = '{$st['valores']['image']}' (use --force para adicionar mesmo assim)";
    } elseif (isset($principalDoEid[$eid])) {
        $plano[$i]['acao'] = 'IMPORTAR_SECUNDARIA';
        $plano[$i]['detalhe'] = 'entra na galeria sem virar imagem principal';
    } else {
        $plano[$i]['acao'] = 'IMPORTAR_PRINCIPAL';
        $plano[$i]['detalhe'] = 'marca image/small_image/thumbnail';
        $principalDoEid[$eid] = true;
    }
}
foreach ($plano as $l) {
    $contagem[$l['acao']] = ($contagem[$l['acao']] ?? 0) + 1;
}

echo "--- PLANO (por SKU) ---\n";
foreach ($plano as $l) {
    printf("  %-30s %-16s %-20s %s\n", $l['arquivo'], $l['sku'] ?: '—', $l['acao'],
        $l['acao'] === 'IMPORTAR_PRINCIPAL' || $l['acao'] === 'IMPORTAR_SECUNDARIA' ? $l['destino'] : $l['detalhe']);
}
echo "\n--- RESUMO ---\n";
ksort($contagem);
foreach ($contagem as $acao => $n) {
    printf("  %-24s %d\n", $acao, $n);
}
printf("  %-24s %d\n", 'TOTAL de arquivos', count($plano));

$importaveis = array_values(array_filter($plano, static fn($l) => str_starts_with($l['acao'], 'IMPORTAR')));
if ($limite > 0) {
    $importaveis = array_slice($importaveis, 0, $limite);
    printf("\n--limit=%d -> processaria %d arquivo(s)\n", $limite, count($importaveis));
}

@mkdir(dirname($relatorio), 0775, true);
$fh = fopen($relatorio, 'w');
fputcsv($fh, ['arquivo', 'sku', 'entity_id', 'acao', 'destino', 'detalhe'], ';', '"', '\\');
foreach ($plano as $l) {
    fputcsv($fh, [$l['arquivo'], $l['sku'], $l['entity_id'], $l['acao'], $l['destino'], $l['detalhe']], ';', '"', '\\');
}
fclose($fh);

if (!$aplicar) {
    printf("\nDRY-RUN: nada foi gravado.\nRelatório: %s\n", $relatorio);
    printf("Para gravar: --apply%s\n", $force ? ' --force' : '');
    exit(0);
}
if (!$importaveis) {
    echo "\nNada a importar: todos os arquivos foram pulados.\n";
    exit(0);
}

// ---------------------------------------------------------------- APPLY (com backup antes)
@mkdir($backupDir, 0775, true);
@mkdir($stagingAbs, 0775, true);
$backup = ['gerado_em' => date('c'), 'script' => 'importar-fotos-sku.php',
           'modo' => $force ? 'apply+force' : 'apply', 'pasta_origem' => $pasta, 'produtos' => []];
foreach ($importaveis as $l) {
    $eid = $l['entity_id'];
    if (!isset($backup['produtos'][$eid])) {
        $backup['produtos'][$eid] = [
            'sku' => $l['sku'], 'entity_id' => $eid,
            'valores_antes' => $estado[$eid]['valores'],
            'linhas_antes' => $estado[$eid]['linhas'],
            'galeria_antes' => $estado[$eid]['galeria'],
        ];
    }
}
$escreve = static function () use (&$backup, $backupDir): void {
    file_put_contents($backupDir . '/backup.json',
        json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};
$escreve();
printf("\nBACKUP gravado: %s (%d produto(s))\n", $backupDir . '/backup.json', count($backup['produtos']));

$resultado = [];
$novos = [];
foreach ($importaveis as $l) {
    $eid = $l['entity_id'];
    try {
        $stagingFile = $stagingAbs . '/' . $l['arquivo'];
        if (!@copy($l['caminho'], $stagingFile)) {
            throw new RuntimeException("falha ao copiar para o estágio {$stagingFile}");
        }
        $product = $repo->get($l['sku'], true, 0, true);
        $papel = $l['ordem'] === 1 ? ['image', 'small_image', 'thumbnail'] : null;
        $product->addImageToMediaGallery($stagingFile, $papel, false, false);
        $product->save();
        @unlink($stagingFile);

        $antes = array_map('intval', array_column($estado[$eid]['galeria'], 'value_id'));
        $depois = $conn->fetchAll(
            'SELECT g.value_id, g.value FROM catalog_product_entity_media_gallery g
             JOIN catalog_product_entity_media_gallery_value_to_entity gve ON gve.value_id = g.value_id
             WHERE gve.entity_id = ? ORDER BY g.value_id',
            [$eid]
        );
        foreach ($depois as $d) {
            if (!in_array((int) $d['value_id'], $antes, true)) {
                $novos[$eid][] = ['value_id' => (int) $d['value_id'], 'value' => (string) $d['value']];
                $resultado[] = ['arquivo' => $l['arquivo'], 'sku' => $l['sku'], 'entity_id' => $eid,
                                'acao' => 'OK', 'value_id' => (int) $d['value_id'], 'value' => (string) $d['value']];
            }
        }
        $img = (string) $conn->fetchOne(
            "SELECT value FROM catalog_product_entity_varchar v JOIN eav_attribute a ON a.attribute_id=v.attribute_id
             WHERE v.entity_id=? AND a.attribute_code='image' AND v.store_id=0", [$eid]);
        printf("  OK   %-30s SKU %-16s entity %-5d img=%s\n", $l['arquivo'], $l['sku'], $eid, $img);
    } catch (Throwable $e) {
        $resultado[] = ['arquivo' => $l['arquivo'], 'sku' => $l['sku'], 'entity_id' => $eid, 'acao' => 'ERRO',
                        'value_id' => 0, 'value' => $e->getMessage()];
        fwrite(STDERR, "  ERRO {$l['arquivo']}: {$e->getMessage()}\n");
    }
}

$backup['novos_por_produto'] = $novos;
$backup['resultado'] = $resultado;
$escreve();

$fh = fopen($relatorio, 'w');
fputcsv($fh, ['arquivo', 'sku', 'entity_id', 'acao', 'value_id', 'value'], ';', '"', '\\');
foreach ($resultado as $r) {
    fputcsv($fh, [$r['arquivo'], $r['sku'], $r['entity_id'], $r['acao'], $r['value_id'], $r['value']], ';', '"', '\\');
}
fclose($fh);

$ok = count(array_filter($resultado, static fn($r) => $r['acao'] === 'OK'));
printf("\nAPPLY concluído: %d imagem(ns) importada(s), %d erro(s).\n", $ok, count($resultado) - $ok);
printf("Backup   : %s\nRelatório: %s\n", $backupDir, $relatorio);
printf("\nREVERSÃO (se precisar):\n  php tools/rollback-fotos-sku.php --backup=%s --dry-run\n  php tools/rollback-fotos-sku.php --backup=%s --apply\n", $backupDir, $backupDir);
echo "\nLIMPE O CACHE (nunca cache:flush):\n  php bin/magento cache:clean block_html full_page\n";
echo "CONFIRA NO AR:\n  curl -s -o /dev/null -w '%{http_code}\\n' \"https://awamotos.com/media/catalog/product/<x>/<y>/<arquivo>\"\n";



