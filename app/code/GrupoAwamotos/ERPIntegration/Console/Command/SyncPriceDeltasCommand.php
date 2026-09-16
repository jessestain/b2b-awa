<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\PriceDeltaSync;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncPriceDeltasCommand extends Command
{
    private const OPTION_CYCLES = 'cycles';
    private const OPTION_UNTIL_IDLE = 'until-idle';
    private const OPTION_STATUS = 'status';
    private const OPTION_DIAGNOSE_MISSING = 'diagnose-missing';
    private const OPTION_MISSING_LIMIT = 'missing-limit';
    private const OPTION_PRICE_LIST = 'price-list';

    public function __construct(
        private readonly PriceDeltaSync $priceDeltaSync,
        private readonly Helper $helper,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('erp:sync:price-deltas')
            ->setDescription('Executa o sync incremental de tabelas/precos B2B do ERP Sectra')
            ->addOption(self::OPTION_CYCLES, 'c', InputOption::VALUE_REQUIRED, 'Quantidade maxima de ciclos', 1)
            ->addOption(self::OPTION_UNTIL_IDLE, null, InputOption::VALUE_NONE, 'Roda ate encontrar ciclo sem novos registros')
            ->addOption(self::OPTION_STATUS, null, InputOption::VALUE_NONE, 'Mostra cursores atuais do delta')
            ->addOption(self::OPTION_DIAGNOSE_MISSING, null, InputOption::VALUE_NONE, 'Lista SKUs do ERP sem produto correspondente no Magento')
            ->addOption(self::OPTION_MISSING_LIMIT, null, InputOption::VALUE_REQUIRED, 'Limite de SKUs ausentes para listar', 50)
            ->addOption(self::OPTION_PRICE_LIST, 'p', InputOption::VALUE_REQUIRED, 'Lista de preco ERP para diagnostico de ausentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeAreaCode();

        if ((bool) $input->getOption(self::OPTION_STATUS)) {
            $this->renderState($output);
            if (!(bool) $input->getOption(self::OPTION_DIAGNOSE_MISSING)
                && !(bool) $input->getOption(self::OPTION_UNTIL_IDLE)
                && (int) $input->getOption(self::OPTION_CYCLES) === 1
            ) {
                return Command::SUCCESS;
            }
        }

        if (!$this->helper->isPriceDeltaSyncEnabled()) {
            $output->writeln('<error>Sync incremental de precos esta desabilitado.</error>');
            return Command::FAILURE;
        }

        $cycles = max(1, min((int) $input->getOption(self::OPTION_CYCLES), 200));
        $untilIdle = (bool) $input->getOption(self::OPTION_UNTIL_IDLE);

        if ($untilIdle && $cycles === 1) {
            $cycles = 50;
        }

        $output->writeln('<info>Sync incremental de precos B2B Sectra</info>');
        $output->writeln(sprintf('Ciclos maximos: %d%s', $cycles, $untilIdle ? ' (ate idle)' : ''));

        $lastResult = [];
        $previousCursors = $this->priceDeltaSync->getStateSnapshot()['cursors'];
        $trackedCursorKeys = array_keys($previousCursors);
        $completedCursorKeys = [];
        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            $startedAt = microtime(true);
            $lastResult = $this->priceDeltaSync->execute();
            $elapsed = round(microtime(true) - $startedAt, 2);

            $output->writeln(sprintf(
                'Ciclo %d: clientes=%d, alterados=%d, linhas_preco=%d, cache=%d, catalogo=%d, ausentes=%d, erros=%d, tempo=%ss',
                $cycle,
                (int) ($lastResult['customers_scanned'] ?? 0),
                (int) ($lastResult['customers_changed'] ?? 0),
                (int) ($lastResult['price_rows_scanned'] ?? 0),
                (int) ($lastResult['price_cache_changed'] ?? 0),
                (int) ($lastResult['catalog_updated'] ?? 0),
                (int) ($lastResult['catalog_missing'] ?? 0),
                (int) ($lastResult['errors'] ?? 0),
                $elapsed
            ));

            if ((int) ($lastResult['errors'] ?? 0) > 0) {
                break;
            }

            $currentCursors = $this->priceDeltaSync->getStateSnapshot()['cursors'];
            $trackedCursorKeys = array_values(array_unique(array_merge($trackedCursorKeys, array_keys($currentCursors))));
            foreach ($this->getCompletedCursorKeys($previousCursors, $currentCursors) as $cursorKey) {
                $completedCursorKeys[$cursorKey] = true;
            }

            if ($untilIdle
                && $cycle > 1
                && $trackedCursorKeys !== []
                && count($completedCursorKeys) >= count($trackedCursorKeys)
            ) {
                $output->writeln('<comment>Varredura completa: todos os cursores percorreram a base ao menos uma vez.</comment>');
                break;
            }

            if ($untilIdle
                && (int) ($lastResult['customers_scanned'] ?? 0) === 0
                && (int) ($lastResult['price_rows_scanned'] ?? 0) === 0
            ) {
                $output->writeln('<comment>Nenhum registro novo encontrado neste ciclo.</comment>');
                break;
            }

            $previousCursors = $currentCursors;
        }

        $this->renderState($output);

        if ((bool) $input->getOption(self::OPTION_DIAGNOSE_MISSING)) {
            $this->renderMissingSkus($input, $output);
        }

        return (int) ($lastResult['errors'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string,string> $previousCursors
     * @param array<string,string> $currentCursors
     * @return string[]
     */
    private function getCompletedCursorKeys(array $previousCursors, array $currentCursors): array
    {
        $completed = [];
        $cursorKeys = array_values(array_unique(array_merge(array_keys($previousCursors), array_keys($currentCursors))));

        foreach ($cursorKeys as $cursorKey) {
            $previous = (string) ($previousCursors[$cursorKey] ?? '');
            $current = (string) ($currentCursors[$cursorKey] ?? '');

            if ($previous === '' && $current === '') {
                $completed[] = $cursorKey;
                continue;
            }

            if ($previous !== '' && $current === '') {
                $completed[] = $cursorKey;
                continue;
            }

            if ($this->cursorMovedBackwards($cursorKey, $previous, $current)) {
                $completed[] = $cursorKey;
            }
        }

        return $completed;
    }

    private function cursorMovedBackwards(string $cursorKey, string $previous, string $current): bool
    {
        if ($previous === '' || $current === '') {
            return false;
        }

        if ($cursorKey === 'price_delta_customer_cursor') {
            return (int) $current < (int) $previous;
        }

        return strcmp($current, $previous) < 0;
    }

    private function initializeAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (LocalizedException) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }
    }

    private function renderState(OutputInterface $output): void
    {
        $snapshot = $this->priceDeltaSync->getStateSnapshot();
        $output->writeln('');
        $output->writeln('<info>Estado do delta</info>');
        $output->writeln(sprintf('Clientes rastreados: %d', (int) $snapshot['customer_price_states']));
        $output->writeln(sprintf('Listas rastreadas: %d', (int) $snapshot['tracked_lists']));

        $cursors = $snapshot['cursors'];
        if ($cursors === []) {
            $output->writeln('Cursores: nenhum');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Cursor', 'Valor']);
        foreach ($cursors as $key => $value) {
            $table->addRow([$key, $value === '' ? '(inicio)' : $value]);
        }
        $table->render();
    }

    private function renderMissingSkus(InputInterface $input, OutputInterface $output): void
    {
        $limit = max(1, min((int) $input->getOption(self::OPTION_MISSING_LIMIT), 1000));
        $priceList = $input->getOption(self::OPTION_PRICE_LIST);
        $priceList = $priceList !== null && $priceList !== '' ? (int) $priceList : null;

        $missing = $this->priceDeltaSync->getMissingCatalogSkus($limit, $priceList);

        $output->writeln('');
        $output->writeln(sprintf('<info>SKUs ERP sem produto Magento correspondente (amostra: %d)</info>', count($missing)));

        if ($missing === []) {
            $output->writeln('Nenhum SKU ausente encontrado nesta amostra.');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['SKU ERP', 'SKU base', 'Lista', 'Preco']);
        foreach ($missing as $row) {
            $table->addRow([
                $row['sku'],
                $row['base_sku'] !== '' ? $row['base_sku'] : '-',
                $row['price_list_code'],
                number_format((float) $row['price'], 2, ',', '.'),
            ]);
        }
        $table->render();
    }
}
