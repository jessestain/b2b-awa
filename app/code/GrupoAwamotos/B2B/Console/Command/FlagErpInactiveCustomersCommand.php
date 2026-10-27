<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Customer\CustomerErpStatusFlagService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Marca (b2b_admin_notes) clientes aprovados no site que o Sectra diz inativos ou
 * nao encontrados. Nunca bloqueia, nunca despromove — ver CustomerErpStatusGuard.
 */
class FlagErpInactiveCustomersCommand extends Command
{
    public function __construct(private readonly CustomerErpStatusFlagService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:customer:flag-erp-inactive')
            ->setDescription('Marca clientes aprovados e inativos/ausentes no Sectra (nunca bloqueia, so marca)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apenas simula, nao grava nada')
            ->addOption('max-percent', null, InputOption::VALUE_REQUIRED, 'Sobrescreve o limite de seguranca (%) so nesta execucao');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $maxPercent = $input->getOption('max-percent');
        $maxRatio = ($maxPercent !== null && (float) $maxPercent > 0 && (float) $maxPercent <= 100)
            ? (float) $maxPercent / 100
            : 0.15;

        $output->writeln('<info>Marcacao de clientes aprovados e inativos/ausentes no Sectra</info>');
        if ($dryRun) {
            $output->writeln('<comment>Modo DRY-RUN: nenhuma alteracao sera feita</comment>');
        }
        $output->writeln('');

        $r = $this->service->run($dryRun, $maxRatio);

        if ($r['skipped']) {
            $output->writeln('<error>Ignorado: ' . $r['skip_reason'] . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Clientes aprovados com erp_code:     <comment>%d</comment>', $r['total_checked']));
        $output->writeln(sprintf('Ja marcados (nao repete):           <comment>%d</comment>', $r['already_flagged']));
        $output->writeln(sprintf('Excluidos (teste/QA):               <comment>%d</comment>', $r['excluded_test_qa']));
        $output->writeln(sprintf(
            'Candidatos novos:                   <comment>%d</comment> (limite %.0f%% do checado)',
            $r['candidates'],
            $r['max_ratio'] * 100
        ));
        foreach ($r['by_reason'] as $reason => $count) {
            $output->writeln("  - {$reason}: {$count}");
        }

        if (!empty($r['skip_reason'])) {
            $output->writeln('<error>' . $r['skip_reason'] . ($dryRun
                ? ': em execucao real nada seria gravado.'
                : ': NADA foi gravado.') . '</error>');
            return $dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        if ($r['sample'] !== []) {
            $output->writeln('');
            $output->writeln('<comment>Amostra (ate 30):</comment>');
            foreach ($r['sample'] as $s) {
                $output->writeln(sprintf('  #%d %s erp=%s motivo=%s', $s['entity_id'], $s['email'], $s['erp_code'], $s['reason']));
            }
        }

        $output->writeln('');
        if ($dryRun) {
            $output->writeln(sprintf('<comment>DRY-RUN: %d cliente(s) seriam marcados.</comment>', $r['candidates']));
        } else {
            $output->writeln(sprintf('Marcados: <info>%d</info>', $r['flagged']));
        }

        return Command::SUCCESS;
    }
}
