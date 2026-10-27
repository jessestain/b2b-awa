<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Relatorio SOMENTE LEITURA: produtos habilitados sem imagem, priorizados por vendas.
 *
 * Por que existe: o ImageSync so busca foto de quem ja TEM foto no ERP; nada
 * perguntava "quais produtos publicados estao sem foto?". Medido no sitemap em
 * 16/10/2026: 226 de 680 produtos publicados sem imagem (o Magento serve o placeholder).
 * Esconder tudo seria perder venda; a lista priorizada deixa a equipe cadastrar primeiro
 * o que mais vende.
 *
 * A coluna gallery_images separa "sem foto nenhuma" de "tem foto na galeria mas o papel
 * image/small_image/thumbnail nao esta marcado" — este segundo caso se corrige sem fotografar.
 */
class ReportProductsWithoutImageCommand extends Command
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('erp:report:products-without-image')
            ->setDescription('Lista produtos habilitados sem imagem, priorizados por vendas (somente leitura)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Janela de vendas em dias', 365)
            ->addOption('no-csv', null, InputOption::VALUE_NONE, 'Nao grava CSV, so mostra o resumo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $db = $this->resource->getConnection();

        $attrId = function (string $code) use ($db): int {
            return (int) $db->fetchOne(
                $db->select()
                    ->from($db->getTableName('eav_attribute'), ['attribute_id'])
                    ->where('attribute_code = ?', $code)
                    ->where('entity_type_id = ?', 4)
            );
        };

        $status = $attrId('status');
        $image = $attrId('image');
        $name = $attrId('name');
        $price = $attrId('price');
        if ($status <= 0 || $image <= 0 || $name <= 0 || $price <= 0) {
            $output->writeln('<error>Atributos status/image/name/price nao encontrados.</error>');
            return Command::FAILURE;
        }

        $t = fn (string $table): string => $db->getTableName($table);

        $sql = "SELECT cpe.entity_id, cpe.sku, nm.value AS name, pr.value AS price,
                       COALESCE(g.total, 0) AS gallery_images,
                       COALESCE(s.qty, 0) AS qty_sold, COALESCE(s.orders, 0) AS orders
                FROM {$t('catalog_product_entity')} cpe
                INNER JOIN {$t('catalog_product_entity_int')} st
                    ON st.entity_id = cpe.entity_id AND st.attribute_id = {$status}
                   AND st.store_id = 0 AND st.value = 1
                LEFT JOIN {$t('catalog_product_entity_varchar')} im
                    ON im.entity_id = cpe.entity_id AND im.attribute_id = {$image} AND im.store_id = 0
                LEFT JOIN {$t('catalog_product_entity_varchar')} nm
                    ON nm.entity_id = cpe.entity_id AND nm.attribute_id = {$name} AND nm.store_id = 0
                LEFT JOIN {$t('catalog_product_entity_decimal')} pr
                    ON pr.entity_id = cpe.entity_id AND pr.attribute_id = {$price} AND pr.store_id = 0
                LEFT JOIN (
                    SELECT entity_id, COUNT(*) AS total
                    FROM {$t('catalog_product_entity_media_gallery_value_to_entity')}
                    GROUP BY entity_id
                ) g ON g.entity_id = cpe.entity_id
                LEFT JOIN (
                    SELECT product_id, SUM(qty_ordered) AS qty, COUNT(DISTINCT order_id) AS orders
                    FROM {$t('sales_order_item')}
                    WHERE parent_item_id IS NULL AND created_at >= (NOW() - INTERVAL {$days} DAY)
                    GROUP BY product_id
                ) s ON s.product_id = cpe.entity_id
                WHERE cpe.type_id IN ('simple', 'virtual')
                  AND (im.value IS NULL OR im.value = '' OR im.value = 'no_selection')
                ORDER BY qty_sold DESC, cpe.sku ASC";

        $rows = $db->fetchAll($sql);

        $enabledTotal = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM {$t('catalog_product_entity')} cpe
             INNER JOIN {$t('catalog_product_entity_int')} st
                ON st.entity_id = cpe.entity_id AND st.attribute_id = {$status}
               AND st.store_id = 0 AND st.value = 1
             WHERE cpe.type_id IN ('simple', 'virtual')"
        );

        $withGallery = count(array_filter($rows, static fn (array $r): bool => (int) $r['gallery_images'] > 0));
        $withSales = count(array_filter($rows, static fn (array $r): bool => (float) $r['qty_sold'] > 0));
        $missing = count($rows);

        $output->writeln('<info>Produtos habilitados sem imagem</info>');
        $output->writeln(sprintf('Habilitados (simples):           <comment>%d</comment>', $enabledTotal));
        $output->writeln(sprintf(
            'Sem imagem:                     <comment>%d</comment> (%.1f%%)',
            $missing,
            $enabledTotal > 0 ? $missing / $enabledTotal * 100 : 0
        ));
        $output->writeln(sprintf('  com foto na galeria (papel):  <comment>%d</comment> -> corrige sem fotografar', $withGallery));
        $output->writeln(sprintf('  sem foto nenhuma:             <comment>%d</comment>', $missing - $withGallery));
        $output->writeln(sprintf('  que venderam em %d dias:     <comment>%d</comment> -> prioridade 1', $days, $withSales));

        $output->writeln('');
        $output->writeln('<comment>Top 15 por vendas:</comment>');
        foreach (array_slice($rows, 0, 15) as $r) {
            $output->writeln(sprintf(
                '  %-22s qtd=%-6s galeria=%-2s %s',
                $r['sku'],
                (string) (float) $r['qty_sold'],
                $r['gallery_images'],
                mb_substr((string) $r['name'], 0, 60)
            ));
        }

        if ($input->getOption('no-csv')) {
            return Command::SUCCESS;
        }

        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir->create('export');
        $relative = 'export/produtos_sem_imagem_' . date('Ymd_His') . '.csv';
        $path = $dir->getAbsolutePath($relative);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $output->writeln('<error>Nao foi possivel criar o CSV: ' . $path . '</error>');
            return Command::FAILURE;
        }
        fputcsv($handle, ['sku', 'nome', 'preco', 'qtd_vendida_' . $days . 'd', 'pedidos', 'fotos_na_galeria', 'acao'], ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($handle, [
                $r['sku'],
                $r['name'],
                $r['price'],
                (float) $r['qty_sold'],
                (int) $r['orders'],
                (int) $r['gallery_images'],
                (int) $r['gallery_images'] > 0 ? 'marcar papel image/small_image/thumbnail' : 'cadastrar foto',
            ], ';', '"', '\\');
        }
        fclose($handle);

        $output->writeln('');
        $output->writeln('CSV: <info>' . $path . '</info> (' . $missing . ' linhas)');

        return Command::SUCCESS;
    }
}
