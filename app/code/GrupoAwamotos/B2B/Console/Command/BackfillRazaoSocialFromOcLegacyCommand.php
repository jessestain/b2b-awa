<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Registration\BackfillRazaoSocialFromOcLegacyService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillRazaoSocialFromOcLegacyCommand extends Command
{
    public function __construct(
        private readonly BackfillRazaoSocialFromOcLegacyService $backfillService,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:registration:backfill-razao-social-from-oc-legacy')
            ->setDescription('Preenche b2b_razao_social a partir de oc_customer.custom_field (legado OpenCart)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem gravar (padrão se --apply omitido)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Aplica alterações no banco')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limita clientes analisados', '0')
            ->addOption('from-id', null, InputOption::VALUE_OPTIONAL, 'entity_id mínimo (inclusivo)')
            ->addOption('to-id', null, InputOption::VALUE_OPTIONAL, 'entity_id máximo (inclusivo)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run') || !$apply;
        $limit = max(0, (int) $input->getOption('limit'));
        $limit = $limit > 0 ? $limit : null;
        $fromId = $input->getOption('from-id') !== null ? (int) $input->getOption('from-id') : null;
        $toId = $input->getOption('to-id') !== null ? (int) $input->getOption('to-id') : null;

        if ($apply && $input->getOption('dry-run')) {
            $output->writeln('<error>Use --dry-run OU --apply, não ambos.</error>');

            return Command::FAILURE;
        }

        if ($apply && $limit === null && ($fromId === null && $toId === null)) {
            $output->writeln('<error>Apply exige --limit ou faixa --from-id/--to-id.</error>');

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
            $output->writeln('<comment>Use --apply --limit=N para persistir alterações no banco.</comment>');
        }

        $report = $this->backfillService->execute(
            !$dryRun && $apply,
            $limit,
            $fromId,
            $toId
        );

        $output->writeln('');
        $output->writeln('<info>Relatório backfill b2b_razao_social ← oc_customer.custom_field</info>');
        $output->writeln(sprintf('Analisados: %d', $report['analyzed']));
        $output->writeln(sprintf('Atualizados: %d', $report['updated']));
        $output->writeln(sprintf('Ignorados: %d', $report['skipped']));
        $output->writeln(sprintf('Sem mapeamento OC / custom_field vazio: %d', $report['no_oc_mapping']));
        $output->writeln(sprintf('OC sem razão válida: %d', $report['no_valid_razao_in_oc']));
        $output->writeln(sprintf('CNPJ divergente OC vs Magento: %d', $report['cnpj_mismatch']));
        $output->writeln(sprintf('Falhas de persistência: %d', $report['failed']));

        if ($report['backup_path'] !== null) {
            $output->writeln(sprintf('Backup lógico: %s', $report['backup_path']));
        }

        $snapshot = $report['snapshot'];
        $output->writeln('');
        $output->writeln('<info>Snapshot global</info>');
        $output->writeln(sprintf('sem_b2b_razao_social: %d', $snapshot['sem_b2b_razao_social']));
        $output->writeln(sprintf('pode_backfill_oc_legacy: %d', $snapshot['pode_backfill_oc_legacy']));
        $output->writeln('Log: var/log/b2b_razao_social_backfill.log');

        foreach (array_slice($report['details'], 0, 20) as $line) {
            $output->writeln('  - ' . $line);
        }
        if (count($report['details']) > 20) {
            $output->writeln(sprintf('  ... +%d registros no log', count($report['details']) - 20));
        }

        foreach ($report['errors'] as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        if ($apply && $report['failed'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
