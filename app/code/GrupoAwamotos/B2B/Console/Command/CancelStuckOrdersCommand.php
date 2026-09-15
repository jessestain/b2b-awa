<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Sectra\StuckOrderCleanup;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CancelStuckOrdersCommand extends Command
{
    private const NAME = 'b2b:sectra:cancel-stuck-orders';

    public function __construct(
        private readonly StuckOrderCleanup $stuckOrderCleanup,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Cancela pedidos B2B presos antes da validação ERP')
            ->addOption('increment-id', null, InputOption::VALUE_REQUIRED, 'Cancelar pedido específico')
            ->addOption('all-unvalidated', null, InputOption::VALUE_NONE, 'Cancelar todos os pedidos de clientes não validados')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simular cancelamentos sem executar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (LocalizedException) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }

        $incrementId = $input->getOption('increment-id');
        if ($incrementId !== null) {
            $ok = $this->stuckOrderCleanup->cancelByIncrementId((string) $incrementId);
            $output->writeln($ok
                ? sprintf('Pedido %s cancelado e removido da fila ERP.', $incrementId)
                : sprintf('Falha ao cancelar pedido %s.', $incrementId));
            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if ($input->getOption('all-unvalidated')) {
            $dryRun = (bool) $input->getOption('dry-run');
            $result = $this->stuckOrderCleanup->cancelOrdersForUnvalidatedCustomers($dryRun);
            $output->writeln(sprintf(
                '%s: %d candidato(s), %d cancelado(s), %d ignorado(s)',
                $dryRun ? 'Dry-run' : 'Execução',
                count($result['candidates']),
                $result['cancelled'],
                count($result['skipped'])
            ));
            foreach ($result['candidates'] as $candidate) {
                $output->writeln(sprintf(
                    '  - #%s (customer %d) — %s',
                    $candidate['increment_id'],
                    $candidate['customer_id'],
                    $candidate['reason']
                ));
            }
            return Command::SUCCESS;
        }

        $output->writeln('Use --increment-id=000000035 ou --all-unvalidated');
        return Command::INVALID;
    }
}
