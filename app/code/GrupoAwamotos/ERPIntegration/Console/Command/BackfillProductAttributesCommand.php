<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ProductAttributeBackfill;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillProductAttributesCommand extends Command
{
    private ProductAttributeBackfill $productAttributeBackfill;
    private Helper $helper;
    private State $appState;

    public function __construct(
        ProductAttributeBackfill $productAttributeBackfill,
        Helper $helper,
        State $appState
    ) {
        parent::__construct();
        $this->productAttributeBackfill = $productAttributeBackfill;
        $this->helper = $helper;
        $this->appState = $appState;
    }

    protected function configure(): void
    {
        $this->setName('erp:catalog:backfill-attributes')
            ->setDescription('Preenche atributos ERP de produto ja existentes no catalogo')
            ->addOption('sku', 's', InputOption::VALUE_REQUIRED, 'Processa apenas um SKU especifico')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limita a quantidade de SKUs processados', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeAreaCode();

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>Integracao ERP esta desabilitada.</error>');
            return Command::FAILURE;
        }

        $sku = $input->getOption('sku');
        $limit = (int) $input->getOption('limit');

        $output->writeln('<info>Backfill de atributos ERP de produto</info>');
        if ($sku) {
            $output->writeln(sprintf('SKU alvo: <comment>%s</comment>', $sku));
        } elseif ($limit > 0) {
            $output->writeln(sprintf('Limite: <comment>%d</comment>', $limit));
        }

        $result = $this->productAttributeBackfill->backfill($sku ?: null, $limit);

        $output->writeln('');
        $output->writeln(sprintf('  Total ERP:   <info>%d</info>', $result['total']));
        $output->writeln(sprintf('  Atualizados: <info>%d</info>', $result['updated']));
        $output->writeln(sprintf('  Sem mudanca: <comment>%d</comment>', $result['unchanged']));
        $output->writeln(sprintf('  Nao encontrados: <comment>%d</comment>', $result['not_found']));
        $output->writeln(sprintf('  Ignorados:   <comment>%d</comment>', $result['skipped']));
        $output->writeln(sprintf(
            '  Erros:       %s',
            $result['errors'] > 0 ? '<error>' . $result['errors'] . '</error>' : '0'
        ));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function initializeAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
            return;
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (LocalizedException) {
            // Area code already set — safe to continue
        }

        $this->appState->setAreaCode(Area::AREA_ADMINHTML);
    }
}
