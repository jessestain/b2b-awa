<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Cron\SyncAttendantFromErp;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Sql\Expression;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

/**
 * CLI command to run attendant-customer sync from ERP on demand.
 * Also refreshes customer_count in grupoawamotos_b2b_attendants after sync.
 *
 * Usage:
 *   bin/magento b2b:attendant:sync           # full sync
 *   bin/magento b2b:attendant:sync --dry-run # preview without changes
 *   bin/magento b2b:attendant:sync --stats   # show stats only (no sync)
 */
class SyncAttendantCommand extends Command
{
    private SyncAttendantFromErp $syncCron;
    private ResourceConnection $resource;
    private State $state;

    public function __construct(
        SyncAttendantFromErp $syncCron,
        ResourceConnection $resource,
        State $state
    ) {
        $this->syncCron = $syncCron;
        $this->resource = $resource;
        $this->state    = $state;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:attendant:sync')
            ->setDescription('Sync attendant-customer assignments from ERP VENDPREF + refresh customer_count')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only, no changes')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show current stats without syncing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // area already set
        }

        if ($input->getOption('stats')) {
            $this->showStats($output);
            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('<comment>DRY RUN — mostrando estado atual sem alterações</comment>');
            $this->showStats($output);
            return Command::SUCCESS;
        }

        // 1. Run the ERP sync
        $output->writeln('<info>Executando sync ERP VENDPREF → atendentes...</info>');
        $this->syncCron->execute();
        $output->writeln('<info>Sync ERP concluído.</info>');

        // 2. Refresh customer_count in attendants table
        $output->writeln('<info>Atualizando customer_count...</info>');
        $updated = $this->refreshCustomerCounts();
        $output->writeln(sprintf('<info>customer_count atualizado para %d atendentes.</info>', $updated));

        // 3. Show final stats
        $this->showStats($output);

        return Command::SUCCESS;
    }

    /**
     * Refresh customer_count column from actual assignments.
     */
    private function refreshCustomerCounts(): int
    {
        $connection = $this->resource->getConnection();
        $attTable = $this->resource->getTableName('grupoawamotos_b2b_attendants');
        $caTable  = $this->resource->getTableName('grupoawamotos_b2b_customer_attendant');

        $sql = "UPDATE {$attTable} a SET a.customer_count = (
            SELECT COUNT(*) FROM {$caTable} ca WHERE ca.attendant_id = a.attendant_id
        )";
        return (int) $connection->query($sql)->rowCount();
    }

    private function showStats(OutputInterface $output): void
    {
        $connection = $this->resource->getConnection();
        $attTable = $this->resource->getTableName('grupoawamotos_b2b_attendants');
        $caTable  = $this->resource->getTableName('grupoawamotos_b2b_customer_attendant');

        $attendants = $connection->fetchAll(
            $connection->select()
                ->from(['a' => $attTable], [
                    'attendant_id',
                    'name',
                    'erp_seller_code',
                    'customer_count',
                    'max_customers',
                    'is_active',
                ])
                ->joinLeft(
                    ['u' => $this->resource->getTableName('admin_user')],
                    'u.user_id = a.admin_user_id',
                    ['username']
                )
                ->where('a.admin_user_id IS NOT NULL')
                ->order('a.attendant_id')
        );

        $table = new Table($output);
        $table->setHeaders(['ID', 'Nome', 'Login', 'ERP Code', 'Clientes DB', 'Clientes Real', 'Max', 'Ativo']);

        foreach ($attendants as $att) {
            $realCount = (int) $connection->fetchOne(
                $connection->select()
                    ->from($caTable, ['cnt' => new Expression('COUNT(*)')])
                    ->where('attendant_id = ?', $att['attendant_id'])
            );

            $table->addRow([
                $att['attendant_id'],
                mb_substr((string) $att['name'], 0, 20),
                $att['username'] ?? '-',
                $att['erp_seller_code'] ?? 'NULL',
                $att['customer_count'],
                $realCount,
                $att['max_customers'],
                $att['is_active'] ? 'Sim' : 'Não',
            ]);
        }

        $table->render();

        $totalAssigned = (int) $connection->fetchOne(
            $connection->select()->from($caTable, ['cnt' => new Expression('COUNT(*)')])
        );
        $totalCustomers = (int) $connection->fetchOne(
            $connection->select()->from(
                $this->resource->getTableName('customer_entity_varchar'),
                ['cnt' => new Expression('COUNT(*)')]
            )->where('attribute_id = 198')->where('value IS NOT NULL')->where("value != ''")
        );

        $output->writeln(sprintf(
            "\n<info>Resumo: %d clientes com erp_code, %d atribuídos (%d sem atendente)</info>",
            $totalCustomers,
            $totalAssigned,
            $totalCustomers - $totalAssigned
        ));
    }
}
