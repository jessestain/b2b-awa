<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\CommercialPanel\Model\Admin\CommercialRoleAssignment;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AssignCommercialSellerRolesCommand extends Command
{
    public function __construct(
        private readonly CommercialRoleAssignment $roleAssignment,
        private readonly TypeListInterface $cacheTypeList,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:commercial:assign-seller-roles')
            ->setDescription('Vincula vendedoras B2B (atendentes com login admin) ao papel AWA Comercial Vendedora')
            ->addOption(
                'supervisor',
                's',
                InputOption::VALUE_OPTIONAL,
                'Username admin da supervisora (papel AWA Comercial Supervisora)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // Area already set
        }

        $report = $this->roleAssignment->assignSellerRoleToActiveAttendants();
        if ($report['missing_role']) {
            $output->writeln('<error>Papel "' . CommercialRoleAssignment::ROLE_SELLER . '" nao encontrado. Execute setup:upgrade.</error>');

            return Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(['Login', 'Vendedora', 'Acao']);
        foreach ($report['details'] as $row) {
            $table->addRow([
                $row['username'] ?? '-',
                $row['name'] ?? '-',
                $row['action'] ?? '-',
            ]);
        }
        $table->render();

        $output->writeln(sprintf(
            '<info>Concluido: %d atribuido(s), %d ignorado(s).</info>',
            $report['assigned'],
            $report['skipped']
        ));

        $supervisor = (string) ($input->getOption('supervisor') ?? '');
        if ($supervisor !== '') {
            $supervisorReport = $this->roleAssignment->assignSupervisorRoleByUsername($supervisor);
            if ($supervisorReport['missing_role']) {
                $output->writeln('<error>Papel supervisora nao encontrado.</error>');

                return Command::FAILURE;
            }
            if ($supervisorReport['assigned'] === 1) {
                $output->writeln('<info>Supervisora atribuida: ' . $supervisor . '</info>');
            } else {
                $output->writeln('<comment>Supervisora nao atribuida (usuario inexistente ou sem role U).</comment>');
            }
        }

        $this->cacheTypeList->cleanType('config');

        return Command::SUCCESS;
    }
}
