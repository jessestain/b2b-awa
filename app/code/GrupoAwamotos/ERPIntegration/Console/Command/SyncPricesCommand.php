<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Model\PriceSync;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class SyncPricesCommand extends Command
{
    private PriceSync $priceSync;
    private CustomerPriceProvider $customerPriceProvider;
    private Helper $helper;
    private State $state;

    public function __construct(
        PriceSync $priceSync,
        CustomerPriceProvider $customerPriceProvider,
        Helper $helper,
        State $state
    ) {
        parent::__construct();
        $this->priceSync = $priceSync;
        $this->customerPriceProvider = $customerPriceProvider;
        $this->helper = $helper;
        $this->state = $state;
    }

    protected function configure(): void
    {
        $this->setName('erp:sync:prices')
            ->setDescription('Sincroniza precos dos produtos do ERP (MT_MATERIALLISTA) para o Magento')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apenas simula, nao faz alteracoes')
            ->addOption('sku', 's', InputOption::VALUE_REQUIRED, 'Sincroniza apenas um SKU especifico')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limita quantidade de produtos', 0)
            ->addOption('list', null, InputOption::VALUE_NONE, 'Mostra todas as listas de precos disponiveis no ERP')
            ->addOption('price-list', 'p', InputOption::VALUE_REQUIRED, 'Codigo da lista de precos (FATORPRECO) a usar')
            ->addOption('customer', 'c', InputOption::VALUE_REQUIRED, 'Mostra precos para um cliente ERP especifico');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        $output->writeln('<info>Sincronizacao de Precos ERP (MT_MATERIALLISTA)</info>');
        $output->writeln('');

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>Integracao ERP esta desabilitada.</error>');
            return Command::FAILURE;
        }

        // --list: show available price lists
        if ($input->getOption('list')) {
            return $this->showPriceLists($output);
        }

        // --customer: show prices for a specific ERP customer
        $customerCode = $input->getOption('customer');
        if ($customerCode !== null) {
            return $this->showCustomerPrices((int) $customerCode, $output);
        }

        if (!$this->helper->isPriceSyncEnabled()) {
            $output->writeln('<error>Sincronizacao de precos esta desabilitada.</error>');
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');
        $sku = $input->getOption('sku');
        $priceList = $input->getOption('price-list') ? (int) $input->getOption('price-list') : null;
        $defaultList = $priceList ?? $this->helper->getDefaultPriceList();

        $output->writeln(sprintf('  Lista de precos: <comment>#%d</comment>', $defaultList));
        $output->writeln(sprintf('  Filial: <comment>%d</comment>', $this->helper->getStockFilial()));
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>Modo DRY-RUN: nenhuma alteracao sera feita</comment>');
            $output->writeln('');
        }

        // Single SKU sync
        if ($sku) {
            return $this->syncSingleSku($sku, $output, $dryRun, $priceList);
        }

        // Full sync
        return $this->syncAll($output, $dryRun, $priceList);
    }

    private function showPriceLists(OutputInterface $output): int
    {
        $output->writeln('<info>Listas de precos disponiveis no ERP (VE_FATORPRECO):</info>');
        $output->writeln('');

        try {
            $lists = $this->priceSync->getAvailablePriceLists();

            if (empty($lists)) {
                $output->writeln('<error>Nenhuma lista de precos encontrada.</error>');
                return Command::FAILURE;
            }

            $defaultList = $this->helper->getDefaultPriceList();

            $table = new Table($output);
            $table->setHeaders(['#', 'Descricao', 'Ativo', 'Produtos', 'Clientes', 'Padrao']);

            foreach ($lists as $list) {
                $codigo = (int) $list['CODIGO'];
                $isDefault = $codigo === $defaultList ? '<info>*** SIM ***</info>' : '';
                $isActive = (($list['ATIVO'] ?? '') === 'S') ? '<info>Sim</info>' : '<comment>Nao</comment>';

                $table->addRow([
                    $codigo,
                    trim($list['DESCRICAO'] ?? ''),
                    $isActive,
                    number_format((int) ($list['total_produtos'] ?? 0), 0, '', '.'),
                    number_format((int) ($list['total_clientes'] ?? 0), 0, '', '.'),
                    $isDefault,
                ]);
            }

            $table->render();

            $output->writeln('');
            $output->writeln(sprintf(
                'Lista padrao configurada: <info>#%d</info> (altere em Admin > ERP > Sync Precos > Lista de Preco Padrao)',
                $defaultList
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function syncSingleSku(string $sku, OutputInterface $output, bool $dryRun, ?int $priceList): int
    {
        $output->writeln("Buscando preco para SKU: <comment>{$sku}</comment>");

        try {
            $priceData = $this->priceSync->getErpPrice($sku, $priceList);

            if (!$priceData) {
                $output->writeln('<error>Preco nao encontrado no ERP para este SKU nesta lista.</error>');
                return Command::FAILURE;
            }

            $output->writeln('');
            $output->writeln('<info>Dados de preco no ERP (MT_MATERIALLISTA):</info>');
            $output->writeln(sprintf('  Preco Sugerido (VLRVDSUG): R$ %.2f', $priceData['VLRVENDA'] ?? 0));
            $output->writeln(sprintf('  Preco Minimo (VLRVDMIN):   R$ %.2f', $priceData['VLRVDMIN'] ?? 0));
            $output->writeln(sprintf('  Preco Maximo (VLRVDMAX):   R$ %.2f', $priceData['VLRVDMAX'] ?? 0));
            $output->writeln(sprintf('  Custo (VLRCUSTO):          R$ %.2f', $priceData['VLRCUSTO'] ?? 0));
            $output->writeln(sprintf('  Tabela (VLRTABELA):        R$ %.2f', $priceData['VLRTABELA'] ?? 0));
            $output->writeln(sprintf('  Lista (FATORPRECO):        #%d', $priceData['FATORPRECO'] ?? 0));

            if (!$dryRun) {
                $result = $this->priceSync->syncBySku($sku, $priceList);
                $output->writeln('');
                $output->writeln($result
                    ? '<info>Preco sincronizado com sucesso!</info>'
                    : '<error>Falha ao sincronizar preco</error>');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function syncAll(OutputInterface $output, bool $dryRun, ?int $priceList): int
    {
        try {
            if ($dryRun) {
                $output->writeln('<comment>Modo DRY-RUN: mostrando primeiros 20 precos que seriam sincronizados</comment>');
                $output->writeln('');

                $prices = $this->priceSync->getErpPrices(20, 0, $priceList);

                $table = new Table($output);
                $table->setHeaders(['SKU', 'Preco Sug.', 'Min', 'Max', 'Custo']);

                foreach ($prices as $price) {
                    $table->addRow([
                        $price['CODIGO'] ?? 'N/A',
                        sprintf('R$ %.2f', $price['VLRVENDA'] ?? 0),
                        sprintf('R$ %.2f', $price['VLRVDMIN'] ?? 0),
                        sprintf('R$ %.2f', $price['VLRVDMAX'] ?? 0),
                        sprintf('R$ %.2f', $price['VLRCUSTO'] ?? 0),
                    ]);
                }

                $table->render();
                return Command::SUCCESS;
            }

            $output->writeln('<info>Iniciando sincronizacao de precos...</info>');
            $output->writeln('');

            $startTime = microtime(true);
            $result = $this->priceSync->syncAll($priceList);
            $totalTime = round(microtime(true) - $startTime, 2);

            $output->writeln('');
            $output->writeln('<info>Sincronizacao concluida!</info>');
            $output->writeln('');
            $output->writeln("  Atualizados:    <info>{$result['updated']}</info>");
            $output->writeln("  Sem alteracao:  <comment>{$result['skipped']}</comment>");
            $output->writeln("  Sem preco ERP:  <comment>{$result['not_found']}</comment>");
            $output->writeln("  Erros:          " . ($result['errors'] > 0 ? "<error>{$result['errors']}</error>" : "0"));
            $output->writeln("  Tempo:          {$totalTime}s");

            return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function showCustomerPrices(int $customerCode, OutputInterface $output): int
    {
        $output->writeln(sprintf('<info>Precos do cliente ERP #%d</info>', $customerCode));
        $output->writeln('');

        try {
            // 1. Get customer's price list
            $listCode = $this->customerPriceProvider->getCustomerPriceListCode($customerCode);

            if ($listCode === null) {
                $output->writeln('<error>Cliente nao encontrado no ERP ou sem lista de precos atribuida.</error>');
                return Command::FAILURE;
            }

            $listName = $this->customerPriceProvider->getCustomerPriceListName($customerCode);
            $defaultList = $this->helper->getDefaultPriceList();

            $output->writeln(sprintf('  Lista do cliente: <comment>#%d</comment> — %s', $listCode, $listName ?? 'N/A'));
            $output->writeln(sprintf('  Lista padrao:     <comment>#%d</comment> (NACIONAL)', $defaultList));

            if ($listCode === $defaultList) {
                $output->writeln('');
                $output->writeln('<comment>Cliente usa a lista padrao (NACIONAL). Precos sao os mesmos do catalogo base.</comment>');
                return Command::SUCCESS;
            }

            $output->writeln('');

            // 2. Get prices from customer's list
            $customerPrices = $this->priceSync->getErpPrices(20, 0, $listCode);

            if (empty($customerPrices)) {
                $output->writeln('<error>Nenhum preco encontrado na lista do cliente.</error>');
                return Command::FAILURE;
            }

            // 3. Get corresponding NACIONAL prices for comparison
            $skus = array_map(fn($row) => trim($row['CODIGO'] ?? ''), $customerPrices);
            $nacionalPrices = $this->priceSync->getPricesForSkus($skus, $defaultList);

            // 4. Display comparison table
            $table = new Table($output);
            $table->setHeaders(['SKU', 'Preco Cliente', 'Preco NACIONAL', 'Diferenca']);

            foreach ($customerPrices as $row) {
                $sku = trim($row['CODIGO'] ?? '');
                $customerPrice = (float) ($row['VLRVENDA'] ?? 0);
                $nacionalPrice = $nacionalPrices[$sku] ?? null;

                $diff = '';
                if ($nacionalPrice !== null && $nacionalPrice > 0) {
                    $pctDiff = (($customerPrice - $nacionalPrice) / $nacionalPrice) * 100;
                    $diffFormatted = sprintf('%+.1f%%', $pctDiff);
                    $diff = $pctDiff < 0
                        ? "<info>{$diffFormatted}</info>"
                        : ($pctDiff > 0 ? "<error>{$diffFormatted}</error>" : $diffFormatted);
                }

                $table->addRow([
                    $sku,
                    sprintf('R$ %.2f', $customerPrice),
                    $nacionalPrice !== null ? sprintf('R$ %.2f', $nacionalPrice) : '-',
                    $diff,
                ]);
            }

            $table->render();

            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Mostrando primeiros %d precos. Use --price-list %d para ver a lista completa.</info>',
                count($customerPrices),
                $listCode
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
