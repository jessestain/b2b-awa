<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Model\ProductSync;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class SyncProductsCommand extends Command
{
    private ProductSync $productSync;
    private Helper $helper;

    public function __construct(
        ProductSync $productSync,
        Helper $helper
    ) {
        parent::__construct();
        $this->productSync = $productSync;
        $this->helper = $helper;
    }

    protected function configure(): void
    {
        $this->setName('erp:sync:products')
            ->setDescription('Sincroniza produtos do ERP para o Magento')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apenas simula, não faz alterações')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limita quantidade de produtos', 0)
            ->addOption('sku', 's', InputOption::VALUE_REQUIRED, 'Sincroniza apenas um SKU específico')
            ->addOption(
                'deactivate-stale',
                null,
                InputOption::VALUE_NONE,
                'Desativa no Magento SKUs Sectra fora do escopo ativo (CCKATIVO, e CKCOMERCIALIZA se o filtro estiver ligado)'
            )
            ->addOption(
                'reactivate-stale',
                null,
                InputOption::VALUE_NONE,
                'Reativa no Magento SKUs Sectra que voltaram ao escopo ativo e ainda estao desativados. '
                . 'Nao reativa item com preco de placeholder (ver InactiveProductReactivationGuard).'
            )
            ->addOption(
                'reconcile-scope',
                null,
                InputOption::VALUE_NONE,
                'Faz os DOIS lados do escopo numa passada: desativa quem saiu e reativa quem voltou. '
                . 'E a forma correta no cron, porque os dois lados precisam ser simetricos.'
            )
            ->addOption(
                'publication-gate',
                null,
                InputOption::VALUE_NONE,
                'Portao de publicacao: lista (com --dry-run) ou desabilita produtos habilitados que nao existem '
                . 'no Sectra ou estao com preco de placeholder. Respeita o limite de seguranca configurado.'
            )
            ->addOption(
                'max-percent',
                null,
                InputOption::VALUE_REQUIRED,
                'Sobrescreve o limite de seguranca (%) do portao so nesta execucao'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Sincronização de Produtos ERP</info>');
        $output->writeln('');

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>Integração ERP está desabilitada.</error>');
            return Command::FAILURE;
        }

        if (!$this->helper->isProductSyncEnabled()) {
            $output->writeln('<error>Sincronização de produtos está desabilitada.</error>');
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $sku = $input->getOption('sku');

        if ($dryRun) {
            $output->writeln('<comment>Modo DRY-RUN: nenhuma alteração será feita</comment>');
            $output->writeln('');
        }

        // Single SKU sync
        if ($sku) {
            return $this->syncSingleSku($sku, $output, $dryRun);
        }

        if ($input->getOption('publication-gate')) {
            $maxPercent = $input->getOption('max-percent');
            $maxRatio = ($maxPercent !== null && (float) $maxPercent > 0 && (float) $maxPercent <= 100)
                ? (float) $maxPercent / 100
                : null;

            return $this->publicationGate($output, (bool) $dryRun, $maxRatio);
        }

        if ($input->getOption('deactivate-stale')) {
            return $this->deactivateStale($output, $dryRun);
        }

        if ($input->getOption('reactivate-stale')) {
            return $this->reactivateStale($output, $dryRun);
        }

        /* Os dois lados de uma vez: sem isto o operador precisa lembrar de rodar duas
         * vezes, e esquecer o lado "reativar" foi exatamente o que deixou 27 produtos
         * fora da loja em 06/10/2026. */
        if ($input->getOption('reconcile-scope')) {
            return $this->reconcileScope($output, $dryRun);
        }

        // Full sync
        return $this->syncAll($output, $dryRun, $limit);
    }

    private function publicationGate(OutputInterface $output, bool $dryRun, ?float $maxRatio): int
    {
        $output->writeln('<info>Portao de publicacao: o que esta habilitado na loja e nao pode ficar</info>');
        $output->writeln('');

        $r = $this->productSync->reconcilePublicationGate($dryRun, $maxRatio);

        $output->writeln(sprintf('Materiais Sectra:           <comment>%d</comment>', (int) $r['all_erp']));
        $output->writeln(sprintf('Habilitados (simples):      <comment>%d</comment>', (int) $r['enabled_total']));
        $output->writeln(sprintf('Outros tipos (ignorados):   <comment>%d</comment>', (int) $r['other_types_ignored']));
        $output->writeln(sprintf('Fora do Sectra:             <comment>%d</comment>', (int) $r['missing_from_erp']));
        $output->writeln(sprintf('Preco placeholder/ausente:  <comment>%d</comment>', (int) $r['placeholder_price']));
        $output->writeln(sprintf('Preco < R$ 1 (so revisar):  <comment>%d</comment>', (int) $r['low_price_review']));
        $output->writeln(sprintf(
            'Candidatos:                 <comment>%d</comment> (limite %.0f%% do habilitado)',
            (int) $r['candidates'],
            (float) $r['max_ratio'] * 100
        ));

        if ($r['skipped']) {
            $output->writeln('<error>Portao ignorado: ' . $r['skip_reason'] . '</error>');
            return Command::FAILURE;
        }

        foreach (['missing_skus' => 'Fora do Sectra', 'placeholder_skus' => 'Preco placeholder', 'low_price_skus' => 'Preco baixo (revisar)'] as $key => $label) {
            if ($r[$key] !== []) {
                $output->writeln('');
                $output->writeln('<comment>' . $label . ' (ate 50):</comment>');
                foreach ($r[$key] as $sku) {
                    $output->writeln('  ' . $sku);
                }
            }
        }

        if ($r['over_limit']) {
            $output->writeln('');
            $output->writeln('<error>Candidatos acima do limite de seguranca: ' . ($dryRun
                ? 'em execucao real NADA seria alterado ate revisar a lista.'
                : 'NADA foi alterado. Revise a lista e rode de novo com --max-percent maior, se for legitimo.') . '</error>');
            return $dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('');
        if ($dryRun) {
            $output->writeln(sprintf('<comment>DRY-RUN: %d produto(s) seriam desabilitados.</comment>', (int) $r['candidates']));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Desabilitados: <info>%d</info>', (int) $r['disabled']));
        return Command::SUCCESS;
    }

    private function deactivateStale(OutputInterface $output, bool $dryRun): int
    {
        $output->writeln('<info>Reconciliacao de produtos fora do escopo ativo do Sectra</info>');
        $output->writeln('');

        $result = $this->productSync->reconcileInactiveProducts($dryRun);

        $output->writeln(sprintf('Materiais Sectra: <comment>%d</comment>', (int) $result['all_erp']));
        $output->writeln(sprintf('Escopo ativo:     <comment>%d</comment>', (int) $result['active_erp']));
        $output->writeln(sprintf('Candidatos:       <comment>%d</comment>', (int) $result['candidates']));

        if (!empty($result['skip_reason'])) {
            $output->writeln('<error>Reconciliacao ignorada: ' . $result['skip_reason'] . '</error>');
            return Command::FAILURE;
        }

        if ($dryRun) {
            foreach ($result['candidate_skus'] as $sku) {
                $output->writeln('  ' . $sku);
            }
            $output->writeln('');
            $output->writeln('<comment>DRY-RUN: nenhum produto foi desativado.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Desativados:      <info>%d</info>', (int) $result['deactivated']));
        return Command::SUCCESS;
    }

    private function reactivateStale(OutputInterface $output, bool $dryRun): int
    {
        $output->writeln('<info>Reconciliacao de produtos que VOLTARAM ao escopo ativo do Sectra</info>');
        $output->writeln('');

        $result = $this->productSync->reconcileReactivation($dryRun);

        $output->writeln(sprintf('Materiais Sectra:  <comment>%d</comment>', (int) $result['all_erp']));
        $output->writeln(sprintf('Escopo ativo:      <comment>%d</comment>', (int) $result['active_erp']));
        $output->writeln(sprintf('Candidatos:        <comment>%d</comment>', (int) $result['candidates']));
        $output->writeln(sprintf('Retidos por preco: <comment>%d</comment>', (int) $result['blocked_by_price']));

        if (!empty($result['skip_reason'])) {
            $output->writeln('<error>Reconciliacao ignorada: ' . $result['skip_reason'] . '</error>');
            return Command::FAILURE;
        }

        if ((int) $result['blocked_by_price'] > 0) {
            $output->writeln('');
            $output->writeln('<comment>Retidos por preco de placeholder (corrigir no Sectra):</comment>');
            foreach (array_slice($result['blocked_skus'], 0, 25) as $sku) {
                $output->writeln('  ' . $sku);
            }
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>DRY-RUN: %d produto(s) seriam reativados; %d retidos por preco.</comment>',
                (int) ($result['reactivatable'] ?? 0),
                (int) $result['blocked_by_price']
            ));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf('Reativados:        <info>%d</info>', (int) $result['reactivated']));
        return Command::SUCCESS;
    }

    /**
     * Os dois lados numa passada. E a forma que o cron deve usar: desativar sem
     * reativar e o bug que deixou 27 produtos fora da loja.
     */
    private function reconcileScope(OutputInterface $output, bool $dryRun): int
    {
        $saida = $this->deactivateStale($output, $dryRun);

        $output->writeln('');
        $reativar = $this->reactivateStale($output, $dryRun);

        return ($saida === Command::SUCCESS && $reativar === Command::SUCCESS)
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function syncSingleSku(string $sku, OutputInterface $output, bool $dryRun): int
    {
        $output->writeln("Buscando produto SKU: <comment>{$sku}</comment>");

        $products = $this->productSync->getErpProducts(1, 0);

        // Search for specific SKU
        $found = false;
        foreach ($this->productSync->getErpProducts(10000, 0) as $product) {
            if (trim($product['CODIGO']) === $sku) {
                $found = true;
                $output->writeln('');
                $output->writeln('<info>Produto encontrado no ERP:</info>');
                $output->writeln("  SKU: {$product['CODIGO']}");
                $output->writeln("  Nome: {$product['DESCRICAO']}");
                $output->writeln("  Preço: R$ " . number_format((float)($product['VLRVENDA'] ?? 0), 2, ',', '.'));
                $output->writeln("  Ativo: " . (($product['CCKATIVO'] ?? 'N') === 'S' ? 'Sim' : 'Não'));

                if (!$dryRun) {
                    $result = $this->productSync->syncBySku($sku);
                    $output->writeln('');
                    $output->writeln($result ? '<info>✓ Produto sincronizado com sucesso!</info>' : '<error>✗ Falha ao sincronizar produto</error>');
                }
                break;
            }
        }

        if (!$found) {
            $output->writeln('<error>Produto não encontrado no ERP.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function syncAll(OutputInterface $output, bool $dryRun, int $limit): int
    {
        $totalCount = $this->productSync->getErpProductCount();

        $output->writeln("Total de produtos no ERP: <comment>{$totalCount}</comment>");

        if ($limit > 0) {
            $output->writeln("Limite definido: <comment>{$limit}</comment>");
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<comment>Primeiros 10 produtos que seriam sincronizados:</comment>');

            $products = $this->productSync->getErpProducts(10, 0);
            foreach ($products as $product) {
                $output->writeln(sprintf(
                    '  [%s] %s - R$ %.2f',
                    $product['CODIGO'],
                    substr($product['DESCRICAO'] ?? '', 0, 50),
                    (float)($product['VLRVENDA'] ?? 0)
                ));
            }

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>Iniciando sincronização...</info>');
        $output->writeln('');

        $startTime = microtime(true);
        $result = $this->productSync->syncAll();
        $totalTime = round(microtime(true) - $startTime, 2);

        $output->writeln('');
        $output->writeln('<info>Sincronização concluída!</info>');
        $output->writeln('');
        $output->writeln("  Criados:     <info>{$result['created']}</info>");
        $output->writeln("  Atualizados: <info>{$result['updated']}</info>");
        $output->writeln("  Desativados: <comment>{$result['deactivated']}</comment>");
        $output->writeln("  Ignorados:   <comment>{$result['skipped']}</comment>");
        $output->writeln("  Erros:       " . ($result['errors'] > 0 ? "<error>{$result['errors']}</error>" : "0"));
        $output->writeln("  Batches:     {$result['batches_processed']}");
        $output->writeln("  Tempo:       {$totalTime}s");
        $output->writeln('');
        $output->writeln('<comment>💡 Nota: Este comando sincroniza apenas dados de texto (nome, preço, status, categorias).</comment>');
        $output->writeln('<comment>   Para sincronizar IMAGENS, execute: php bin/magento erp:sync:images</comment>');
        $output->writeln('<comment>   Ou use: make erp-sync-images</comment>');

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
