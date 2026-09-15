<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class AttendantManageCommand extends Command
{
    private AttendantManager $attendantManager;
    private ResourceConnection $resource;
    private State $state;

    public function __construct(
        AttendantManager $attendantManager,
        ResourceConnection $resource,
        State $state,
        ?string $name = null
    ) {
        $this->attendantManager = $attendantManager;
        $this->resource         = $resource;
        $this->state            = $state;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:attendant:manage')
            ->setDescription('Gerencia atendentes B2B')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Acao: list | recalculate | assign-unassigned | redistribute | stats | calibrate'
            )
            ->addOption('department', 'd', InputOption::VALUE_OPTIONAL, 'Filtrar por departamento')
            ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Batch para assign-unassigned', '500')
            ->addOption(
                'buffer',
                null,
                InputOption::VALUE_OPTIONAL,
                'Margem acima do volume real para calibrate (ex: 0.30 = 30%)',
                '0.30'
            )
            ->addOption(
                'min-max',
                null,
                InputOption::VALUE_OPTIONAL,
                'Minimo de max_customers para atendentes novos sem clientes em calibrate',
                '50'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Exibe o que seria feito pelo calibrate sem salvar'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area already set
        }

        $action     = $input->getArgument('action');
        $department = $input->getOption('department');

        switch ($action) {
            case 'list':
                return $this->listAttendants($output, $department);
            case 'recalculate':
                return $this->recalculateCounts($output);
            case 'assign-unassigned':
                return $this->assignUnassigned($output, $department, (int) $input->getOption('batch'));
            case 'redistribute':
                return $this->redistribute($output, $department);
            case 'stats':
                return $this->showStats($output, $department);
            case 'calibrate':
                return $this->calibrateMaxCustomers(
                    $output,
                    (float) $input->getOption('buffer'),
                    (int) $input->getOption('min-max'),
                    (bool) $input->getOption('dry-run')
                );
            default:
                $output->writeln('<error>Acao invalida: ' . $action . '</error>');
                $output->writeln('Acoes validas: list, recalculate, assign-unassigned, redistribute, stats, calibrate');
                return Command::FAILURE;
        }
    }

    // -----------------------------------------------------------------------
    // calibrate
    // -----------------------------------------------------------------------

    /**
     * Calibrate max_customers for every active attendant.
     *
     * Strategy:
     *   - ERP-sourced attendants (erp_seller_code IS NOT NULL):
     *       new_max = MAX( CEIL(customer_count * (1 + buffer)), min_max )
     *       This gives them headroom above their real ERP volume while making
     *       the %usage metric meaningful (<= ~77% at buffer=0.30).
     *   - Internal attendants (erp_seller_code IS NULL):
     *       Keep their existing max_customers — they were set intentionally.
     */
    private function calibrateMaxCustomers(
        OutputInterface $output,
        float $buffer,
        int $minMax,
        bool $dryRun
    ): int {
        if ($dryRun) {
            $output->writeln('<comment>[DRY-RUN] Nenhuma alteracao sera salva.</comment>');
        }

        $output->writeln(sprintf(
            '<info>Calibrando max_customers (buffer=%.0f%%, min=%d)...</info>',
            $buffer * 100,
            $minMax
        ));

        // First make sure counts are fresh
        $this->attendantManager->recalculateAllCounts();

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('grupoawamotos_b2b_attendants');

        $attendants = $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('is_active = ?', 1)
                ->order('customer_count DESC')
        );

        $tbl = new Table($output);
        $tbl->setHeaders(['ID', 'Nome', 'Clientes', 'Max Atual', 'Novo Max', 'Tipo', 'Acao']);

        $updated = 0;

        foreach ($attendants as $att) {
            $id       = (int) $att['attendant_id'];
            $count    = (int) $att['customer_count'];
            $maxNow   = (int) $att['max_customers'];
            $hasErp   = !empty($att['erp_seller_code']);

            if (!$hasErp) {
                // Internal team — never touch their limit
                $newMax = $maxNow;
                $action = 'MANTER';
                $tipo   = 'interno';
            } else {
                // ERP vendor — calibrate to real volume + buffer
                $newMax = max((int) ceil($count * (1 + $buffer)), $minMax);
                $action = ($newMax !== $maxNow) ? 'ATUALIZAR' : 'OK';
                $tipo   = 'ERP';
            }

            $tbl->addRow([
                $id,
                mb_strimwidth((string) $att['name'], 0, 35, '...'),
                $count,
                $maxNow,
                $newMax,
                $tipo,
                $action,
            ]);

            if ($action === 'ATUALIZAR' && !$dryRun) {
                $connection->update(
                    $table,
                    ['max_customers' => $newMax, 'updated_at' => date('Y-m-d H:i:s')],
                    ['attendant_id = ?' => $id]
                );
                $updated++;
            } elseif ($action === 'ATUALIZAR') {
                $updated++;
            }
        }

        $tbl->render();

        $output->writeln(sprintf(
            '<info>%d atendente(s) %s.</info>',
            $updated,
            $dryRun ? 'seriam atualizados (dry-run)' : 'atualizados'
        ));

        if (!$dryRun) {
            $output->writeln('<info>Execute "stats" para ver o resultado final.</info>');
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Existing actions (unchanged)
    // -----------------------------------------------------------------------

    private function listAttendants(OutputInterface $output, ?string $department): int
    {
        $attendants = $this->attendantManager->getActiveAttendants($department);

        if (empty($attendants)) {
            $output->writeln('<comment>Nenhum atendente ativo encontrado.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Nome', 'Email', 'Dept', 'Clientes', 'Max', 'ERP Code', 'Chatwoot']);

        foreach ($attendants as $att) {
            $count  = (int) $att['customer_count'];
            $max    = (int) $att['max_customers'];
            $status = $count >= $max ? ' <fg=red>FULL</>' : '';

            $table->addRow([
                $att['attendant_id'],
                $att['name'],
                $att['email'] ?? '-',
                $att['department'] ?? 'geral',
                $count . $status,
                $max,
                $att['erp_seller_code'] ?? '-',
                $att['chatwoot_agent_id'] ?? '-',
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<info>Total: %d atendentes</info>', count($attendants)));

        return Command::SUCCESS;
    }

    private function recalculateCounts(OutputInterface $output): int
    {
        $output->writeln('<info>Recalculando contadores de clientes...</info>');

        $result = $this->attendantManager->recalculateAllCounts();

        $table = new Table($output);
        $table->setHeaders(['ID', 'Nome', 'Anterior', 'Real', 'Alterado']);

        foreach ($result as $row) {
            $changed = $row['old_count'] !== $row['real_count'] ? '<fg=yellow>SIM</>' : 'nao';
            $table->addRow([
                $row['attendant_id'],
                $row['name'],
                $row['old_count'],
                $row['real_count'],
                $changed,
            ]);
        }

        $table->render();
        $output->writeln('<info>Contadores atualizados com sucesso.</info>');

        return Command::SUCCESS;
    }

    private function assignUnassigned(OutputInterface $output, ?string $department, int $batchLimit): int
    {
        $output->writeln(sprintf(
            '<info>Atribuindo clientes sem atendente (batch=%d)...</info>',
            $batchLimit
        ));

        $totalAssigned = 0;
        $iterations    = 0;

        do {
            $result     = $this->attendantManager->assignUnassignedCustomers($department, $batchLimit);
            $assigned   = $result['assigned'] ?? 0;
            $remaining  = $result['remaining'] ?? 0;
            $totalAssigned += $assigned;
            $iterations++;

            if ($assigned > 0) {
                $output->writeln(sprintf(
                    '  Batch %d: %d clientes atribuidos, %d restantes',
                    $iterations,
                    $assigned,
                    $remaining
                ));
            }
        } while ($assigned > 0 && $remaining > 0 && $iterations < 100);

        $output->writeln(sprintf(
            '<info>Total atribuido: %d clientes em %d batches.</info>',
            $totalAssigned,
            $iterations
        ));

        return Command::SUCCESS;
    }

    private function redistribute(OutputInterface $output, ?string $department): int
    {
        $output->writeln('<info>Redistribuindo clientes entre atendentes...</info>');

        $result = $this->attendantManager->redistributeCustomers($department);

        $output->writeln(sprintf(
            '<info>Redistribuicao concluida: %d clientes entre %d atendentes (alvo ~%d/atendente)</info>',
            $result['redistributed'] ?? 0,
            $result['attendants'] ?? 0,
            $result['target_per_attendant'] ?? 0
        ));

        return Command::SUCCESS;
    }

    private function showStats(OutputInterface $output, ?string $department): int
    {
        $summary = $this->attendantManager->getAttendantsSummary($department);

        if (empty($summary)) {
            $output->writeln('<comment>Nenhum atendente encontrado.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Nome', 'Clientes', 'Max', '%Uso', 'Novos 30d', 'Ult. Atribuicao']);

        foreach ($summary as $att) {
            $count      = (int) ($att['customer_count'] ?? 0);
            $max        = (int) ($att['max_customers'] ?? 0);
            $usage      = $max > 0 ? round(($count / $max) * 100) : 0;
            $usageColor = $usage >= 90 ? 'red' : ($usage >= 70 ? 'yellow' : 'green');

            $table->addRow([
                $att['attendant_id'],
                $att['name'],
                $count,
                $max,
                sprintf('<fg=%s>%d%%</>', $usageColor, $usage),
                $att['new_customers_period'] ?? 0,
                $att['last_assignment'] ?? 'nunca',
            ]);
        }

        $table->render();

        $totalCustomers = array_sum(array_column($summary, 'customer_count'));
        $output->writeln(sprintf('<info>Total clientes atribuidos: %d</info>', $totalCustomers));

        return Command::SUCCESS;
    }
}
