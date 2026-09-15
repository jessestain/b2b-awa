<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\ApprovedCustomerErpSync;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reprocess ERP sync for specific approved B2B customers (no re-approval, no bulk legacy).
 */
class ReprocessApprovedErpSyncCommand extends Command
{
    private const OPTION_CUSTOMER_ID = 'customer-id';
    private const OPTION_DRY_RUN = 'dry-run';

    /** @var list<int> Default targets from green manual approval backlog. */
    private const DEFAULT_CUSTOMER_IDS = [8905, 8926];

    public function __construct(
        private readonly ApprovedCustomerErpSync $approvedCustomerErpSync,
        private readonly SyncLogResource $syncLogResource,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:erp:reprocess-approved-sync')
            ->setDescription('Reprocessa status ERP pós-aprovação B2B (pull de pedido; sem re-aprovar)')
            ->addOption(
                self::OPTION_CUSTOMER_ID,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'ID do cliente aprovado (padrão: 8905,8926)',
                self::DEFAULT_CUSTOMER_IDS
            )
            ->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Somente simular');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeAreaCode();

        /** @var list<string|int> $rawIds */
        $rawIds = $input->getOption(self::OPTION_CUSTOMER_ID);
        $customerIds = array_values(array_unique(array_map('intval', $rawIds)));
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);

        if ($customerIds === []) {
            $output->writeln('<error>Informe ao menos um --customer-id.</error>');
            return Command::FAILURE;
        }

        if (count($customerIds) > 10) {
            $output->writeln('<error>Máximo de 10 clientes por execução.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Reprocessamento ERP — clientes aprovados</info>');
        $output->writeln(sprintf('IDs: %s', implode(', ', $customerIds)));

        $failures = 0;

        foreach ($customerIds as $customerId) {
            $output->writeln(sprintf('<comment>--- Customer #%d ---</comment>', $customerId));

            if (!$this->approvedCustomerErpSync->isApprovedCustomer($customerId)) {
                $output->writeln('<error>Cliente não está com status approved — ignorado.</error>');
                $failures++;
                continue;
            }

            $beforeSyncAt = $this->getLastSyncAt($customerId);

            if ($dryRun) {
                $output->writeln(sprintf(
                    '[DRY-RUN] Reprocessaria sync ERP (last_sync_at atual: %s)',
                    $beforeSyncAt ?? 'NULL'
                ));
                continue;
            }

            $result = $this->approvedCustomerErpSync->syncApprovedCustomer($customerId);
            $afterSyncAt = $this->getLastSyncAt($customerId);

            $output->writeln(sprintf('CNPJ: %s (fonte: %s)', $result['cnpj'] ?? '—', $result['cnpj_source'] ?? '—'));
            $output->writeln(sprintf('Ação: %s', $result['action']));
            $output->writeln(sprintf(
                'erp_customer_sync_status: %s',
                $result['erp_customer_sync_status'] ?? '—'
            ));
            $output->writeln(sprintf('ERP code: %s', $result['erp_code'] !== null ? (string) $result['erp_code'] : '—'));
            $output->writeln(sprintf('last_sync_at: %s → %s', $beforeSyncAt ?? 'NULL', $afterSyncAt ?? 'NULL'));
            $output->writeln(sprintf('Mensagem: %s', $result['message']));

            if ($result['success']) {
                $output->writeln('<info>OK</info>');
            } else {
                $output->writeln('<error>FALHOU</error>');
                $failures++;
            }
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY-RUN] Nenhuma alteração aplicada.</comment>');
            return Command::SUCCESS;
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function getLastSyncAt(int $customerId): ?string
    {
        $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
        if ($erpCode === null) {
            return null;
        }

        $connection = $this->syncLogResource->getConnection();
        $syncAt = $connection->fetchOne(
            $connection->select()
                ->from('grupoawamotos_erp_entity_map', 'last_sync_at')
                ->where('entity_type = ?', 'customer')
                ->where('magento_entity_id = ?', $customerId)
                ->order('last_sync_at DESC')
                ->limit(1)
        );

        return $syncAt !== false ? (string) $syncAt : null;
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
