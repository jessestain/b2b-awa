<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\B2B\Service\CustomerGroupManager;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\B2BClientRegistration;
use GrupoAwamotos\ERPIntegration\Model\Connection;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Cron: Maintain OpenCart bridge tables for Sectra integration.
 *
 * Keeps oc_customer_id_map and oc_customer_b2b_confirmed in sync with
 * Magento customer_entity so that new B2B customers and their orders
 * are visible to Sectra through the oc_order VIEW.
 *
 * Handles:
 *  - Auto-mapping new B2B customers to oc_customer_id_map (entity_id+200000)
 *  - Auto-confirming new B2B customers in oc_customer_b2b_confirmed
 *  - Syncing oc_customer TABLE with Magento customer data (for Sectra reads/writes)
 *  - Syncing oc_pre_registration so Sectra "Importar Clientes Prospect" finds new customers
 *  - Recovery of oc_customer_b2b_confirmed after Sectra's periodic TRUNCATE
 *
 * custom_field JSON structure (confirmed by Sectra):
 *   "6" = CNPJ (digits only)
 *   "2" = CPF  (digits only, empty for PJ)
 *   "3" = IE   (empty — not collected in Magento registration form)
 *   "1" = Razão Social / company name
 *
 * Schedule: every 5 minutes (via crontab.xml)
 */
class SyncOpenCartBridge
{
    /** @var int Offset applied to Magento entity_id to generate OpenCart-compatible customer_id */
    private const OC_CUSTOMER_ID_OFFSET = 200000;

    /** @var int Fallback OpenCart product_id when MT_MATERIAL.CODINTERNO is absent. */
    private const OC_PRODUCT_ID_OFFSET = 200000;

    /**
     * Bridge includes commercial groups + pending (prospects), resolved dynamically.
     */

    private const ATTR_CODE_B2B_CNPJ         = 'b2b_cnpj';
    private const ATTR_CODE_B2B_RAZAO_SOCIAL = 'b2b_razao_social';
    private const ATTR_CODE_ERP_CODE         = 'erp_code';
    private const WARNING_COOLDOWN_SECONDS = 21600;
    private const SQL_EXPORT_DIR = '/var/log';
    private const SQL_EXPORT_PREFIX = 'erp_register_clients_auto_';
    private const SQL_EXPORT_LATEST = 'erp_register_clients_pending_latest.sql';
    private const GENERATE_SQL_COMMAND = 'bin/magento erp:client:register --generate-sql';

    /** @var array<string, int> Resolved customer EAV attribute_id cache, keyed by attribute_code */
    private array $attributeIdCache = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Helper $helper,
        private readonly B2BClientRegistration $b2bRegistration,
        private readonly Connection $erpConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve a customer EAV attribute_id dynamically instead of relying on
     * hardcoded IDs, which are not stable across installations/migrations.
     */
    private function getAttributeId(string $attributeCode): int
    {
        if (isset($this->attributeIdCache[$attributeCode])) {
            return $this->attributeIdCache[$attributeCode];
        }

        $connection = $this->resourceConnection->getConnection();
        $attributeId = (int) $connection->fetchOne(
            "SELECT ea.attribute_id
             FROM eav_attribute ea
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = :attribute_code
               AND et.entity_type_code = 'customer'",
            ['attribute_code' => $attributeCode]
        );

        if ($attributeId <= 0) {
            $this->logger->warning(
                "[ERP Cron] Customer EAV attribute '{$attributeCode}' not found — related sync steps will be skipped."
            );
        }

        return $this->attributeIdCache[$attributeCode] = $attributeId;
    }

    public function execute(): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $mapped = $this->syncCustomerIdMap();
            $confirmed = $this->syncB2bConfirmed();
            $synced = $this->syncCustomerTable();
            $priceGroups = $this->syncOcCustomerPriceGroupsFromErp();
            $preReg = $this->syncPreRegistration();
            $preRegRemoved = $this->removeResolvedCustomersFromPreRegistration();
            $recovered = $this->recoverAfterTruncate();
            $registered = $this->registerPendingOrderClients();
            $products = $this->syncProductBridge();
            $viewPatched = $this->ensureOcOrderViewUsesConfirmedCustomers();

            if ($mapped > 0
                || $confirmed > 0
                || $synced > 0
                || $priceGroups > 0
                || $preReg > 0
                || $preRegRemoved > 0
                || $recovered > 0
                || $registered > 0
                || $products > 0
                || $viewPatched
            ) {
                $this->logger->info('[ERP Cron] OpenCart bridge sync completed', [
                    'new_mappings' => $mapped,
                    'new_confirmations' => $confirmed,
                    'customer_table_synced' => $synced,
                    'price_groups_synced' => $priceGroups,
                    'pre_registration_synced' => $preReg,
                    'pre_registration_removed_resolved' => $preRegRemoved,
                    'truncate_recovery' => $recovered,
                    'b2b_registrations' => $registered,
                    'product_bridge_synced' => $products,
                    'oc_order_view_patched' => $viewPatched,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->critical('[ERP Cron] OpenCart bridge sync failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Sync oc_product and oc_product_id_map for the OpenCart bridge.
     *
     * Sectra "Importar Pedidos AWA" resolves each oc_order_product item through:
     *   product_id -> oc_product.product_id -> model -> MT_MATERIAL.CODIGO.
     * The oc_order_product VIEW computes product_id as
     *   COALESCE(oc_product_id_map.old_oc_product_id, soi.product_id + 200000),
     * so every catalog SKU needs both an oc_product row (model = sku) and an
     * oc_product_id_map row (old_oc_product_id = oc_product.product_id),
     * otherwise order import fails with "Produto nao foi encontrado".
     *
     * product_id convention: MT_MATERIAL.CODINTERNO when numeric, else
     * entity_id + OC_PRODUCT_ID_OFFSET (matching the VIEW fallback).
     */
    public function syncProductBridge(): int
    {
        $connection = $this->resourceConnection->getConnection();

        $catalog = [];
        foreach ($connection->fetchAll('SELECT entity_id, TRIM(sku) AS sku FROM catalog_product_entity') as $row) {
            $sku = (string) $row['sku'];
            if ($sku !== '') {
                $catalog[$sku] = (int) $row['entity_id'];
            }
        }
        if ($catalog === []) {
            return 0;
        }

        $existingModels  = array_flip($connection->fetchCol('SELECT model FROM oc_product'));
        $existingMapSkus = array_flip($connection->fetchCol('SELECT magento_sku FROM oc_product_id_map'));

        $missingSkus = array_unique(array_merge(
            array_keys(array_diff_key($catalog, $existingModels)),
            array_keys(array_diff_key($catalog, $existingMapSkus))
        ));
        if ($missingSkus === []) {
            return 0;
        }

        $codinternoBySku = $this->fetchCodinternoBySku($missingSkus);
        $usedProductIds  = array_flip(array_map('intval', $connection->fetchCol('SELECT product_id FROM oc_product')));

        $changes = 0;
        foreach ($missingSkus as $sku) {
            $entityId    = $catalog[$sku];
            $codinterno  = $codinternoBySku[$sku] ?? null;
            $productId   = ($codinterno !== null && preg_match('/^\d+$/', $codinterno))
                ? (int) $codinterno
                : ($entityId + self::OC_PRODUCT_ID_OFFSET);

            // Never reuse a product_id already owned by another model.
            if (isset($usedProductIds[$productId])) {
                $productId = $entityId + self::OC_PRODUCT_ID_OFFSET;
            }

            if (!isset($existingModels[$sku])) {
                $connection->insertOnDuplicate(
                    'oc_product',
                    [
                        'product_id'      => $productId,
                        'model'           => $sku,
                        'sku'             => $sku,
                        'quantity'        => 0,
                        'stock_status_id' => 7,
                        'status'          => 1,
                    ],
                    ['model', 'sku', 'quantity', 'stock_status_id', 'status']
                );
                $existingModels[$sku]    = true;
                $usedProductIds[$productId] = true;
                $changes++;
            }

            if (!isset($existingMapSkus[$sku])) {
                $connection->insertOnDuplicate(
                    'oc_product_id_map',
                    [
                        'magento_entity_id' => $entityId,
                        'magento_sku'       => $sku,
                        'old_oc_product_id' => $productId,
                        'old_oc_sku'        => $sku,
                    ],
                    ['old_oc_product_id', 'old_oc_sku', 'magento_entity_id']
                );
                $existingMapSkus[$sku] = true;
                $changes++;
            }
        }

        return $changes;
    }

    /**
     * @param list<string> $skus
     * @return array<string, string> SKU => CODINTERNO
     */
    private function fetchCodinternoBySku(array $skus): array
    {
        $result = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $rows = $this->erpConnection->query(
                    "SELECT CODIGO, CODINTERNO FROM MT_MATERIAL WHERE CODIGO IN ({$placeholders}) AND CODINTERNO IS NOT NULL AND CODINTERNO <> ''",
                    array_values($chunk)
                );
                foreach ($rows as $row) {
                    $result[trim((string) $row['CODIGO'])] = trim((string) $row['CODINTERNO']);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ERP Cron] Product bridge: failed to load CODINTERNO: ' . $e->getMessage());
                break;
            }
        }

        return $result;
    }

    /**
     * Auto-map new B2B customers that are not yet in oc_customer_id_map.
     *
     * Uses entity_id + 200000 as old_oc_customer_id (same convention as
     * the original create_opencart_views.sql script).
     */
    private function syncCustomerIdMap(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $groupIds = implode(',', $this->getBridgeB2bGroupIds());

        $sql = "
            INSERT INTO oc_customer_id_map (old_oc_customer_id, old_email, old_cnpj, magento_customer_id)
            SELECT
                ce.entity_id + :offset AS old_oc_customer_id,
                COALESCE(ce.email, '') AS old_email,
                COALESCE(
                    REPLACE(REPLACE(REPLACE(REPLACE(ce.taxvat, '.', ''), '/', ''), '-', ''), ' ', ''),
                    ''
                ) AS old_cnpj,
                ce.entity_id AS magento_customer_id
            FROM customer_entity ce
            LEFT JOIN oc_customer_id_map existing_map
                ON existing_map.magento_customer_id = ce.entity_id
            WHERE ce.group_id IN ({$groupIds})
              AND existing_map.magento_customer_id IS NULL
            ON DUPLICATE KEY UPDATE
                old_email = VALUES(old_email),
                old_cnpj = VALUES(old_cnpj),
                magento_customer_id = VALUES(magento_customer_id)
        ";

        $stmt = $connection->query($sql, ['offset' => self::OC_CUSTOMER_ID_OFFSET]);

        return (int) $stmt->rowCount();
    }

    /**
     * Auto-confirm new B2B customers in oc_customer_b2b_confirmed.
     *
     * Uses Sectra's GR_INTEGRACAOVALIDADOR as source of truth.
     * Only customers already registered in Sectra are marked as confirmed.
     */
    private function syncB2bConfirmed(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $groupIds = implode(',', $this->getBridgeB2bGroupIds());

        $erpCodeAttrId = $this->getAttributeId(self::ATTR_CODE_ERP_CODE);
        if ($erpCodeAttrId <= 0) {
            return 0;
        }

        $mappedRows = $connection->fetchAll(
            "SELECT map.old_oc_customer_id AS customer_id,
                    CAST(erp_attr.value AS UNSIGNED) AS erp_code
             FROM oc_customer_id_map map
             INNER JOIN customer_entity ce ON ce.entity_id = map.magento_customer_id
             INNER JOIN customer_entity_varchar erp_attr
                 ON erp_attr.entity_id = ce.entity_id
                 AND erp_attr.attribute_id = :attr_erp_code
             WHERE ce.group_id IN ({$groupIds})
               AND erp_attr.value REGEXP '^[0-9]+$'",
            ['attr_erp_code' => $erpCodeAttrId]
        );

        if (empty($mappedRows)) {
            return 0;
        }

        $registeredCodeList = $this->b2bRegistration->getRegisteredClientCodes();
        if (empty($registeredCodeList)) {
            if ($this->shouldLogWarningWithCooldown('b2b_registered_codes_empty', 'empty')) {
                $this->logger->warning('[ERP Cron] Sectra returned zero registered B2B codes; keeping current oc_customer_b2b_confirmed unchanged.');
            }
            return 0;
        }
        $registeredCodes = array_fill_keys($registeredCodeList, true);

        // Chave em oc_customer_b2b_confirmed DEVE ser a mesma usada por
        // ValidatorChecker::resolveSectraChave() e pela view oc_order:
        // COALESCE(erp_code, old_oc_customer_id). Preferir erp_code.
        $desiredIds = [];
        foreach ($mappedRows as $row) {
            $erpCode = (int) ($row['erp_code'] ?? 0);
            $mapCustomerId = (int) ($row['customer_id'] ?? 0);
            if ($erpCode <= 0) {
                continue;
            }

            if (isset($registeredCodes[$erpCode])) {
                $desiredIds[$erpCode] = true;
            }

            // Legado: alguns mapas usam old_oc_customer_id == código Sectra.
            if ($mapCustomerId > 0 && isset($registeredCodes[$mapCustomerId])) {
                $desiredIds[$mapCustomerId] = true;
            }
        }

        if (empty($desiredIds)) {
            if ($this->shouldLogWarningWithCooldown('b2b_confirmed_customers_empty', 'empty')) {
                $this->logger->warning('[ERP Cron] No confirmed B2B customers found in Sectra validator for current mapped ERP codes; keeping current oc_customer_b2b_confirmed unchanged.');
            }
            return 0;
        }

        $existingIds = array_map(
            'intval',
            $connection->fetchCol('SELECT customer_id FROM oc_customer_b2b_confirmed')
        );
        $existingSet = array_fill_keys($existingIds, true);

        $toInsert = [];
        foreach (array_keys($desiredIds) as $customerId) {
            if (!isset($existingSet[$customerId])) {
                $toInsert[] = $customerId;
            }
        }

        $toDelete = [];
        foreach ($existingIds as $customerId) {
            if (!isset($desiredIds[$customerId])) {
                $toDelete[] = $customerId;
            }
        }

        $changes = 0;

        if (!empty($toDelete)) {
            foreach (array_chunk($toDelete, 500) as $chunk) {
                $changes += (int) $connection->delete('oc_customer_b2b_confirmed', ['customer_id IN (?)' => $chunk]);
            }
        }

        if (!empty($toInsert)) {
            $now = gmdate('Y-m-d H:i:s');
            foreach (array_chunk($toInsert, 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $customerId) {
                    $rows[] = [
                        'customer_id' => $customerId,
                        'synced_at' => $now,
                    ];
                }
                if (!empty($rows)) {
                    $connection->insertMultiple('oc_customer_b2b_confirmed', $rows);
                    $changes += count($rows);
                }
            }
        }

        return $changes;
    }

    /**
     * Sync oc_customer TABLE with Magento customer data.
     *
     * Inserts new customers and updates changed data (name, email, phone, taxvat)
     * so that Sectra's "Exportar Clientes" and "Importar Pedidos" work correctly.
     * The oc_customer table was converted from a VIEW to a real TABLE to allow
     * Sectra to INSERT/UPDATE rows during "Exportar Clientes".
     */
    private function syncCustomerTable(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $cnpjAttrId = $this->getAttributeId(self::ATTR_CODE_B2B_CNPJ);
        $razaoAttrId = $this->getAttributeId(self::ATTR_CODE_B2B_RAZAO_SOCIAL);

        $sql = "
            INSERT INTO oc_customer (
                customer_id, customer_group_id, store_id, language_id,
                firstname, lastname, email, telephone, fax, password, salt,
                cart, wishlist, newsletter, address_id, custom_field, ip,
                status, safe, token, code, date_added
            )
            SELECT
                map.old_oc_customer_id AS customer_id,
                CASE WHEN ce.taxvat IS NOT NULL AND ce.taxvat != '' THEN 2 ELSE 1 END,
                ce.store_id,
                2 AS language_id,
                COALESCE(ce.firstname, '') AS firstname,
                COALESCE(ce.lastname, '') AS lastname,
                COALESCE(ce.email, '') AS email,
                COALESCE(
                    REPLACE(REPLACE(REPLACE(REPLACE(ca.telephone, '(', ''), ')', ''), '-', ''), ' ', ''),
                    ''
                ) AS telephone,
                '' AS fax,
                '' AS password,
                '' AS salt,
                NULL AS cart,
                NULL AS wishlist,
                0 AS newsletter,
                0 AS address_id,
                CONCAT(
                    '{\"6\":\"',
                    REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(
                            (SELECT value FROM customer_entity_varchar
                             WHERE entity_id = ce.entity_id AND attribute_id = :attr_cnpj LIMIT 1),
                            ce.taxvat, ''
                        ), '.', ''), '/', ''), '-', ''), ' ', ''),
                    '\",\"2\":\"\",\"3\":\"\",\"1\":\"',
                    REPLACE(
                        CONCAT(COALESCE(
                            (SELECT value FROM customer_entity_varchar
                             WHERE entity_id = ce.entity_id AND attribute_id = :attr_razao LIMIT 1),
                            CONCAT(COALESCE(ce.firstname,''), ' ', COALESCE(ce.lastname,''))
                        ), ''),
                    '\"', '\\\"'),
                    '\"}'
                ) AS custom_field,
                '' AS ip,
                1 AS status,
                1 AS safe,
                '' AS token,
                '' AS code,
                ce.created_at AS date_added
            FROM oc_customer_id_map map
            INNER JOIN customer_entity ce ON ce.entity_id = map.magento_customer_id
            LEFT JOIN (
                SELECT parent_id, MIN(entity_id) AS entity_id
                FROM customer_address_entity
                GROUP BY parent_id
            ) first_addr ON first_addr.parent_id = ce.entity_id
            LEFT JOIN customer_address_entity ca ON ca.entity_id = first_addr.entity_id
            WHERE map.magento_customer_id IS NOT NULL
            ON DUPLICATE KEY UPDATE
                firstname = VALUES(firstname),
                lastname = VALUES(lastname),
                email = VALUES(email),
                telephone = VALUES(telephone),
                custom_field = VALUES(custom_field),
                safe = 1
        ";

        $stmt = $connection->query($sql, [
            'attr_cnpj'  => $cnpjAttrId,
            'attr_razao' => $razaoAttrId,
        ]);

        return (int) $stmt->rowCount();
    }

    /**
     * Align oc_customer.customer_group_id with ERP FATORPRECO (lista de preço Sectra).
     *
     * The Magento→oc_customer upsert must not overwrite this field; Sectra Importar Pedidos
     * expects customer_group_id == FN_FORNECEDORES.FATORPRECO when VE_FATORPRECO.CKATIVO='S'.
     */
    private function syncOcCustomerPriceGroupsFromErp(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $erpAttrId = $this->getAttributeId(self::ATTR_CODE_ERP_CODE);
        if ($erpAttrId <= 0) {
            return 0;
        }

        $rows = $connection->fetchAll(
            "SELECT DISTINCT CAST(erp_attr.value AS UNSIGNED) AS erp_code,
                    map.old_oc_customer_id AS map_customer_id
             FROM customer_entity_varchar erp_attr
             INNER JOIN customer_entity ce ON ce.entity_id = erp_attr.entity_id
             LEFT JOIN oc_customer_id_map map ON map.magento_customer_id = ce.entity_id
             WHERE erp_attr.attribute_id = ?
               AND erp_attr.value REGEXP '^[0-9]+$'
               AND ce.group_id IN (" . implode(',', $this->getBridgeB2bGroupIds()) . ')',
            [$erpAttrId]
        );

        $erpCodes = [];
        foreach ($rows as $row) {
            $erpCode = (int) ($row['erp_code'] ?? 0);
            if ($erpCode > 0) {
                $erpCodes[$erpCode] = true;
            }
        }
        if ($erpCodes === []) {
            return 0;
        }

        $fatorByCode = $this->b2bRegistration->getActiveFatorPrecoByClientCodes(array_keys($erpCodes));
        if ($fatorByCode === []) {
            return 0;
        }

        $changes = 0;
        foreach ($rows as $row) {
            $erpCode = (int) ($row['erp_code'] ?? 0);
            $mapId = (int) ($row['map_customer_id'] ?? 0);
            $fator = (int) ($fatorByCode[$erpCode] ?? 0);
            if ($erpCode <= 0 || $fator <= 0) {
                continue;
            }

            foreach (array_unique(array_filter([$erpCode, $mapId])) as $customerId) {
                $updated = (int) $connection->update(
                    'oc_customer',
                    ['customer_group_id' => $fator],
                    [
                        'customer_id = ?' => $customerId,
                        'customer_group_id != ?' => $fator,
                    ]
                );
                $changes += $updated;
            }
        }

        return $changes;
    }

    /**
     * Sync B2B customers to oc_pre_registration so Sectra's
     * "Importar Clientes Prospect" can discover new Magento customers.
     *
     * Sectra reads:
     *   SELECT customer_id, firstname, lastname, email, telephone,
     *          CAST(custom_field AS CHAR(1000)) AS lkcustomfield
     *   FROM oc_pre_registration
     *   WHERE customer_id NOT IN (CLIENTESPRE)
     *
     * custom_field keys (per Sectra spec):
     *   "6" = CNPJ (digits only)   "2" = CPF (empty for PJ)
     *   "3" = IE   (empty — not collected)   "1" = Razão Social
     *
     * IMPORTANT — avoids re-registering customers who already exist in Sectra:
     * Customers that already have a resolved `erp_code` (either because they were
     * bulk-migrated from Sectra originally, or because ResolveCustomerErpCodes
     * later matched them by CPF/CNPJ) are NOT sent here. Sending them would
     * either (a) just be noise — Sectra already has them, no "prospect" step
     * needed — or (b) in the worst case create a duplicate CHAVE in Sectra for
     * a customer who was auto-mapped with a synthetic old_oc_customer_id
     * (entity_id+200000) before their real erp_code was resolved.
     */
    private function syncPreRegistration(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $groupIds   = implode(',', $this->getBridgeB2bGroupIds());
        $cnpjAttrId = $this->getAttributeId(self::ATTR_CODE_B2B_CNPJ);
        $razaoAttrId = $this->getAttributeId(self::ATTR_CODE_B2B_RAZAO_SOCIAL);
        $erpCodeAttrId = $this->getAttributeId(self::ATTR_CODE_ERP_CODE);

        $sql = "
            INSERT INTO oc_pre_registration (
                customer_id, customer_group_id, store_id, language_id,
                firstname, lastname, email, telephone, fax,
                password, salt, cart, wishlist, newsletter, address_id,
                custom_field, ip, status, safe, token, code, date_added
            )
            SELECT
                map.old_oc_customer_id AS customer_id,
                2 AS customer_group_id,
                ce.store_id,
                2 AS language_id,
                COALESCE(ce.firstname, '') AS firstname,
                COALESCE(ce.lastname, '')  AS lastname,
                COALESCE(ce.email, '')     AS email,
                COALESCE(
                    REPLACE(REPLACE(REPLACE(REPLACE(ca.telephone,'(',''),')',''),'-',''),' ',''),
                    ''
                ) AS telephone,
                '' AS fax,
                '' AS password,
                '' AS salt,
                NULL AS cart,
                NULL AS wishlist,
                0  AS newsletter,
                0  AS address_id,
                CONCAT(
                    '{\"6\":\"',
                    REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(
                            (SELECT value FROM customer_entity_varchar
                             WHERE entity_id = ce.entity_id AND attribute_id = :attr_cnpj LIMIT 1),
                            ce.taxvat, ''
                        ), '.',''),'/',''),'-',''),' ',''),
                    '\",\"2\":\"\",\"3\":\"\",\"1\":\"',
                    REPLACE(
                        COALESCE(
                            (SELECT value FROM customer_entity_varchar
                             WHERE entity_id = ce.entity_id AND attribute_id = :attr_razao LIMIT 1),
                            CONCAT(COALESCE(ce.firstname,''), ' ', COALESCE(ce.lastname,''))
                        ),
                    '\"', '\\\"'),
                    '\"}'
                ) AS custom_field,
                '' AS ip,
                1  AS status,
                1  AS safe,
                '' AS token,
                '' AS code,
                ce.created_at AS date_added
            FROM oc_customer_id_map map
            INNER JOIN customer_entity ce ON ce.entity_id = map.magento_customer_id
            LEFT JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = ce.entity_id
                AND erp_attr.attribute_id = :attr_erp_code
            LEFT JOIN (
                SELECT parent_id, MIN(entity_id) AS entity_id
                FROM customer_address_entity
                GROUP BY parent_id
            ) first_addr ON first_addr.parent_id = ce.entity_id
            LEFT JOIN customer_address_entity ca ON ca.entity_id = first_addr.entity_id
            WHERE map.magento_customer_id IS NOT NULL
              AND ce.group_id IN ({$groupIds})
              AND (
                  erp_attr.value IS NULL
                  OR erp_attr.value = ''
                  OR erp_attr.value = '0'
                  OR erp_attr.value NOT REGEXP '^[0-9]+$'
              )
            ON DUPLICATE KEY UPDATE
                firstname    = VALUES(firstname),
                lastname     = VALUES(lastname),
                email        = VALUES(email),
                telephone    = VALUES(telephone),
                custom_field = VALUES(custom_field)
        ";

        $stmt = $connection->query($sql, [
            'attr_cnpj'     => $cnpjAttrId,
            'attr_razao'    => $razaoAttrId,
            'attr_erp_code' => $erpCodeAttrId,
        ]);

        return (int) $stmt->rowCount();
    }

    /**
     * Remove customers from oc_pre_registration once they get a resolved
     * `erp_code` (real Sectra CHAVE) — whether from the initial bulk mapping
     * or resolved later by ResolveCustomerErpCodes (CPF/CNPJ match).
     *
     * Self-heals the two duplication risks:
     *  - Legacy customers who already exist in Sectra but were left in the
     *    prospect queue (cosmetic noise, inflates "Importar Clientes
     *    Prospect" counts).
     *  - Customers auto-mapped with a synthetic old_oc_customer_id
     *    (entity_id+200000) who are later matched to a *different* real
     *    erp_code — these MUST be removed before Sectra ever imports them,
     *    otherwise a duplicate CHAVE would be created for an existing client.
     */
    private function removeResolvedCustomersFromPreRegistration(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $erpCodeAttrId = $this->getAttributeId(self::ATTR_CODE_ERP_CODE);

        if ($erpCodeAttrId <= 0) {
            return 0;
        }

        $sql = "
            DELETE pr FROM oc_pre_registration pr
            INNER JOIN oc_customer_id_map map ON map.old_oc_customer_id = pr.customer_id
            INNER JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = map.magento_customer_id
                AND erp_attr.attribute_id = :attr_erp_code
            WHERE erp_attr.value REGEXP '^[0-9]+$'
              AND erp_attr.value != '0'
        ";

        $stmt = $connection->query($sql, ['attr_erp_code' => $erpCodeAttrId]);

        return (int) $stmt->rowCount();
    }

    /**
     * Register pending-order B2B clients in Sectra's GR_INTEGRACAOVALIDADOR.
     *
     * For each non-imported active order, checks if the customer's erp_code
     * is registered with the correct B2B INTEGRACAOORIGEM. If write access
     * is available, registers automatically; otherwise logs the missing codes.
     */
    private function registerPendingOrderClients(): int
    {
        $connection = $this->resourceConnection->getConnection();

        $erpCodes = $connection->fetchCol("
            SELECT DISTINCT erp_attr.value
            FROM sales_order so
            INNER JOIN customer_entity ce ON ce.entity_id = so.customer_id
            INNER JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = ce.entity_id
                AND erp_attr.attribute_id = (
                    SELECT attribute_id FROM eav_attribute
                    WHERE attribute_code = 'erp_code'
                      AND entity_type_id = (
                          SELECT entity_type_id FROM eav_entity_type
                          WHERE entity_type_code = 'customer'
                      )
                )
            WHERE so.state NOT IN ('canceled', 'closed')
              AND erp_attr.value IS NOT NULL
              AND erp_attr.value != ''
              AND CAST(erp_attr.value AS UNSIGNED) > 0
              AND NOT EXISTS (
                  SELECT 1 FROM oc_order_imported oi WHERE oi.order_id = so.entity_id
              )
        ");

        if (empty($erpCodes)) {
            return 0;
        }

        $registered = 0;
        $missing = [];
        $writeAccess = $this->b2bRegistration->hasWriteAccess();
        $registeredCodes = array_fill_keys($this->b2bRegistration->getRegisteredClientCodes(), true);

        foreach ($erpCodes as $code) {
            $code = (int) $code;
            if ($code <= 0 || isset($registeredCodes[$code])) {
                continue;
            }

            if ($writeAccess) {
                if ($this->b2bRegistration->registerClient($code)) {
                    $registered++;
                    $registeredCodes[$code] = true;
                    continue;
                }

                // Write access may have been revoked (e.g. permission denied during INSERT).
                $writeAccess = $this->b2bRegistration->hasWriteAccess();
            }

            if (!$writeAccess) {
                $missing[] = $code;
            }
        }

        if (!empty($missing)) { // phpcs:ignore Squiz.Operators.ComparisonOperatorUsage
            sort($missing);
            $cliCommand = $this->buildGenerateSqlCommand($missing);
            $missingMessage = '[ERP Cron] B2B clients missing Sectra registration (no write access): '
                . implode(', ', $missing)
                . ' — execute "' . $cliCommand . '" or enable write connection';

            $payloadHash = hash('xxh128', implode(',', $missing));
            if ($this->shouldLogWarningWithCooldown('b2b_missing_clients', $payloadHash)) {
                $context = [
                    'cli_command' => $cliCommand,
                ];
                $sqlExportFiles = $this->exportMissingClientsSql($missing);
                if ($sqlExportFiles !== null) {
                    $context['sql_export_file'] = $sqlExportFiles['timestamped'];
                    $context['sql_export_latest_file'] = $sqlExportFiles['latest'];
                }
                $this->logger->warning($missingMessage, $context);
            }
        }

        return $registered;
    }

    /**
     * Export SQL with pending B2B registrations to help DBA/manual recovery.
     *
     * @param int[] $missingCodes
     * @return array{timestamped: string, latest: string}|null
     */
    private function exportMissingClientsSql(array $missingCodes): ?array
    {
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        $exportDir = rtrim($basePath, '/') . self::SQL_EXPORT_DIR;

        if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
            return null;
        }

        try {
            $sql = $this->b2bRegistration->generateRegistrationSQL($missingCodes);
            $filename = self::SQL_EXPORT_PREFIX . gmdate('Ymd_His') . '.sql';
            $timestampedPath = $exportDir . '/' . $filename;
            $latestPath = $exportDir . '/' . self::SQL_EXPORT_LATEST;

            if (!$this->writeSqlExportFile($timestampedPath, $sql)) {
                return null;
            }

            $this->writeSqlExportFile($latestPath, $sql);

            return [
                'timestamped' => $timestampedPath,
                'latest' => $latestPath,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('[ERP Cron] Could not export B2B registration SQL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param int[] $missingCodes
     */
    private function buildGenerateSqlCommand(array $missingCodes): string
    {
        return self::GENERATE_SQL_COMMAND . ' ' . implode(',', $missingCodes);
    }

    private function writeSqlExportFile(string $absolutePath, string $sql): bool
    {
        if (@file_put_contents($absolutePath, $sql, LOCK_EX) === false) {
            return false;
        }

        @chmod($absolutePath, 0664);

        return true;
    }

    /**
     * Reconcile confirmed customers after Sectra-side changes.
     *
     * Kept as wrapper for backward compatibility/logging semantics.
     */
    private function recoverAfterTruncate(): int
    {
        return 0;
    }

    /**
     * Ensure oc_order view only exposes customers confirmed in Sectra.
     */
    private function ensureOcOrderViewUsesConfirmedCustomers(): bool
    {
        $connection = $this->resourceConnection->getConnection();

        $viewRow = $connection->fetchRow('SHOW CREATE VIEW oc_order');
        $viewSql = strtolower((string) ($viewRow['Create View'] ?? ''));

        $sql = "
            CREATE OR REPLACE VIEW oc_order AS
            SELECT
                so.entity_id + 200000 AS order_id,
                0 AS invoice_no,
                'MAG-' AS invoice_prefix,
                COALESCE(bridge_customer.store_id, 0) AS store_id,
                'AWA MOTOS' AS store_name,
                'https://awamotos.com.br/' AS store_url,
                COALESCE(
                    NULLIF(CAST(erp_attr.value AS UNSIGNED), 0),
                    m.old_oc_customer_id,
                    (so.customer_id + 200000)
                ) AS customer_id,
                bridge_customer.customer_group_id AS customer_group_id,
                COALESCE(so.customer_firstname, '') AS firstname,
                COALESCE(so.customer_lastname, '') AS lastname,
                COALESCE(so.customer_email, '') AS email,
                COALESCE(ba.telephone, '') AS telephone,
                '' AS fax,
                COALESCE(bridge_customer.custom_field, '{\"6\":\"\",\"2\":\"\",\"3\":\"\",\"1\":\"\"}') AS custom_field,
                COALESCE(ba.firstname, so.customer_firstname, '') AS payment_firstname,
                COALESCE(ba.lastname, so.customer_lastname, '') AS payment_lastname,
                COALESCE(ba.company, '') AS payment_company,
                COALESCE(SUBSTRING_INDEX(ba.street, '\\n', 1), '') AS payment_address_1,
                COALESCE(CASE
                    WHEN LOCATE('\\n', COALESCE(ba.street, '')) > 0
                    THEN TRIM(REPLACE(SUBSTR(ba.street, LOCATE('\\n', ba.street) + 1), '\\n', ', '))
                    ELSE ''
                END, '') AS payment_address_2,
                COALESCE(ba.city, '') AS payment_city,
                COALESCE(ba.postcode, '') AS payment_postcode,
                'Brasil' AS payment_country,
                30 AS payment_country_id,
                COALESCE(ba.region, '') AS payment_zone,
                COALESCE(bz.zone_id, 0) AS payment_zone_id,
                '' AS payment_address_format,
                COALESCE(bridge_customer.custom_field, '{\"6\":\"\",\"2\":\"\",\"3\":\"\",\"1\":\"\"}')
                    AS payment_custom_field,
                COALESCE(sop.method, '') AS payment_method,
                COALESCE(sop.method, '') AS payment_code,
                COALESCE(sa.firstname, so.customer_firstname, '') AS shipping_firstname,
                COALESCE(sa.lastname, so.customer_lastname, '') AS shipping_lastname,
                COALESCE(sa.company, '') AS shipping_company,
                COALESCE(SUBSTRING_INDEX(sa.street, '\\n', 1), '') AS shipping_address_1,
                COALESCE(CASE
                    WHEN LOCATE('\\n', COALESCE(sa.street, '')) > 0
                    THEN TRIM(REPLACE(SUBSTR(sa.street, LOCATE('\\n', sa.street) + 1), '\\n', ', '))
                    ELSE ''
                END, '') AS shipping_address_2,
                COALESCE(sa.city, '') AS shipping_city,
                COALESCE(sa.postcode, '') AS shipping_postcode,
                'Brasil' AS shipping_country,
                30 AS shipping_country_id,
                COALESCE(sa.region, '') AS shipping_zone,
                COALESCE(sz.zone_id, 0) AS shipping_zone_id,
                '' AS shipping_address_format,
                COALESCE(bridge_customer.custom_field, '{\"6\":\"\",\"2\":\"\",\"3\":\"\",\"1\":\"\"}')
                    AS shipping_custom_field,
                COALESCE(so.shipping_method, '') AS shipping_method,
                COALESCE(so.shipping_method, '') AS shipping_code,
                '' AS comment,
                CAST(so.grand_total AS DECIMAL(15,4)) AS total,
                1 AS order_status_id,
                0 AS affiliate_id,
                CAST(0 AS DECIMAL(15,4)) AS commission,
                0 AS marketing_id,
                '' AS tracking,
                2 AS language_id,
                2 AS currency_id,
                'BRL' AS currency_code,
                CAST(1.00000000 AS DECIMAL(15,8)) AS currency_value,
                COALESCE(so.remote_ip, '') AS ip,
                '' AS forwarded_ip,
                '' AS user_agent,
                '' AS accept_language,
                so.created_at AS date_added,
                so.created_at AS date_modified
            FROM sales_order so
            LEFT JOIN sales_order_address ba
                ON ba.parent_id = so.entity_id
                AND ba.address_type = 'billing'
            LEFT JOIN sales_order_address sa
                ON sa.parent_id = so.entity_id
                AND sa.address_type = 'shipping'
            LEFT JOIN sales_order_payment sop
                ON sop.parent_id = so.entity_id
            LEFT JOIN oc_zone bz
                ON bz.name COLLATE utf8mb4_general_ci = ba.region
                AND bz.country_id = 30
            LEFT JOIN oc_zone sz
                ON sz.name COLLATE utf8mb4_general_ci = sa.region
                AND sz.country_id = 30
            LEFT JOIN oc_customer_id_map m
                ON m.magento_customer_id = so.customer_id
            LEFT JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = so.customer_id
                AND erp_attr.attribute_id = (
                    SELECT ea.attribute_id
                    FROM eav_attribute ea
                    INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                    WHERE ea.attribute_code = 'erp_code'
                      AND et.entity_type_code = 'customer'
                )
                AND erp_attr.value REGEXP '^[0-9]+$'";
        $sql .= "
            INNER JOIN oc_customer bridge_customer
                ON bridge_customer.customer_id = COALESCE(
                    NULLIF(CAST(erp_attr.value AS UNSIGNED), 0),
                    m.old_oc_customer_id,
                    (so.customer_id + 200000)
                )
            INNER JOIN oc_customer_b2b_confirmed b2b_confirmed
                ON b2b_confirmed.customer_id = COALESCE(
                    NULLIF(CAST(erp_attr.value AS UNSIGNED), 0),
                    m.old_oc_customer_id,
                    (so.customer_id + 200000)
                )
            WHERE so.customer_id IS NOT NULL
              AND so.state IN ('new', 'pending_payment', 'processing')
              AND so.sectra_import_status = 'ready_for_import'
              AND JSON_UNQUOTE(JSON_EXTRACT(bridge_customer.custom_field, '$.\"6\"')) <> ''
              AND NOT EXISTS (
                  SELECT 1
                  FROM oc_order_imported oi
                  WHERE oi.order_id = (so.entity_id + 200000)
              )
        ";

        // VIEW está atualizada quando: tem todas as cláusulas obrigatórias,
        // subquery dinâmica de attribute_id, e date_modified = created_at
        // (OpenCardB2B filtra date_modified BETWEEN; updated_at desloca o
        // pedido para o dia UTC seguinte ao liberar sectra_import_status).
        $hasRequiredClauses = str_contains($viewSql, 'oc_customer_b2b_confirmed')
            && str_contains($viewSql, 'sectra_import_status')
            && str_contains($viewSql, 'erp_attr');
        $usesDynamicAttrId = str_contains($viewSql, 'eav_attribute');
        $usesCreatedAtAsDateModified = str_contains($viewSql, 'created_at` as `date_modified')
            || str_contains($viewSql, 'created_at as date_modified');

        if ($hasRequiredClauses && $usesDynamicAttrId && $usesCreatedAtAsDateModified) {
            return false;
        }

        $connection->query($sql);

        return true;
    }

    /**
     * Limit repetitive warnings across cron runs.
     *
     * Always logs immediately when payload hash changes.
     */
    private function shouldLogWarningWithCooldown(string $key, string $payloadHash): bool
    {
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        $lockDir = rtrim($basePath, '/') . '/var/locks';

        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return true;
        }

        $file = $lockDir . '/erp_warn_' . preg_replace('/[^a-z0-9_]+/i', '_', $key) . '.lock';
        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return true;
        }

        @chmod($file, 0666);

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return true;
        }

        try {
            rewind($handle);
            $raw = trim((string) stream_get_contents($handle));
            [$lastTsRaw, $lastHash] = array_pad(explode('|', $raw, 2), 2, '');
            $lastTs = (int) $lastTsRaw;
            $now = time();

            if ($lastHash !== '' && $lastHash !== $payloadHash) {
                $lastTs = 0;
            }

            if ($lastTs > 0 && ($now - $lastTs) < self::WARNING_COOLDOWN_SECONDS) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $now . '|' . $payloadHash);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<int>
     */
    private function getBridgeB2bGroupIds(): array
    {
        $ids = B2bGroupIds::resolve($this->resourceConnection);
        $pendingId = (int) $this->resourceConnection->getConnection()->fetchOne(
            $this->resourceConnection->getConnection()->select()
                ->from($this->resourceConnection->getTableName('customer_group'), 'customer_group_id')
                ->where('customer_group_code = ?', CustomerGroupManager::GROUP_NAME_PENDING)
        );
        if ($pendingId > 0) {
            $ids[] = $pendingId;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
