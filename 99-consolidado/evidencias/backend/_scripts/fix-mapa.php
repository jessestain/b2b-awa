<?php
/**
 * task-55 — CORRECAO DE RAIZ no produtor.
 *
 * Em `OrderImportGate::syncImportedOrderFlags()` o `imported` era gravado SEM o mapa.
 * Passa a gravar o mapa (entity_type='order') com o CODIGO lido do ERP pela CONEXAO DE LEITURA.
 *
 * Nenhum construtor muda => nao exige setup:di:compile.
 * Uso: php fix-mapa.php [--apply]
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$target = $root . '/app/code/GrupoAwamotos/B2B/Model/Sectra/OrderImportGate.php';
$apply = in_array('--apply', $argv, true);
$bakDir = $root . '/_backups/erp-map-reconcile-' . gmdate('Ymd\THis\Z');

$anchor = <<<'TXT'
        $updated = 0;
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);
            $this->syncLogger->log(
                ProspectEvent::ORDER_IMPORTED_SUCCESS,
                sprintf('Pedido #%s importado com sucesso no ERP Sectra.', $row['increment_id']),
                (int) ($row['customer_id'] ?? 0) ?: null,
                $orderId,
                null,
                null,
                'success'
            );
            $updated++;
        }

        return $reconciled + $updated;
    }
TXT;

$novo = <<<'TXT'
        $updated = 0;
        $importedOrderIds = [];
        foreach ($rows as $row) {
            $orderId = (int) $row['entity_id'];
            $this->setOrderImportStatus($orderId, SectraImportStatus::IMPORTED);
            $importedOrderIds[] = $orderId;
            $this->syncLogger->log(
                ProspectEvent::ORDER_IMPORTED_SUCCESS,
                sprintf('Pedido #%s importado com sucesso no ERP Sectra.', $row['increment_id']),
                (int) ($row['customer_id'] ?? 0) ?: null,
                $orderId,
                null,
                null,
                'success'
            );
            $updated++;
        }

        /* task-55 (11/10/2026) — CORRECAO DE RAIZ.
           Este laco marcava sectra_import_status='imported' e NAO escrevia o mapa
           grupoawamotos_erp_entity_map. Resultado medido: 29 pedidos 'imported' e apenas
           6 com linha no mapa (23 sem). Como o OrderSync::getPendingOrdersForSync() le
           SOMENTE o mapa, esses pedidos nunca entravam no sync de status - o log repetia
           "Syncing status for 0 orders" (82x) e eles ficavam presos em 'processing'.
           Agora o mapa e gravado JUNTO com o flag, no mesmo caminho (produtor). */
        if ($importedOrderIds !== []) {
            $this->syncOrderEntityMap($importedOrderIds);
        }

        return $reconciled + $updated;
    }

    /**
     * Grava o mapa de pedido (entity_type='order') dos pedidos marcados como importados.
     *
     * Le o CODIGO do ERP pela CONEXAO DE LEITURA (nenhuma escrita no ERP) e faz upsert
     * em grupoawamotos_erp_entity_map, com a mesma semantica de SyncLog::setEntityMap().
     * Nunca lanca: o flag 'imported' e a obrigacao principal deste caminho, e uma falha
     * do ERP nao pode derrubar o sync de status.
     *
     * @param int[] $orderIds
     * @return int quantos pedidos ficaram mapeados
     */
    private function syncOrderEntityMap(array $orderIds): int
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return 0;
        }

        try {
            $webIds = array_map(
                static fn (int $id): string => (string) ($id + self::OC_ORDER_ID_OFFSET),
                $orderIds
            );
            $placeholders = implode(',', array_fill(0, count($webIds), '?'));
            $erpRows = $this->erpConnection->query(
                "SELECT p.CODIGO, p.PEDIDOWEB FROM VE_PEDIDO p WHERE p.PEDIDOWEB IN ({$placeholders})",
                $webIds
            );

            $erpCodeByWebId = [];
            foreach ($erpRows as $erpRow) {
                $webId = trim((string) ($erpRow['PEDIDOWEB'] ?? ''));
                $codigo = trim((string) ($erpRow['CODIGO'] ?? ''));
                if ($webId !== '' && $codigo !== '' && ctype_digit($codigo) && (int) $codigo > 0) {
                    $erpCodeByWebId[$webId] = $codigo;
                }
            }

            if ($erpCodeByWebId === []) {
                $this->logger->warning(
                    '[B2B-Sectra] Nenhum CODIGO devolvido pelo ERP; mapa de pedidos nao gravado',
                    ['orders' => $orderIds]
                );
                return 0;
            }

            $connection = $this->resourceConnection->getConnection();
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $mapped = 0;

            foreach ($orderIds as $orderId) {
                $webId = (string) ($orderId + self::OC_ORDER_ID_OFFSET);
                if (!isset($erpCodeByWebId[$webId])) {
                    continue;
                }
                $erpCode = $erpCodeByWebId[$webId];

                $existing = $connection->fetchOne(
                    $connection->select()
                        ->from('grupoawamotos_erp_entity_map', 'map_id')
                        ->where('entity_type = ?', 'order')
                        ->where('erp_code = ?', $erpCode)
                );

                if ($existing) {
                    $connection->update(
                        'grupoawamotos_erp_entity_map',
                        ['magento_entity_id' => $orderId, 'last_sync_at' => $now],
                        ['entity_type = ?' => 'order', 'erp_code = ?' => $erpCode]
                    );
                } else {
                    $connection->insert('grupoawamotos_erp_entity_map', [
                        'entity_type' => 'order',
                        'erp_code' => $erpCode,
                        'magento_entity_id' => $orderId,
                        'last_sync_at' => $now,
                        'sync_hash' => null,
                    ]);
                }
                $mapped++;
            }

            $this->logger->info('[B2B-Sectra] Mapa de pedidos gravado no caminho de reconciliacao (task-55)', [
                'mapeados' => $mapped,
                'sem_codigo_no_erp' => count($orderIds) - $mapped,
            ]);

            return $mapped;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[B2B-Sectra] Falha ao gravar o mapa de pedidos importados: ' . $e->getMessage()
            );
            return 0;
        }
    }
TXT;

$src = file_get_contents($target);
if (substr_count($src, $anchor) !== 1) {
    $a2 = str_replace("\n", "\r\n", $anchor);
    if (substr_count($src, $a2) === 1) {
        $anchor = $a2;
        $novo = str_replace("\n", "\r\n", $novo);
    }
}
$n = substr_count($src, $anchor);
echo "ancoras exatas encontradas = $n\n";
echo 'construtores antes = ' . substr_count($src, 'function __construct') . "\n";
if ($n !== 1) {
    fwrite(STDERR, "ABORTA: esperava 1 ancora, achei $n\n");
    exit(2);
}

$new = str_replace($anchor, $novo, $src);
file_put_contents('/tmp/mapa.new.php', $new);
file_put_contents('/tmp/mapa.old.php', $src);

$out = [];
exec('php -l /tmp/mapa.new.php 2>&1', $out, $rc);
echo 'php -l (novo) => rc=' . $rc . ' :: ' . implode(' | ', $out) . "\n";
if ($rc !== 0) {
    fwrite(STDERR, "ABORTA: nao passa no lint; nada escrito\n");
    exit(3);
}

echo "\n--- DIFF ---\n";
system('diff -u /tmp/mapa.old.php /tmp/mapa.new.php | head -40');

if (!$apply) {
    echo "\nDRY-RUN: nada escrito.\n";
    exit(0);
}

if (!is_dir($bakDir) && !mkdir($bakDir, 0775, true)) {
    fwrite(STDERR, "ABORTA: nao criei $bakDir\n");
    exit(4);
}
copy($target, $bakDir . '/OrderImportGate.php.orig');
file_put_contents($bakDir . '/ANTES.txt', sprintf(
    "arquivo: %s\nsha256: %s\nbytes: %d\ndono: %d\ngrupo: %d\nmodo: %o\nmotivo: task-55 mapa de pedidos na reconciliacao\n",
    $target, hash_file('sha256', $target), filesize($target), fileowner($target), filegroup($target), fileperms($target) & 0777
));

file_put_contents($target, $new);
clearstatcache(true, $target);
echo "\nBACKUP = $bakDir\n";
echo 'APLICADO: sha256 ' . hash_file('sha256', $target) . ' | bytes=' . filesize($target)
    . ' | dono/grupo/modo=' . fileowner($target) . '/' . filegroup($target) . '/' . sprintf('%o', fileperms($target) & 0777) . "\n";
echo 'construtores depois = ' . substr_count(file_get_contents($target), 'function __construct') . "\n";
