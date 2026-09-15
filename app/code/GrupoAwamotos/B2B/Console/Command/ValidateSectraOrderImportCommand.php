<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\ERPIntegration\Api\B2bOrderPullCustomerDataInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use GrupoAwamotos\ERPIntegration\Cron\SyncOpenCartBridge;
use GrupoAwamotos\ERPIntegration\Model\B2BClientRegistration;
use GrupoAwamotos\B2B\Model\Sectra\OrderImportGate;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

/**
 * Valida disponibilidade de pedidos B2B para Sectra → Integração AWA → Importar Pedidos.
 */
class ValidateSectraOrderImportCommand extends Command
{
    private const OPTION_CUSTOMER_ID = 'customer-id';
    private const OPTION_CREATE_TEST_ORDER = 'create-test-order';
    private const OPTION_SKU = 'sku';
    private const OC_OFFSET = 200000;
    /** SKUs com histórico de importação bem-sucedida no Sectra (oc_product_id_map). */
    private const PREFERRED_SECTRA_TEST_SKUS = ['2305', '503', '557', '2220'];

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly B2bOrderPullCustomerDataInterface $orderPullCustomerData,
        private readonly ResourceConnection $resourceConnection,
        private readonly SyncOpenCartBridge $openCartBridge,
        private readonly B2BClientRegistration $b2bClientRegistration,
        private readonly ConnectionInterface $erpConnection,
        private readonly OrderImportGate $orderImportGate,
        private readonly OrderPullInterface $orderPull,
        private readonly CartManagementInterface $cartManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockHelper $stockHelper,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly State $appState,
        private readonly LoggerInterface $logger,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:sectra:validate-order-import')
            ->setDescription('Valida pedido B2B para Sectra Importar Pedidos (Integração AWA)')
            ->addOption(
                self::OPTION_CUSTOMER_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'ID Magento do cliente aprovado (ex.: 8905)'
            )
            ->addOption(
                self::OPTION_CREATE_TEST_ORDER,
                null,
                InputOption::VALUE_NONE,
                'Cria pedido de teste real para validar oc_order e payload pull'
            )
            ->addOption(
                self::OPTION_SKU,
                null,
                InputOption::VALUE_REQUIRED,
                'SKU do produto no pedido de teste (ex.: 2305). Usado com --create-test-order.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeAreaCode();

        $customerId = (int) $input->getOption(self::OPTION_CUSTOMER_ID);
        if ($customerId <= 0) {
            $output->writeln('<error>Informe --customer-id=8905</error>');
            return Command::FAILURE;
        }

        $createTestOrder = (bool) $input->getOption(self::OPTION_CREATE_TEST_ORDER);
        $sectraCustomerId = $this->resolveSectraCustomerId($customerId);
        if ($sectraCustomerId <= 0) {
            $output->writeln('<error>Não foi possível resolver a CHAVE Sectra do cliente informado.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Validação Sectra — Importar Pedidos</info>');
        $output->writeln(sprintf('Cliente Magento #%d (Sectra customer_id %d)', $customerId, $sectraCustomerId));

        $checks = $this->validateBridgeReadiness($customerId, $sectraCustomerId);
        foreach ($checks as $label => $ok) {
            $output->writeln(sprintf('  [%s] %s', $ok ? 'OK' : 'FALHA', $label));
        }

        if (in_array(false, $checks, true)) {
            $output->writeln('<comment>Executando bridge cron para sincronizar tabelas oc_*...</comment>');
            $this->openCartBridge->execute();
            $checks = $this->validateBridgeReadiness($customerId, $sectraCustomerId);
            foreach ($checks as $label => $ok) {
                $output->writeln(sprintf('  [%s] %s (pós-bridge)', $ok ? 'OK' : 'FALHA', $label));
            }
        }

        if ((bool) $input->getOption(self::OPTION_CREATE_TEST_ORDER)) {
            try {
                $sku = trim((string) $input->getOption(self::OPTION_SKU));
                $incrementId = $this->createTestOrder($customerId, $output, $sku !== '' ? $sku : null);
                if ($incrementId === null) {
                    return Command::FAILURE;
                }
                $output->writeln(sprintf('<info>Pedido de teste criado: #%s</info>', $incrementId));
                $this->openCartBridge->execute();
            } catch (\Exception $e) {
                $output->writeln('<error>Falha ao criar pedido de teste: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        $ocOrders = $this->fetchOcOrders($sectraCustomerId);
        $output->writeln('');
        $output->writeln(sprintf('Pedidos visíveis em oc_order (Importar Pedidos): %d', count($ocOrders)));

        if ($ocOrders === []) {
            $output->writeln('<comment>Nenhum pedido em oc_order. Use --create-test-order para gerar um pedido de validação.</comment>');
            $this->printSectraImportGuidance($customerId, $sectraCustomerId, $checks, $output);
            return in_array(false, $checks, true) ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($ocOrders as $row) {
            $output->writeln(sprintf(
                '  order_id=%s | total=%s | payment=%s | CNPJ=%s',
                $row['order_id'],
                $row['total'],
                $row['payment_method'],
                $this->extractCnpjFromCustomField((string) ($row['custom_field'] ?? ''))
            ));
        }

        $this->printSectraImportChecklist($ocOrders, $sectraCustomerId, $output);
        $this->acknowledgeOrdersImportedInErp($ocOrders, $output);

        $pullResult = $this->orderPull->getPendingOrders(50);
        $payloadOk = $this->validatePullPayload($pullResult, $customerId, $output);

        return ($checks === [] || !in_array(false, $checks, true)) && $payloadOk
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $ocOrders
     */
    private function printSectraImportChecklist(
        array $ocOrders,
        int $sectraCustomerId,
        OutputInterface $output
    ): void {
        $conn = $this->resourceConnection->getConnection();
        $dbName = (string) $conn->fetchOne('SELECT DATABASE()');
        $dbHost = (string) gethostname();

        $output->writeln('');
        $output->writeln('<comment>── Checklist Importar Pedidos (Sectra) ──</comment>');
        $output->writeln(sprintf('  MySQL bridge: %s / %s (porta 3306)', $dbHost, $dbName));
        $output->writeln('  O Sectra grava PEDIDOWEB = oc_order.order_id (ex.: 200071, não #000000082)');

        $clientCadastro = $this->b2bClientRegistration->isClientRegistered($sectraCustomerId);
        $clientNative = $this->b2bClientRegistration->isErpNativeB2bClient($sectraCustomerId);
        $clientReady = $this->b2bClientRegistration->isClientReadyForSectraOrderImport($sectraCustomerId);

        $output->writeln(sprintf(
            '  Cliente ERP %d Cadastro de Cliente (7D4C6FBD): %s',
            $sectraCustomerId,
            $clientCadastro ? 'OK' : 'ausente'
        ));
        $output->writeln(sprintf(
            '  Cliente ERP %d Nativo ERP (FN_FORNECEDORES): %s',
            $sectraCustomerId,
            $clientNative ? 'sim (FN_FORNECEDORES)' : 'não'
        ));
        $output->writeln(sprintf(
            '  Cliente ERP %d Pronto p/ Importar Pedidos: %s',
            $sectraCustomerId,
            $clientReady ? '<info>SIM</info>' : '<error>NÃO — falta Cadastro (Exportar Clientes no Sectra)</error>'
        ));

        foreach ($ocOrders as $row) {
            $ocOrderId = (int) ($row['order_id'] ?? 0);
            if ($ocOrderId <= 0) {
                continue;
            }

            $items = $conn->fetchAll(
                'SELECT product_id, model, quantity FROM oc_order_product WHERE order_id = ?',
                [$ocOrderId]
            );
            $productIds = array_map(static fn (array $item): int => (int) ($item['product_id'] ?? 0), $items);
            $registeredProducts = $this->b2bClientRegistration->getRegisteredProductIds($productIds);
            $missingProducts = array_values(array_diff($productIds, $registeredProducts));

            $erpRow = null;
            try {
                $erpRows = $this->erpConnection->query(
                    "SELECT TOP 1 CODIGO, STATUS, VLRTOTAL, DTPEDIDO
                     FROM VE_PEDIDO
                     WHERE CAST(PEDIDOWEB AS VARCHAR(50)) = ?",
                    [(string) $ocOrderId]
                );
                $erpRow = $erpRows[0] ?? null;
            } catch (\Throwable) {
                $erpRow = null;
            }

            $magentoIncrement = (string) $conn->fetchOne(
                'SELECT increment_id FROM sales_order WHERE entity_id + ? = ?',
                [self::OC_OFFSET, $ocOrderId]
            );

            $output->writeln(sprintf(
                '  Pedido oc_order %d (#%s): produtos=%s | validador produto=%s | VE_PEDIDO=%s',
                $ocOrderId,
                $magentoIncrement !== '' ? $magentoIncrement : '?',
                implode(',', array_map(static fn (array $i): string => (string) ($i['model'] ?? ''), $items)),
                $missingProducts === [] ? 'OK' : 'FALTA ids ' . implode(',', $missingProducts),
                $erpRow
                    ? sprintf('IMPORTADO cod=%s status=%s', $erpRow['CODIGO'] ?? '?', $erpRow['STATUS'] ?? '?')
                    : 'AUSENTE — Importar Pedidos ainda não concluiu'
            ));
        }

        $output->writeln('  Teste MySQL no Sectra: SELECT order_id, customer_id, total FROM oc_order;');
    }

    /**
     * When Sectra imports into VE_PEDIDO but cannot write oc_order_imported (MySQL ack),
     * reconcile Magento state so the order leaves oc_order.
     *
     * @param list<array<string, mixed>> $ocOrders
     */
    private function acknowledgeOrdersImportedInErp(array $ocOrders, OutputInterface $output): void
    {
        $conn = $this->resourceConnection->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $acked = 0;

        foreach ($ocOrders as $row) {
            $ocOrderId = (int) ($row['order_id'] ?? 0);
            if ($ocOrderId <= 0) {
                continue;
            }

            $alreadyAcked = (bool) $conn->fetchOne(
                'SELECT order_id FROM oc_order_imported WHERE order_id = ?',
                [$ocOrderId]
            );
            if ($alreadyAcked) {
                continue;
            }

            try {
                $erpRows = $this->erpConnection->query(
                    "SELECT TOP 1 CODIGO FROM VE_PEDIDO WHERE CAST(PEDIDOWEB AS VARCHAR(50)) = ?",
                    [(string) $ocOrderId]
                );
            } catch (\Throwable) {
                continue;
            }

            if ($erpRows === []) {
                continue;
            }

            $conn->insertOnDuplicate(
                'oc_order_imported',
                [
                    'order_id' => $ocOrderId,
                    'date_imported' => $now,
                ],
                ['date_imported']
            );

            $conn->update(
                'sales_order',
                ['sectra_import_status' => 'imported'],
                ['entity_id = ?' => $ocOrderId - self::OC_OFFSET]
            );

            $incrementId = (string) $conn->fetchOne(
                'SELECT increment_id FROM sales_order WHERE entity_id + ? = ?',
                [self::OC_OFFSET, $ocOrderId]
            );

            $output->writeln(sprintf(
                '<info>  Reconciliado: #%s (oc_order %d) já está em VE_PEDIDO — removido da fila oc_order.</info>',
                $incrementId !== '' ? $incrementId : '?',
                $ocOrderId
            ));
            $acked++;
        }
    }

    /**
     * @param array<string, bool> $checks
     */
    private function printSectraImportGuidance(
        int $customerId,
        int $sectraCustomerId,
        array $checks,
        OutputInterface $output
    ): void {
        $conn = $this->resourceConnection->getConnection();
        $inPreReg = (bool) $conn->fetchOne(
            'SELECT customer_id FROM oc_pre_registration WHERE customer_id = ?',
            [$sectraCustomerId]
        );
        $registeredInErp = $this->b2bClientRegistration->isClientReadyForSectraOrderImport($sectraCustomerId);
        $readyCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM sales_order
             WHERE customer_id = ?
               AND sectra_import_status = 'ready_for_import'
               AND state IN ('new', 'pending_payment', 'processing')",
            [$customerId]
        );
        $cancelledCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM sales_order
             WHERE customer_id = ?
               AND sectra_import_status = 'order_cancelled_before_erp_import'",
            [$customerId]
        );

        $output->writeln('');
        $output->writeln('<comment>── Diagnóstico Importar Pedidos ──</comment>');

        if (!$registeredInErp && ($checks['oc_customer_b2b_confirmed'] ?? false) === false) {
            $output->writeln('<error>BLOQUEIO: cliente não está em Cadastro de Cliente no GR_INTEGRACAOVALIDADOR (Sectra).</error>');
            $hasProspect = $this->b2bClientRegistration->isClientProspectRegistered($sectraCustomerId);
            if (!$hasProspect) {
                $output->writeln('  <info>Pré-cadastro B2B ausente no validador — obrigatório mesmo para clientes já existentes no ERP.</info>');
                $output->writeln('  1. Sectra → Importar Clientes Prospect (CHAVE ' . $sectraCustomerId . ')');
                $output->writeln('  2. Sectra → Exportar Clientes (lê oc_customer — cria Cadastro de Cliente no validador)');
                $step = 3;
            } elseif ($inPreReg) {
                $output->writeln('  1. Sectra → Importar Clientes Prospect');
                $output->writeln('  2. Sectra → Exportar Clientes (lê oc_customer — cria Cadastro de Cliente no ERP)');
                $step = 3;
            } else {
                $output->writeln('  1. Pré-cadastro já no ERP — vá direto para Exportar Clientes');
                $prospectChave = $this->b2bClientRegistration->resolveProspectIntegrationChave($sectraCustomerId);
                if ($prospectChave !== null && $prospectChave !== $sectraCustomerId) {
                    $output->writeln(sprintf(
                        '  <comment>Prospect integrado como CHAVE ERP %d (bridge %d) — Exportar Clientes usa oc_customer #%d.</comment>',
                        $prospectChave,
                        $sectraCustomerId,
                        $prospectChave
                    ));
                }
                $output->writeln('  2. Sectra → Exportar Clientes (lê oc_customer — cria Cadastro de Cliente no ERP)');
                $step = 3;
            }
            $ocCode = trim((string) $conn->fetchOne(
                'SELECT code FROM oc_customer WHERE customer_id = ?',
                [$this->b2bClientRegistration->resolveSectraExportCustomerId($sectraCustomerId)]
            ));
            if ($ocCode !== '') {
                $output->writeln(sprintf(
                    '  <comment>ATENÇÃO: oc_customer.code=%s impede Exportar Clientes — bridge vai limpar no próximo cron.</comment>',
                    $ocCode
                ));
            }
            $exportId = $this->b2bClientRegistration->resolveSectraExportCustomerId($sectraCustomerId);
            $exportPhone = preg_replace(
                '/\D/',
                '',
                (string) $conn->fetchOne('SELECT telephone FROM oc_customer WHERE customer_id = ?', [$exportId])
            );
            if ($exportPhone === '' || preg_match('/^0+$/', $exportPhone) === 1 || strlen($exportPhone) < 10) {
                $output->writeln(sprintf(
                    '  <error>BLOQUEIO EXPORT: oc_customer #%d sem telefone válido — corrija no admin Magento (cliente #%d) antes do Exportar Clientes.</error>',
                    $exportId,
                    $customerId
                ));
            }
            if (!$this->b2bClientRegistration->hasWriteAccess()) {
                $cadastroChave = $sectraCustomerId;
                $prospectChave = $this->b2bClientRegistration->resolveProspectIntegrationChave($sectraCustomerId);
                if ($prospectChave !== null && $prospectChave !== $sectraCustomerId) {
                    $output->writeln(sprintf(
                        '  <comment>Alternativa SQL (preferir bridge %d, não prospect %d):</comment>',
                        $sectraCustomerId,
                        $prospectChave
                    ));
                }
                $output->writeln(sprintf(
                    '  <comment>bin/magento erp:client:register "%d" --generate-sql --save</comment>',
                    $cadastroChave
                ));
                $latestSql = (defined('BP') ? BP : getcwd()) . '/var/log/sectra_register_clients_latest.sql';
                if (is_readable($latestSql)) {
                    $output->writeln('  <comment>SQL pronto: ' . $latestSql . ' (executar no SSMS com usuário de escrita)</comment>');
                }
            }
            $step = $step ?? 3;
            $output->writeln('  ' . $step++ . '. bin/magento b2b:sectra:sync-prospect --poll');
            $output->writeln('  ' . $step++ . '. bin/magento b2b:sectra:validate-order-import --customer-id=' . $customerId);
            $output->writeln('  ' . $step . '. Sectra → Importar Pedidos');
            return;
        }

        if ($readyCount === 0 && $cancelledCount > 0) {
            $output->writeln(sprintf(
                '<comment>%d pedido(s) aguardando Exportar Clientes no Sectra. Após confirmar, o bridge libera automaticamente para oc_order.</comment>',
                $cancelledCount
            ));
            return;
        }

        if ($readyCount === 0) {
            $output->writeln('<comment>Nenhum pedido com status ready_for_import. Faça um pedido novo ou use --create-test-order.</comment>');
        }
    }

    /**
     * @return array<string, bool>
     */
    private function validateBridgeReadiness(int $customerId, int $sectraCustomerId): array
    {
        $conn = $this->resourceConnection->getConnection();

        $approval = (string) $conn->fetchOne(
            $conn->select()
                ->from(['cev' => 'customer_entity_varchar'], 'value')
                ->join(['ea' => 'eav_attribute'], 'ea.attribute_id = cev.attribute_id', [])
                ->where('cev.entity_id = ?', $customerId)
                ->where('ea.attribute_code = ?', 'b2b_approval_status')
        );

        $syncStatus = (string) $conn->fetchOne(
            $conn->select()
                ->from(['cev' => 'customer_entity_varchar'], 'value')
                ->join(['ea' => 'eav_attribute'], 'ea.attribute_id = cev.attribute_id', [])
                ->where('cev.entity_id = ?', $customerId)
                ->where('ea.attribute_code = ?', 'erp_customer_sync_status')
        );

        $map = $conn->fetchRow(
            "SELECT map.old_oc_customer_id,
                    COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id) AS sectra_chave
             FROM oc_customer_id_map map
             LEFT JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = map.magento_customer_id
                AND erp_attr.attribute_id = (
                    SELECT ea.attribute_id
                    FROM eav_attribute ea
                    INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                    WHERE ea.attribute_code = 'erp_code'
                      AND et.entity_type_code = 'customer'
                )
                AND erp_attr.value REGEXP '^[0-9]+$'
             WHERE map.magento_customer_id = ?",
            [$customerId]
        );
        $confirmed = $conn->fetchOne(
            'SELECT customer_id FROM oc_customer_b2b_confirmed WHERE customer_id = ?',
            [$sectraCustomerId]
        );
        $exportCustomerId = $this->b2bClientRegistration->resolveSectraExportCustomerId($sectraCustomerId);
        $ocCustomer = $conn->fetchRow(
            'SELECT customer_id, customer_group_id, custom_field, code, telephone FROM oc_customer WHERE customer_id = ?',
            [$exportCustomerId]
        );
        $cnpjInField = $ocCustomer
            ? $this->extractCnpjFromCustomField((string) ($ocCustomer['custom_field'] ?? ''))
            : '';
        $exportCode = $ocCustomer ? trim((string) ($ocCustomer['code'] ?? '')) : 'missing';
        $exportTelephone = $ocCustomer ? preg_replace('/\D/', '', (string) ($ocCustomer['telephone'] ?? '')) : '';
        $ocGroupId = $ocCustomer ? (int) ($ocCustomer['customer_group_id'] ?? 0) : 0;
        $erpGroupIds = $this->b2bClientRegistration->getErpClientCodesWithSalesHistory([$sectraCustomerId]);
        $expectedGroupId = 0;
        if ($erpGroupIds !== []) {
            try {
                $erpRow = $this->erpConnection->fetchOne(
                    'SELECT CASE WHEN fp.CKATIVO = \'S\' THEN f.FATORPRECO ELSE NULL END AS FATORPRECO
                     FROM FN_FORNECEDORES f
                     LEFT JOIN VE_FATORPRECO fp ON fp.CODIGO = f.FATORPRECO
                     WHERE f.CODIGO = ? AND f.CKCLIENTE = ?',
                    [$sectraCustomerId, 'S']
                );
                $expectedGroupId = (int) round((float) ($erpRow['FATORPRECO'] ?? 0));
            } catch (\Throwable) {
                $expectedGroupId = 0;
            }
        }
        $prospectOnly = $this->b2bClientRegistration->isClientProspectRegistered($sectraCustomerId)
            && !$this->b2bClientRegistration->isClientRegistered($sectraCustomerId);
        $telephoneOk = $exportTelephone !== ''
            && preg_match('/^0+$/', $exportTelephone) !== 1
            && strlen($exportTelephone) >= 10;

        return [
            'Cliente B2B approved' => $approval === 'approved',
            'erp_customer_sync_status definido' => $syncStatus !== '',
            'oc_customer_id_map / sectra_chave' => is_array($map)
                && (int) ($map['sectra_chave'] ?? 0) === $sectraCustomerId,
            'oc_customer_b2b_confirmed' => $confirmed !== false,
            'oc_customer com CNPJ em custom_field' => strlen($cnpjInField) === 14,
            'oc_customer code vazio (Exportar Clientes)' => !$prospectOnly || $exportCode === '',
            'oc_customer telephone (Exportar Clientes)' => !$prospectOnly || $telephoneOk,
            'oc_customer lista preço (FATORPRECO ERP)' => $expectedGroupId <= 0 || $ocGroupId === $expectedGroupId,
            'Pronto para comprar (dados fiscais)' => $this->orderPullCustomerData->isReadyForOrderPull($customerId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOcOrders(int $ocCustomerId): array
    {
        $conn = $this->resourceConnection->getConnection();

        try {
            return $conn->fetchAll(
                'SELECT order_id, customer_id, email, custom_field, total, payment_method
                 FROM oc_order WHERE customer_id = ? ORDER BY order_id DESC LIMIT 5',
                [$ocCustomerId]
            );
        } catch (\Exception) {
            return [];
        }
    }

    private function resolveSectraCustomerId(int $customerId): int
    {
        $conn = $this->resourceConnection->getConnection();
        $value = $conn->fetchOne(
            "SELECT COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id)
             FROM oc_customer_id_map map
             LEFT JOIN customer_entity_varchar erp_attr
                ON erp_attr.entity_id = map.magento_customer_id
                AND erp_attr.attribute_id = (
                    SELECT ea.attribute_id
                    FROM eav_attribute ea
                    INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                    WHERE ea.attribute_code = 'erp_code'
                      AND et.entity_type_code = 'customer'
                )
                AND erp_attr.value REGEXP '^[0-9]+$'
             WHERE map.magento_customer_id = ?",
            [$customerId]
        );

        return (int) ($value ?: 0);
    }

    /**
     * @param mixed $pullResult
     */
    private function validatePullPayload($pullResult, int $customerId, OutputInterface $output): bool
    {
        $data = is_array($pullResult) ? ($pullResult[0] ?? []) : [];
        $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];

        $output->writeln('');
        $output->writeln(sprintf('Pedidos na API pull (getPendingOrders): %d', count($orders)));

        $order = null;
        foreach ($orders as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $customer = is_array($candidate['customer'] ?? null) ? $candidate['customer'] : [];
            if ((int) ($customer['magento_customer_id'] ?? 0) === $customerId) {
                $order = $candidate;
                break;
            }
        }

        if ($order === null) {
            $held = is_array($data['held_orders'] ?? null) ? $data['held_orders'] : [];
            foreach ($held as $h) {
                if (is_array($h)) {
                    $output->writeln(sprintf(
                        '  RETIDO #%s — %s',
                        $h['increment_id'] ?? '?',
                        $h['reason'] ?? '?'
                    ));
                }
            }
            if ($held === []) {
                $output->writeln('<comment>Nenhum pedido pull encontrado para este cliente.</comment>');
            }
            return false;
        }

        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $required = ['magento_customer_id', 'cnpj', 'razao_social', 'email', 'telephone', 'custom_field'];
        $missing = [];
        foreach ($required as $field) {
            if (empty($customer[$field])) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $output->writeln('<error>Payload pull incompleto: ' . implode(', ', $missing) . '</error>');
            return false;
        }

        $output->writeln(sprintf(
            '  Payload OK — increment=%s | CNPJ=%s | mode=%s | itens=%d | total=R$ %.2f',
            $order['increment_id'] ?? '?',
            $customer['cnpj'] ?? '?',
            $customer['integration_mode'] ?? '?',
            (int) ($order['items_count'] ?? 0),
            (float) ($order['grand_total'] ?? 0)
        ));

        return true;
    }

    private function createTestOrder(int $customerId, OutputInterface $output, ?string $preferredSku = null): ?string
    {
        $customer = $this->customerRepository->getById($customerId);
        if (!$this->orderPullCustomerData->isApprovedB2bCustomer($customerId)) {
            throw new LocalizedException(__('Cliente #%1 não está aprovado B2B.', $customerId));
        }

        $product = $this->findSalableProduct($preferredSku);
        if ($product === null) {
            throw new LocalizedException(__('Nenhum produto simples salável encontrado para pedido de teste.'));
        }

        $this->deactivateActiveQuotes($customerId);

        $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
        $quote = $this->cartRepository->getActive($cartId);
        $quote->addProduct($this->productRepository->get($product->getSku()), 1);

        $address = $this->buildQuoteAddress($customerId, $customer->getEmail());
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address);

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->collectShippingRates();

        $shippingMethod = null;
        foreach ($shippingAddress->getAllShippingRates() as $rate) {
            $shippingMethod = $rate->getCode();
            if ($rate->getCode() === 'freeshipping_freeshipping') {
                break;
            }
        }
        $shippingAddress->setShippingMethod($shippingMethod ?: 'tablerate_bestway');

        $quote->setPaymentMethod('acombinar');
        $quote->getPayment()->importData(['method' => 'acombinar']);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        $orderId = $this->cartManagement->placeOrder($cartId);
        $order = $this->orderRepository->get($orderId);
        $this->orderImportGate->applyOnOrderPlace($order);

        $output->writeln(sprintf(
            '  Produto: %s (Sectra product_id %s) | Total: R$ %.2f | Payment: %s | sectra_import_status: %s',
            $product->getSku(),
            $this->resolveSectraProductId($product->getSku(), (int) $product->getId()),
            (float) $order->getGrandTotal(),
            $order->getPayment()?->getMethod() ?? '—',
            (string) $order->getData('sectra_import_status')
        ));

        return $order->getIncrementId();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuoteAddress(int $customerId, string $email): array
    {
        $addresses = $this->customerRepository->getById($customerId)->getAddresses();
        if ($addresses !== []) {
            foreach ($addresses as $addr) {
                if ($addr->isDefaultShipping() || $addr->isDefaultBilling()) {
                    return [
                        'customer_address_id' => $addr->getId(),
                        'firstname' => $addr->getFirstname(),
                        'lastname' => $addr->getLastname(),
                        'street' => $addr->getStreet(),
                        'city' => $addr->getCity(),
                        'region_id' => $addr->getRegionId(),
                        'region' => $addr->getRegion()?->getRegion(),
                        'postcode' => $addr->getPostcode(),
                        'country_id' => $addr->getCountryId(),
                        'telephone' => $addr->getTelephone(),
                        'company' => $addr->getCompany(),
                        'email' => $email,
                    ];
                }
            }
        }

        return [
            'firstname' => 'Teste',
            'lastname' => 'Sectra',
            'street' => ['Rua Teste Sectra, 100'],
            'city' => 'Araraquara',
            'region_id' => 485,
            'region' => 'São Paulo',
            'postcode' => '14801-000',
            'country_id' => 'BR',
            'telephone' => '16999999999',
            'email' => $email,
        ];
    }

    private function findSalableProduct(?string $preferredSku = null): ?\Magento\Catalog\Model\Product
    {
        if ($preferredSku !== null && $preferredSku !== '') {
            try {
                $product = $this->productRepository->get($preferredSku);
                if ($product->isSalable() && $product->getTypeId() === 'simple') {
                    return $product;
                }
            } catch (\Exception $e) {
                $this->logger->debug(
                    '[ValidateSectraOrderImportCommand::findSalableProduct] SKU preferido indisponível, '
                    . 'usando seleção automática: ' . $e->getMessage()
                );
            }
        }

        $registeredIds = array_flip($this->b2bClientRegistration->getRegisteredProductIds(
            $this->fetchMappedSectraProductIds()
        ));

        foreach (self::PREFERRED_SECTRA_TEST_SKUS as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                if (!$product->isSalable() || $product->getTypeId() !== 'simple') {
                    continue;
                }
                $sectraId = (int) $this->resolveSectraProductId($sku, (int) $product->getId());
                if ($sectraId > 0 && isset($registeredIds[$sectraId])) {
                    return $product;
                }
            } catch (\Exception) {
                continue;
            }
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['sku', 'name', 'price'])
            ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED)
            ->addAttributeToFilter('type_id', 'simple')
            ->addAttributeToFilter('price', ['gt' => 0])
            ->setPageSize(50);
        $this->stockHelper->addInStockFilterToCollection($collection);

        foreach ($collection as $product) {
            if (!$product->isSalable()) {
                continue;
            }
            $sectraId = (int) $this->resolveSectraProductId((string) $product->getSku(), (int) $product->getId());
            if ($sectraId > 0 && isset($registeredIds[$sectraId])) {
                return $product;
            }
        }

        foreach ($collection as $product) {
            if ($product->isSalable()) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    private function fetchMappedSectraProductIds(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $rows = $conn->fetchCol(
            'SELECT DISTINCT old_oc_product_id FROM oc_product_id_map WHERE old_oc_product_id > 0'
        );

        return array_map('intval', $rows ?: []);
    }

    private function resolveSectraProductId(string $sku, int $magentoProductId): int
    {
        $conn = $this->resourceConnection->getConnection();
        $mapped = (int) $conn->fetchOne(
            'SELECT old_oc_product_id FROM oc_product_id_map WHERE magento_sku = ?',
            [trim($sku)]
        );

        return $mapped > 0 ? $mapped : ($magentoProductId + self::OC_OFFSET);
    }

    private function deactivateActiveQuotes(int $customerId): void
    {
        $conn = $this->resourceConnection->getConnection();
        $conn->update(
            'quote',
            ['is_active' => 0],
            ['customer_id = ?' => $customerId, 'is_active = ?' => 1]
        );
    }

    private function extractCnpjFromCustomField(string $json): string
    {
        $data = json_decode($json, true);

        return is_array($data) ? preg_replace('/\D/', '', (string) ($data['6'] ?? '')) : '';
    }

    private function initializeAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception) {
            // already set
        }
    }
}
