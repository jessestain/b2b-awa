<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Registration\RegistrationBackfillService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillRegistrationAttributionCommand extends Command
{
    public function __construct(
        private readonly RegistrationBackfillService $backfillService,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:registration:backfill-attribution')
            ->setDescription('Backfill retroativo de CNPJ, origem UTM/CNAME, status ERP e vendedor')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem gravar (padrão se --apply omitido)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Aplica alterações')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Sobrescreve campos já preenchidos (use com cautela)')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limita clientes pendentes analisados', '0')
            ->addOption('from-id', null, InputOption::VALUE_OPTIONAL, 'entity_id mínimo (inclusivo)')
            ->addOption('to-id', null, InputOption::VALUE_OPTIONAL, 'entity_id máximo (inclusivo)')
            ->addOption('customer-id', null, InputOption::VALUE_OPTIONAL, 'Processa apenas um customer_id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run') || !$apply;
        $force = (bool) $input->getOption('force');
        $limit = max(0, (int) $input->getOption('limit'));
        $limit = $limit > 0 ? $limit : null;
        $fromId = $input->getOption('from-id') !== null ? (int) $input->getOption('from-id') : null;
        $toId = $input->getOption('to-id') !== null ? (int) $input->getOption('to-id') : null;
        $customerId = $input->getOption('customer-id') !== null ? (int) $input->getOption('customer-id') : null;

        if ($apply && $input->getOption('dry-run')) {
            $output->writeln('<error>Use --dry-run OU --apply, não ambos.</error>');
            return Command::FAILURE;
        }

        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }

        $output->writeln($dryRun ? '<comment>Modo DRY-RUN (nenhuma gravação)</comment>' : '<info>Modo APPLY (gravação real)</info>');
        if (!$apply && !$input->getOption('dry-run')) {
            $output->writeln('<comment>Use --apply para persistir alterações no banco.</comment>');
        }
        if ($force) {
            $output->writeln('<error>Modo FORCE ativo — campos preenchidos podem ser sobrescritos.</error>');
        } else {
            $output->writeln('<info>Modo seguro — apenas campos vazios serão preenchidos.</info>');
        }
        if ($fromId !== null || $toId !== null) {
            $output->writeln(sprintf(
                'Faixa entity_id: %s → %s',
                $fromId !== null ? (string) $fromId : '*',
                $toId !== null ? (string) $toId : '*'
            ));
        }

        $report = $this->backfillService->execute(
            !$dryRun && $apply,
            $force,
            $limit,
            $customerId,
            $fromId,
            $toId
        );

        $output->writeln('');
        $output->writeln('<info>Relatório backfill atribuição B2B</info>');
        $output->writeln(sprintf('Analisados (pendentes neste lote): %d', $report['analyzed']));
        $output->writeln(sprintf('Atualizados: %d', $report['updated']));
        $output->writeln(sprintf('Ignorados: %d', $report['skipped']));
        $output->writeln(sprintf('Falhas de persistência: %d', $report['failed'] ?? 0));
        $output->writeln(sprintf('Sem CNPJ: %d', $report['no_cnpj']));
        $output->writeln(sprintf('Sem origem (após lote): %d', $report['no_origin']));
        $output->writeln(sprintf('Sem vendedor (no lote): %d', $report['no_attendant']));
        $output->writeln(sprintf('Sem telefone (no lote): %d', $report['no_phone']));
        $output->writeln(sprintf('Sem razão social (no lote): %d', $report['no_razao_social']));
        $output->writeln(sprintf(
            'Validados ERP com lacuna fiscal (no lote): %d',
            $report['at_risk_validated']
        ));

        if ($report['fields_changed'] !== []) {
            $output->writeln('Campos alterados neste lote:');
            foreach ($report['fields_changed'] as $field => $count) {
                $output->writeln(sprintf('  - %s: %d', $field, $count));
            }
        }

        $snapshot = $report['snapshot'];
        $output->writeln('');
        $output->writeln('<info>Snapshot global (todos os clientes com CNPJ)</info>');
        $output->writeln(sprintf('Pendentes de backfill: %d', $snapshot['pending_backfill']));
        $output->writeln(sprintf('Com legacy_unknown: %d', $snapshot['legacy_unknown_hosts']));
        $output->writeln(sprintf('Sem vendedor: %d', $snapshot['no_attendant']));
        $output->writeln(sprintf('Sem telefone: %d', $snapshot['no_phone']));
        $output->writeln(sprintf('Sem razão social: %d', $snapshot['no_razao_social']));
        $output->writeln(sprintf('erp_customer_sync_status NULL/vazio: %d', $snapshot['erp_status_null']));
        $output->writeln('Log: var/log/b2b_registration_backfill.log');

        foreach (array_slice($report['details'], 0, 20) as $line) {
            $output->writeln('  - ' . $line);
        }
        if (count($report['details']) > 20) {
            $output->writeln(sprintf('  ... +%d registros no log', count($report['details']) - 20));
        }

        foreach ($report['errors'] as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        if ($apply && ($report['failed'] ?? 0) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
