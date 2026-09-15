<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Registration\BackfillPhoneFromAddressService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillPhoneFromAddressCommand extends Command
{
    public function __construct(
        private readonly BackfillPhoneFromAddressService $backfillService,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:registration:backfill-phone-from-address')
            ->setDescription('Preenche b2b_phone a partir do telefone do endereço do cliente')
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
        if ($fromId !== null || $toId !== null) {
            $output->writeln(sprintf(
                'Faixa entity_id: %s → %s',
                $fromId !== null ? (string) $fromId : '*',
                $toId !== null ? (string) $toId : '*'
            ));
        }

        $report = $this->backfillService->execute(
            !$dryRun && $apply,
            $limit,
            $fromId,
            $toId
        );

        $output->writeln('');
        $output->writeln('<info>Relatório backfill b2b_phone ← endereço</info>');
        $output->writeln(sprintf('Analisados: %d', $report['analyzed']));
        $output->writeln(sprintf('Atualizados: %d', $report['updated']));
        $output->writeln(sprintf('Ignorados: %d', $report['skipped']));
        $output->writeln(sprintf('Sem telefone no endereço: %d', $report['no_address_phone']));
        $output->writeln(sprintf('Falhas de persistência: %d', $report['failed']));

        $snapshot = $report['snapshot'];
        $output->writeln('');
        $output->writeln('<info>Snapshot global</info>');
        $output->writeln(sprintf('sem_b2b_phone: %d', $snapshot['sem_b2b_phone']));
        $output->writeln(sprintf('pode_copiar_telefone_do_endereco: %d', $snapshot['pode_copiar_telefone']));
        $output->writeln('Log: var/log/b2b_phone_backfill.log');

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
