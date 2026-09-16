<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Console\Command;

use GrupoAwamotos\CatalogFix\Model\OfertasCategorySync;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncOfertasCategoryCommand extends Command
{
    public function __construct(
        private readonly OfertasCategorySync $ofertasCategorySync,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('grupoawamotos:catalog:sync-ofertas')
            ->setDescription('Sincroniza produtos com preço especial na categoria Ofertas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException) {
            // Area code already set.
        }

        $output->writeln('<info>Sincronizando categoria Ofertas (ID '
            . OfertasCategorySync::OFERTAS_CATEGORY_ID . ')...</info>');

        $result = $this->ofertasCategorySync->sync();

        $output->writeln(sprintf('  Adicionados:  <info>%d</info>', $result['added']));
        $output->writeln(sprintf('  Removidos:    <info>%d</info>', $result['removed']));
        $output->writeln(sprintf('  Total ativos: <info>%d</info>', $result['total']));
        $output->writeln('<info>Concluído.</info>');

        return Command::SUCCESS;
    }
}
