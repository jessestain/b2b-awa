<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\ImageSyncInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncImagesCommand extends Command
{
    private ImageSyncInterface $imageSync;
    private Helper $helper;
    private State $appState;
    private ProductRepositoryInterface $productRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private ConnectionInterface $connection;

    public function __construct(
        ImageSyncInterface $imageSync,
        Helper $helper,
        State $appState,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ConnectionInterface $connection,
        ?string $name = null
    ) {
        $this->imageSync = $imageSync;
        $this->helper = $helper;
        $this->appState = $appState;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->connection = $connection;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('erp:sync:images')
            ->setDescription('Sincroniza imagens de produtos do ERP para o Magento')
            ->addOption('sku', 's', InputOption::VALUE_OPTIONAL, 'Sincronizar apenas um SKU específico')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forçar sync mesmo se desabilitado')
            ->addOption('list-missing', 'l', InputOption::VALUE_NONE, 'Listar produtos sem imagem no Magento')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Mostrar status da configuracao de sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        if ($input->getOption('status')) {
            return $this->showStatus($output);
        }

        if ($input->getOption('list-missing')) {
            return $this->listMissing($output);
        }

        $sku = $input->getOption('sku');
        $force = $input->getOption('force');

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>Integração ERP está desabilitada.</error>');
            return Command::FAILURE;
        }

        if (!$this->helper->isImageSyncEnabled() && !$force) {
            $output->writeln('<error>Sync de imagens está desabilitado. Use --force para forçar.</error>');
            return Command::FAILURE;
        }

        if ($sku) {
            $output->writeln("<info>Sincronizando imagens para SKU: {$sku}</info>");

            $success = $this->imageSync->syncBySku($sku);

            if ($success) {
                $output->writeln('<info>Imagens sincronizadas com sucesso!</info>');
            } else {
                $erpImages = $this->imageSync->getErpImages($sku);
                if (empty($erpImages)) {
                    $output->writeln('<comment>Nenhuma imagem encontrada na fonte (ERP/pasta).</comment>');
                } else {
                    $output->writeln('<info>Imagens já estão atualizadas (sem mudanças detectadas).</info>');
                }
            }
            return Command::SUCCESS;
        }

        $output->writeln('<info>Iniciando sincronização de todas as imagens...</info>');

        $result = $this->imageSync->syncAll($force);

        $output->writeln('');
        $output->writeln('<info>Resultado:</info>');
        $output->writeln(sprintf('  Total de produtos: %d', $result['total']));
        $output->writeln(sprintf('  Sincronizados: %d', $result['synced']));
        $output->writeln(sprintf('  Ignorados: %d', $result['skipped']));
        $output->writeln(sprintf('  Erros: %d', $result['errors']));
        $output->writeln(sprintf('  Tempo de execução: %d ms', $result['execution_time']));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showStatus(OutputInterface $output): int
    {
        $output->writeln('<info>Status da Sincronização de Imagens:</info>');
        $output->writeln('');

        $output->writeln(sprintf('  ERP habilitado:        %s', $this->helper->isEnabled() ? 'Sim' : 'Nao'));
        $output->writeln(sprintf('  Image sync habilitado: %s', $this->helper->isImageSyncEnabled() ? 'Sim' : 'Nao'));
        $output->writeln(sprintf('  Fonte:                 %s', $this->helper->getImageSource() ?: 'auto'));
        $output->writeln(sprintf('  Pasta base:            %s', $this->helper->getImageBasePath() ?: '(nao configurada)'));
        $output->writeln(sprintf('  URL base:              %s', $this->helper->getImageBaseUrl() ?: '(nao configurada)'));
        $output->writeln(sprintf('  Substituir existentes: %s', $this->helper->shouldReplaceImages() ? 'Sim' : 'Nao'));

        $basePath = $this->helper->getImageBasePath();
        if ($basePath && is_dir($basePath)) {
            $files = glob($basePath . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
            $output->writeln(sprintf('  Imagens na pasta:      %d', $files ? count($files) : 0));
        } elseif ($basePath) {
            $output->writeln('  <comment>Pasta base não existe!</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>Convencao de nomes de arquivo na pasta:</info>');
        $output->writeln('  {SKU}.jpg              - imagem principal do produto');
        $output->writeln('  {SKU}_1.jpg            - imagens adicionais (_2, _3...)');
        $output->writeln('  {CODINTERNO}.jpg       - usando codigo interno do ERP');
        $output->writeln('');
        $output->writeln('<info>Comandos:</info>');
        $output->writeln('  erp:sync:images --sku=0087        Sync um produto');
        $output->writeln('  erp:sync:images --force            Sync todos (forcar)');
        $output->writeln('  erp:sync:images --list-missing     Listar produtos sem imagem');

        return Command::SUCCESS;
    }

    private function listMissing(OutputInterface $output): int
    {
        $output->writeln('<info>Buscando produtos sem imagem no Magento...</info>');
        $output->writeln('');

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $products = $this->productRepository->getList($searchCriteria);

        $missing = [];
        $withImage = 0;
        $total = 0;

        foreach ($products->getItems() as $product) {
            $total++;
            $image = $product->getData('image');
            if (empty($image) || $image === 'no_selection') {
                $missing[] = [
                    'sku' => $product->getSku(),
                    'name' => $product->getName(),
                ];
            } else {
                $withImage++;
            }
        }

        // Try to get CODINTERNO mapping from ERP
        $codInternoMap = [];
        try {
            $rows = $this->connection->query(
                "SELECT CODIGO, CODINTERNO, DESCRICAO FROM MT_MATERIAL WHERE CODINTERNO IS NOT NULL AND CODINTERNO != 0"
            );
            foreach ($rows as $row) {
                $codInternoMap[$row['CODIGO']] = $row['CODINTERNO'];
            }
        } catch (\Exception $e) {
            $output->writeln('<comment>ERP indisponível, pulando coluna CODINTERNO: ' . $e->getMessage() . '</comment>');
        }

        $output->writeln(sprintf('Total de produtos: %d', $total));
        $output->writeln(sprintf('Com imagem: %d', $withImage));
        $output->writeln(sprintf('<comment>Sem imagem: %d</comment>', count($missing)));
        $output->writeln('');

        if (!empty($missing)) {
            $output->writeln('SKU        | CodInterno | Produto');
            $output->writeln(str_repeat('-', 80));
            foreach ($missing as $item) {
                $codInterno = $codInternoMap[$item['sku']] ?? '-';
                $output->writeln(sprintf(
                    '%-10s | %-10s | %s',
                    $item['sku'],
                    $codInterno,
                    mb_substr($item['name'], 0, 55)
                ));
            }

            $output->writeln('');
            $basePath = $this->helper->getImageBasePath();
            if ($basePath) {
                $output->writeln(sprintf('<info>Coloque as fotos em: %s</info>', $basePath));
                $output->writeln('Nomeie como: {SKU}.jpg ou {CODINTERNO}.jpg');
            } else {
                $output->writeln('<comment>Configure a pasta base em: Admin > Stores > Config > ERP > Sync Imagens > Pasta Base</comment>');
            }
        }

        return Command::SUCCESS;
    }
}
