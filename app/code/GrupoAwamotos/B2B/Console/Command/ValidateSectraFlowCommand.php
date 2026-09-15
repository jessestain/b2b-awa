<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\B2B\Model\Sectra\CheckoutBlockMessage;
use GrupoAwamotos\B2B\Model\Sectra\ProspectEvent;
use GrupoAwamotos\B2B\Model\Sectra\SectraImportStatus;
use GrupoAwamotos\B2B\Model\Sectra\StuckOrderCleanup;
use GrupoAwamotos\B2B\Model\Sectra\ValidatorChecker;
use GrupoAwamotos\B2B\Observer\ValidateB2bOrderErpReadinessObserver;
use GrupoAwamotos\ERPIntegration\Api\B2bOrderPullCustomerDataInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\QuoteFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateSectraFlowCommand extends Command
{
    private const DEFAULT_UNVALIDATED_ID = 8905;

    public function __construct(
        private readonly State $appState,
        private readonly ResourceConnection $resourceConnection,
        private readonly ValidatorChecker $validatorChecker,
        private readonly B2bOrderPullCustomerDataInterface $orderPullCustomerData,
        private readonly ValidateB2bOrderErpReadinessObserver $checkoutObserver,
        private readonly StuckOrderCleanup $stuckOrderCleanup,
        private readonly QuoteFactory $quoteFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:sectra:validate-flow')
            ->setDescription('Valida fluxo B2B → Sectra sem acesso operacional ao ERP')
            ->addOption('unvalidated-customer-id', null, InputOption::VALUE_OPTIONAL, 'Cliente não validado', (string) self::DEFAULT_UNVALIDATED_ID)
            ->addOption('validated-customer-id', null, InputOption::VALUE_OPTIONAL, 'Cliente validado (auto se omitido)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }

        $report = [];
        $unvalidatedId = (int) $input->getOption('unvalidated-customer-id');
        $validatedId = $input->getOption('validated-customer-id') !== null
            ? (int) $input->getOption('validated-customer-id')
            : $this->findValidatedCustomerId();

        $report[] = '=== RELATÓRIO VALIDAÇÃO B2B → SECTRA ===';
        $report[] = '';

        // 1. Cliente não validado
        $report[] = '## 1. Cliente não validado #' . $unvalidatedId;
        $report[] = $this->describeCustomer($unvalidatedId);
        $blockTest = $this->testCheckoutBlock($unvalidatedId);
        $report[] = 'Gate ERP (Importar Pedidos): ' . ($blockTest['blocked'] ? 'OK' : 'FALHOU');
        $report[] = 'Mensagem: ' . ($blockTest['message'] ?? '—');
        $report[] = '';

        // 2. Cliente validado
        $report[] = '## 2. Cliente validado #' . ($validatedId ?: 'N/A');
        if ($validatedId > 0) {
            $report[] = $this->describeCustomer($validatedId);
            $passTest = $this->testCheckoutPass($validatedId);
            $report[] = 'Gate ERP: ' . ($passTest['can_purchase'] ? 'OK (liberado)' : 'FALHOU — ' . ($passTest['reason'] ?? ''));
        } else {
            $report[] = 'AVISO: nenhum cliente approved + oc_customer_b2b_confirmed encontrado para teste positivo.';
        }
        $report[] = '';

        // 3. Cron dry-run
        $report[] = '## 3. Cron cancel stuck (dry-run)';
        $dryRun = $this->stuckOrderCleanup->cancelOrdersForUnvalidatedCustomers(true);
        $report[] = sprintf(
            'Candidatos: %d | Ignorados: %d | Cancelados: %d',
            count($dryRun['candidates']),
            count($dryRun['skipped']),
            $dryRun['cancelled']
        );
        foreach ($dryRun['skipped'] as $skip) {
            $report[] = '  skip #' . $skip['increment_id'] . ' — ' . $skip['reason'];
        }
        $report[] = '';

        // 4. Logs recentes
        $report[] = '## 4. Logs recentes (grupoawamotos_b2b_sectra_sync_log)';
        foreach ($this->fetchRecentLogs() as $log) {
            $report[] = sprintf(
                '  [%s] %s — %s',
                $log['event_type'],
                $log['message'],
                $log['created_at']
            );
        }
        $report[] = '';

        // 5. Pedidos afetados
        $report[] = '## 5. Pedidos cliente #' . $unvalidatedId;
        foreach ($this->fetchCustomerOrders($unvalidatedId) as $order) {
            $report[] = sprintf(
                '  #%s | %s | %s | oc_order=%s',
                $order['increment_id'],
                $order['state'],
                $order['sectra_import_status'] ?? 'NULL',
                $order['in_oc_order'] ? 'sim' : 'não'
            );
        }
        $report[] = '';

        // 6. Riscos
        $report[] = '## 6. Riscos restantes';
        $report[] = '- Validação ERP depende de oc_customer_b2b_confirmed (preenchido pelo bridge quando Sectra validar externamente).';
        $report[] = '- Clientes legados em b2b_confirmed sem approval comercial recente podem comprar (dados históricos).';
        $report[] = '- Pedidos pagos (total_paid > 0) nunca são cancelados pelo cron.';

        foreach ($report as $line) {
            $output->writeln($line);
        }

        $failed = !$blockTest['blocked'] || ($validatedId > 0 && !($passTest['can_purchase'] ?? false));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{blocked: bool, message?: string}
     */
    private function testCheckoutBlock(int $customerId): array
    {
        if (!$this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            return [
                'blocked' => true,
                'message' => 'Cliente ainda ausente em oc_customer_b2b_confirmed (gate ativo).',
            ];
        }

        $quote = $this->buildQuoteStub($customerId);
        $event = new Event(['quote' => $quote]);
        $observer = new Observer(['event' => $event]);

        try {
            $this->checkoutObserver->execute($observer);
            return ['blocked' => false];
        } catch (LocalizedException $e) {
            return [
                'blocked' => str_contains($e->getMessage(), CheckoutBlockMessage::MESSAGE)
                    || str_contains($e->getMessage(), 'análise'),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{can_purchase: bool, reason?: string}
     */
    private function testCheckoutPass(int $customerId): array
    {
        if (!$this->orderPullCustomerData->isApprovedB2bCustomer($customerId)) {
            return ['can_purchase' => false, 'reason' => 'não aprovado comercialmente'];
        }
        if (!$this->validatorChecker->isCustomerValidatedInSectra($customerId)) {
            return ['can_purchase' => false, 'reason' => 'não em oc_customer_b2b_confirmed'];
        }
        if (!$this->orderPullCustomerData->isReadyForOrderPull($customerId)) {
            return ['can_purchase' => false, 'reason' => 'dados fiscais incompletos'];
        }

        return ['can_purchase' => true];
    }

    private function buildQuoteStub(int $customerId): \Magento\Quote\Model\Quote
    {
        $quote = $this->quoteFactory->create();
        $quote->setCustomerId($customerId);
        $shipping = $quote->getShippingAddress();
        $shipping->setStreet(['Rua Teste 123']);
        $shipping->setCity('Araraquara');
        $shipping->setPostcode('14800000');
        $shipping->setRegion('São Paulo');

        return $quote;
    }

    private function describeCustomer(int $customerId): string
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            "SELECT ce.email, sync.value AS erp_sync, map.old_oc_customer_id,
                    COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id) AS sectra_chave,
                    conf.customer_id AS confirmed_chave
             FROM customer_entity ce
             LEFT JOIN customer_entity_varchar sync ON sync.entity_id=ce.entity_id
               AND sync.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='erp_customer_sync_status' AND entity_type_id=1)
             LEFT JOIN customer_entity_varchar erp_attr ON erp_attr.entity_id=ce.entity_id
               AND erp_attr.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='erp_code' AND entity_type_id=1)
               AND erp_attr.value REGEXP '^[0-9]+$'
             LEFT JOIN oc_customer_id_map map ON map.magento_customer_id=ce.entity_id
             LEFT JOIN oc_customer_b2b_confirmed conf
               ON conf.customer_id=COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id)
             WHERE ce.entity_id=?",
            [$customerId]
        );

        if (!$row) {
            return 'Cliente não encontrado';
        }

        return sprintf(
            'email=%s | erp_sync=%s | sectra_chave=%s | b2b_confirmed=%s | validated=%s',
            $row['email'],
            $row['erp_sync'] ?? 'NULL',
            $row['sectra_chave'] ?? 'NULL',
            $row['confirmed_chave'] ? 'sim' : 'não',
            $this->validatorChecker->isCustomerValidatedInSectra($customerId) ? 'sim' : 'não'
        );
    }

    private function findValidatedCustomerId(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $groupIn = B2bGroupIds::toSqlInList($this->resourceConnection);
        $id = $connection->fetchOne(
            "SELECT ce.entity_id
             FROM customer_entity ce
             INNER JOIN oc_customer_id_map map ON map.magento_customer_id=ce.entity_id
             LEFT JOIN customer_entity_varchar erp_attr ON erp_attr.entity_id=ce.entity_id
               AND erp_attr.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='erp_code' AND entity_type_id=1)
               AND erp_attr.value REGEXP '^[0-9]+$'
             INNER JOIN oc_customer_b2b_confirmed conf
               ON conf.customer_id=COALESCE(NULLIF(CAST(erp_attr.value AS UNSIGNED), 0), map.old_oc_customer_id)
             INNER JOIN customer_entity_varchar appr ON appr.entity_id=ce.entity_id
               AND appr.attribute_id=142 AND appr.value='approved'
             WHERE ce.group_id IN ($groupIn)
             ORDER BY ce.entity_id DESC LIMIT 1"
        );

        return $id ? (int) $id : 0;
    }

    /**
     * @return list<array{event_type: string, message: string, created_at: string}>
     */
    private function fetchRecentLogs(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $events = [
            ProspectEvent::CHECKOUT_BLOCKED_CUSTOMER_NOT_VALIDATED,
            ProspectEvent::ORDER_NOT_CREATED_CUSTOMER_PENDING_ERP,
            ProspectEvent::ORDER_CANCELLED_BEFORE_ERP_IMPORT,
            ProspectEvent::ORDER_RELEASED_FOR_IMPORT,
            ProspectEvent::CUSTOMER_CONFIRMED_BY_ERP_POLL,
        ];

        return $connection->fetchAll(
            'SELECT event_type, message, created_at FROM grupoawamotos_b2b_sectra_sync_log
             WHERE event_type IN (' . implode(',', array_fill(0, count($events), '?')) . ')
             ORDER BY log_id DESC LIMIT 15',
            $events
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCustomerOrders(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            'SELECT so.increment_id, so.state, so.sectra_import_status, so.entity_id
             FROM sales_order so WHERE so.customer_id=? ORDER BY so.entity_id DESC LIMIT 5',
            [$customerId]
        );

        foreach ($rows as &$row) {
            $ocId = (int) $row['entity_id'] + 200000;
            $row['in_oc_order'] = (bool) $connection->fetchOne(
                'SELECT order_id FROM oc_order WHERE order_id=?',
                [$ocId]
            );
        }

        return $rows;
    }
}
