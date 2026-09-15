<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Sectra\OrderImportGate;
use GrupoAwamotos\B2B\Model\Sectra\ProspectPipeline;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncSectraProspectCommand extends Command
{
    private const NAME = 'b2b:sectra:sync-prospect';

    public function __construct(
        private readonly ProspectPipeline $prospectPipeline,
        private readonly OrderImportGate $orderImportGate,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Sincroniza prospect B2B com Sectra e valida status no ERP')
            ->addOption('customer-id', null, InputOption::VALUE_REQUIRED, 'Processar um cliente específico')
            ->addOption('poll', null, InputOption::VALUE_NONE, 'Apenas verificar validações pendentes no Sectra');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (LocalizedException) {
            // area already set
        }

        $customerId = $input->getOption('customer-id');
        if ($customerId !== null) {
            $result = $this->prospectPipeline->processApprovedCustomer((int) $customerId);
            $released = $this->orderImportGate->backfillOrderImportStatus();
            $imported = $this->orderImportGate->syncImportedOrderFlags();
            $output->writeln(sprintf(
                'Cliente #%d: %s (status: %s) | Pedidos liberados: %d | Pedidos importados marcados: %d',
                $result['customer_id'],
                $result['message'],
                $result['erp_customer_sync_status'],
                $released,
                $imported
            ));
            return $result['success'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($input->getOption('poll')) {
            $validation = $this->prospectPipeline->pollPendingValidations();
            $released = $this->orderImportGate->backfillOrderImportStatus();
            $imported = $this->orderImportGate->syncImportedOrderFlags();
            $output->writeln(sprintf(
                'Validados: %d | Pendentes: %d | Pedidos liberados: %d | Pedidos importados marcados: %d',
                $validation['validated'],
                $validation['still_pending'],
                $released,
                $imported
            ));
            return Command::SUCCESS;
        }

        $output->writeln('Use --customer-id=ID ou --poll');
        return Command::INVALID;
    }
}
