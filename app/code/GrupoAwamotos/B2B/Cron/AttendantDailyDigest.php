<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Cron;

use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Service\GroupNotifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resumo diario (17:30) de atividade: buscas de hoje (tabela propria) + produtos vistos
 * (report_viewed_product_index), agrupado por vendedora e limitado aos clientes atribuidos
 * a ela. Uma mensagem por vendedora com ate 5 clientes. Enfileirado via WhatsAppPublisher.
 */
class AttendantDailyDigest
{
    private const CONFIG_DIGEST = 'grupoawamotos_b2b/whatsapp/notify_attendant_activity_digest';
    private const MAX_CUSTOMERS = 5;
    private const MAX_SEARCHES = 3;
    private const MAX_PRODUCTS = 2;
    private const MAX_MESSAGE_LENGTH = 1800;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WhatsAppPublisher $whatsAppPublisher,
        private readonly GroupNotifier $groupNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_DIGEST, ScopeInterface::SCOPE_STORE)) {
            return;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $searchTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_search_activity');
            $viewTable = $this->resourceConnection->getTableName('report_viewed_product_index');
            $mapTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_attendant');
            $attendantTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_attendants');
            $customerTable = $this->resourceConnection->getTableName('customer_entity');
            $customerVarchar = $this->resourceConnection->getTableName('customer_entity_varchar');
            $productVarchar = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

            $searchRows = $connection->fetchAll(
                "SELECT customer_id, GROUP_CONCAT(DISTINCT query_text ORDER BY query_text SEPARATOR ' | ') AS queries
                 FROM {$searchTable}
                 WHERE created_at >= UTC_DATE()
                 GROUP BY customer_id
                 LIMIT 500"
            );
            $viewRows = $connection->fetchAll(
                "SELECT customer_id, COUNT(*) AS total
                 FROM {$viewTable}
                 WHERE customer_id > 0 AND added_at >= UTC_DATE()
                 GROUP BY customer_id
                 LIMIT 500"
            );
            $topViewRows = $connection->fetchAll(
                "SELECT customer_id, product_id, COUNT(*) AS total
                 FROM {$viewTable}
                 WHERE customer_id > 0 AND added_at >= UTC_DATE()
                 GROUP BY customer_id, product_id
                 ORDER BY total DESC
                 LIMIT 1000"
            );

            $activity = [];
            foreach ($searchRows as $row) {
                $terms = array_values(array_filter(array_map('trim', explode('|', (string) $row['queries']))));
                $activity[(int) $row['customer_id']]['searches'] = array_slice($terms, 0, 10);
            }
            foreach ($viewRows as $row) {
                $activity[(int) $row['customer_id']]['views'] = (int) $row['total'];
            }
            foreach ($topViewRows as $row) {
                $activity[(int) $row['customer_id']]['products'][(int) $row['product_id']] =
                    (int) $row['total'];
            }

            if ($activity === []) {
                $this->logger->info('[B2B Digest Vendedoras] Sem atividade hoje.');
                return;
            }

            $customerIds = array_keys($activity);
            $inCustomers = implode(',', array_map('intval', $customerIds));

            $map = $connection->fetchPairs(
                "SELECT ca.customer_id, ca.attendant_id
                 FROM {$mapTable} ca
                 JOIN {$attendantTable} a ON a.attendant_id = ca.attendant_id
                 WHERE ca.customer_id IN ({$inCustomers}) AND a.is_active = 1
                   AND a.whatsapp IS NOT NULL AND a.whatsapp <> ''"
            );
            if ($map === []) {
                $this->logger->info('[B2B Digest Vendedoras] Atividade sem vendedora atribuida.');
                return;
            }

            $names = $connection->fetchPairs(
                "SELECT entity_id, firstname FROM {$customerTable} WHERE entity_id IN ({$inCustomers})"
            );
            $razaoAttrId = (int) $connection->fetchOne(
                "SELECT attribute_id FROM eav_attribute
                 WHERE entity_type_id = 1 AND attribute_code = 'b2b_razao_social' LIMIT 1"
            );
            $empresas = $razaoAttrId > 0
                ? $connection->fetchPairs(
                    "SELECT entity_id, value FROM {$customerVarchar}
                     WHERE attribute_id = {$razaoAttrId} AND entity_id IN ({$inCustomers})"
                )
                : [];

            // Nomes dos produtos em destaque (top 2 por cliente)
            $productIds = [];
            foreach ($activity as $customerId => $data) {
                if (!empty($data['products'])) {
                    arsort($data['products']);
                    $activity[$customerId]['topProducts'] = array_slice(array_keys($data['products']), 0, self::MAX_PRODUCTS);
                    $productIds = array_merge($productIds, $activity[$customerId]['topProducts']);
                }
            }
            $productNames = [];
            if ($productIds !== []) {
                $inProducts = implode(',', array_map('intval', array_unique($productIds)));
                $nameAttrId = (int) $connection->fetchOne(
                    "SELECT attribute_id FROM eav_attribute
                     WHERE entity_type_id = 4 AND attribute_code = 'name' LIMIT 1"
                );
                if ($nameAttrId > 0) {
                    $productNames = $connection->fetchPairs(
                        "SELECT entity_id, value FROM {$productVarchar}
                         WHERE attribute_id = {$nameAttrId} AND store_id = 0 AND entity_id IN ({$inProducts})"
                    );
                }
            }

            $byAttendant = [];
            foreach ($map as $customerId => $attendantId) {
                $byAttendant[(int) $attendantId][] = (int) $customerId;
            }

            $inAttendants = implode(',', array_map('intval', array_keys($byAttendant)));
            $attendantPhones = $connection->fetchPairs(
                "SELECT attendant_id, whatsapp FROM {$attendantTable} WHERE attendant_id IN ({$inAttendants})"
            );

            $sent = 0;
            foreach ($byAttendant as $attendantId => $customerIdsForAttendant) {
                $phone = trim((string) ($attendantPhones[$attendantId] ?? ''));
                if ($phone === '') {
                    continue;
                }
                usort($customerIdsForAttendant, function (int $a, int $b) use ($activity): int {
                    return $this->activityScore($activity[$b] ?? []) <=> $this->activityScore($activity[$a] ?? []);
                });

                $lines = [];
                foreach (array_slice($customerIdsForAttendant, 0, self::MAX_CUSTOMERS) as $customerId) {
                    $data = $activity[$customerId] ?? [];
                    $name = trim((string) ($names[$customerId] ?? 'Cliente'));
                    $empresa = trim((string) ($empresas[$customerId] ?? ''));
                    $line = '• *' . $name . '*';
                    if ($empresa !== '' && $empresa !== $name) {
                        $line .= ' (' . mb_substr($empresa, 0, 40) . ')';
                    }
                    $parts = [];
                    $terms = array_slice($data['searches'] ?? [], 0, self::MAX_SEARCHES);
                    if ($terms !== []) {
                        $parts[] = 'buscou: ' . implode(', ', $terms);
                    }
                    if (!empty($data['views'])) {
                        $parts[] = 'viu ' . (int) $data['views'] . ' produto(s)';
                    }
                    $highlights = [];
                    foreach ($data['topProducts'] ?? [] as $productId) {
                        if (!empty($productNames[$productId])) {
                            $highlights[] = (string) $productNames[$productId];
                        }
                    }
                    if ($highlights !== []) {
                        $parts[] = 'destaques: ' . implode(', ', $highlights);
                    }
                    if ($parts !== []) {
                        $lines[] = $line . "\n   " . implode(' · ', $parts);
                    }
                }
                if ($lines === []) {
                    continue;
                }

                $message = "📊 *Atividade de hoje dos seus clientes*\n\n"
                    . implode("\n\n", $lines)
                    . "\n\n(aviso automático AWA Motos)";
                if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                    $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 10) . '…';
                }

                $this->whatsAppPublisher->publishText($phone, $message);
                $sent++;
            }

            // Cópia consolidada para o grupo operacional (se configurado)
            $this->sendGroupDigest($activity, $names, $empresas, $productNames, $customerIds);

            $this->logger->info(sprintf(
                '[B2B Digest Vendedoras] clientes_com_atividade=%d vendedoras_aviso=%d',
                count($activity),
                $sent
            ));
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Digest Vendedoras] ' . $e->getMessage());
        }
    }

    private function activityScore(array $data): int
    {
        return count($data['searches'] ?? []) + (int) ($data['views'] ?? 0);
    }

    /**
     * Envia uma copia consolidada do resumo diario para o grupo operacional.
     *
     * @param array<int, array<string, mixed>> $activity
     * @param array<int, string> $names
     * @param array<int, string> $empresas
     * @param array<int, string> $productNames
     * @param array<int, int> $customerIds
     */
    private function sendGroupDigest(
        array $activity,
        array $names,
        array $empresas,
        array $productNames,
        array $customerIds
    ): void {
        if (!$this->groupNotifier->isConfigured()) {
            return;
        }

        $sorted = $customerIds;
        usort($sorted, function (int $a, int $b) use ($activity): int {
            return $this->activityScore($activity[$b] ?? []) <=> $this->activityScore($activity[$a] ?? []);
        });

        $lines = [];
        foreach (array_slice($sorted, 0, 10) as $customerId) {
            $data = $activity[$customerId] ?? [];
            $name = trim((string) ($names[$customerId] ?? 'Cliente'));
            $empresa = trim((string) ($empresas[$customerId] ?? ''));
            $line = '• *' . $name . '*';
            if ($empresa !== '' && $empresa !== $name) {
                $line .= ' (' . mb_substr($empresa, 0, 40) . ')';
            }
            $parts = [];
            $terms = array_slice($data['searches'] ?? [], 0, 2);
            if ($terms !== []) {
                $parts[] = 'buscou: ' . implode(', ', $terms);
            }
            if (!empty($data['views'])) {
                $parts[] = 'viu ' . (int) $data['views'] . ' produto(s)';
            }
            $highlights = [];
            foreach ($data['topProducts'] ?? [] as $productId) {
                if (!empty($productNames[$productId])) {
                    $highlights[] = (string) $productNames[$productId];
                }
            }
            if ($highlights !== []) {
                $parts[] = 'destaques: ' . implode(', ', $highlights);
            }
            if ($parts !== []) {
                $lines[] = $line . "\n   " . implode(' · ', $parts);
            }
        }
        if ($lines === []) {
            return;
        }

        $message = "📊 *Atividade de hoje na loja* (clientes B2B)\n\n"
            . implode("\n\n", $lines)
            . "\n\n(aviso automático AWA Motos)";
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 10) . '…';
        }
        $this->groupNotifier->send($message);
    }
}
