<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Model\OrderSync;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class SyncOrdersCommand extends Command
{
    private OrderSync $orderSync;
    private Helper $helper;
    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private AppState $appState;
    private ResourceConnection $resourceConnection;

    public function __construct(
        OrderSync $orderSync,
        Helper $helper,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AppState $appState,
        ResourceConnection $resourceConnection
    ) {
        parent::__construct();
        $this->orderSync = $orderSync;
        $this->helper = $helper;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->appState = $appState;
        $this->resourceConnection = $resourceConnection;
    }

    protected function configure(): void
    {
        $this->setName('erp:sync:orders')
            ->setDescription('Sincroniza pedidos com o ERP')
            ->addOption('send', 's', InputOption::VALUE_REQUIRED, 'Envia um pedido específico para o ERP (increment_id)')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Sincroniza status de pedidos do ERP para Magento')
            ->addOption('pending', 'p', InputOption::VALUE_NONE, 'Lista pedidos pendentes de envio ao ERP')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limite de pedidos a processar', 10)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apenas simula, não faz alterações')
            ->addOption('canceled', null, InputOption::VALUE_NONE, 'Detecta pedidos cancelados no Magento ainda abertos no ERP');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Set area code for CLI
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception $e) {
            // Area already set
        }

        if (!$this->helper->isEnabled()) {
            $output->writeln('<error>Integração ERP está desabilitada.</error>');
            return Command::FAILURE;
        }

        if (!$this->helper->isOrderSyncEnabled()) {
            $output->writeln('<error>Sincronização de pedidos está desabilitada.</error>');
            return Command::FAILURE;
        }

        $sendOrderId = $input->getOption('send');
        $syncStatus = $input->getOption('status');
        $listPending = $input->getOption('pending');
        $dryRun = $input->getOption('dry-run');

        if ($sendOrderId) {
            return $this->sendSingleOrder($sendOrderId, $output, $dryRun);
        }

        if ($syncStatus) {
            return $this->syncStatuses($output, $dryRun);
        }

        if ($listPending) {
            return $this->listPendingOrders($output, (int) $input->getOption('limit'));
        }

        if ($input->getOption('canceled')) {
            return $this->syncCanceledOrders($output, $dryRun);
        }

        // Default: show help
        return $this->showOrderSyncStatus($output);
    }

    private function sendSingleOrder(string $incrementId, OutputInterface $output, bool $dryRun): int
    {
        $output->writeln('<info>Enviando pedido para o ERP...</info>');
        $output->writeln('');
        $output->writeln("Pedido: <comment>{$incrementId}</comment>");
        $output->writeln('');

        try {
            // Find order by increment_id
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->create();

            $orders = $this->orderRepository->getList($searchCriteria)->getItems();

            if (empty($orders)) {
                $output->writeln('<error>Pedido não encontrado.</error>');
                return Command::FAILURE;
            }

            $order = reset($orders);

            $output->writeln("Cliente:    " . ($order->getCustomerEmail() ?: 'Convidado'));
            $output->writeln("CPF/CNPJ:   " . ($order->getCustomerTaxvat() ?: 'Não informado'));
            $output->writeln("Valor:      R$ " . number_format((float) $order->getGrandTotal(), 2, ',', '.'));
            $output->writeln("Status:     " . $order->getStatus());
            $output->writeln("Itens:      " . count($order->getItems()));
            $output->writeln('');

            if ($dryRun) {
                $output->writeln('<comment>Modo DRY-RUN: pedido não será enviado</comment>');
                return Command::SUCCESS;
            }

            $result = $this->orderSync->sendOrder($order);

            if ($result['success']) {
                $output->writeln('<info>✓ Pedido enviado com sucesso!</info>');
                $output->writeln('');
                $output->writeln("ID no ERP:  <comment>{$result['erp_order_id']}</comment>");
                $output->writeln("Itens:      {$result['items_synced']}");
                $output->writeln("Tempo:      {$result['execution_time']}ms");
            } else {
                $output->writeln('<error>✗ Falha ao enviar pedido</error>');
                $output->writeln('');
                $output->writeln("Erro: {$result['message']}");
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function syncStatuses(OutputInterface $output, bool $dryRun): int
    {
        $output->writeln('<info>Sincronizando status de pedidos do ERP...</info>');
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>Modo DRY-RUN: status não serão atualizados</comment>');
            return Command::SUCCESS;
        }

        $result = $this->orderSync->syncOrderStatuses();

        $output->writeln('');
        $output->writeln('<info>Sincronização concluída!</info>');
        $output->writeln('');
        $output->writeln("  Atualizados: <info>{$result['synced']}</info>");
        $output->writeln("  Ignorados:   <comment>{$result['skipped']}</comment>");
        $output->writeln("  Erros:       " . ($result['errors'] > 0 ? "<error>{$result['errors']}</error>" : "0"));

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function listPendingOrders(OutputInterface $output, int $limit): int
    {
        $output->writeln('<info>Pedidos pendentes de envio ao ERP:</info>');
        $output->writeln('');

        try {
            // IDs de pedidos já confirmados pelo ERP (via acknowledgeOrder)
            $conn = $this->resourceConnection->getConnection();
            $syncedIds = $conn->fetchCol(
                $conn->select()
                    ->from('grupoawamotos_erp_entity_map', ['magento_entity_id'])
                    ->where('entity_type = ?', 'order')
                    ->where("erp_code REGEXP '^[0-9]+$'")
            );
            $syncedIds = array_map('intval', $syncedIds);

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('state', ['new', 'processing'], 'in')
                ->setPageSize($limit * 3) // fetch extra to allow filtering
                ->create();

            $allOrders = $this->orderRepository->getList($searchCriteria)->getItems();

            // Filter out already-synced orders
            $orders = array_filter($allOrders, function ($o) use ($syncedIds) {
                return !in_array((int) $o->getEntityId(), $syncedIds, true);
            });

            if (empty($orders)) {
                $output->writeln('<comment>Nenhum pedido pendente encontrado.</comment>');
                return Command::SUCCESS;
            }

            $table = new Table($output);
            $table->setHeaders(['Pedido', 'Data', 'Cliente', 'CPF/CNPJ', 'Valor', 'Status', 'ERP?']);

            foreach (array_slice($orders, 0, $limit) as $order) {
                $table->addRow([
                    $order->getIncrementId(),
                    $order->getCreatedAt(),
                    substr($order->getCustomerEmail() ?: 'Convidado', 0, 25),
                    $order->getCustomerTaxvat() ?: '-',
                    'R$ ' . number_format((float) $order->getGrandTotal(), 2, ',', '.'),
                    $order->getStatus(),
                    'Pendente',
                ]);
            }

            $table->render();

            $output->writeln('');
            $output->writeln("<comment>Use --send PEDIDO para enviar um pedido específico.</comment>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erro: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function syncCanceledOrders(OutputInterface $output, bool $dryRun): int
    {
        $output->writeln('<info>Verificando pedidos cancelados no Magento que ainda estão abertos no ERP...</info>');
        $output->writeln('');

        $result = $this->orderSync->syncCanceledOrders();

        $found = $result['found'] ?? 0;
        if ($found === 0) {
            $output->writeln('<info>✓ Nenhum pedido cancelado encontrado aberto no ERP.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Verificar os logs em var/log/erp_integration.log para detalhes.</comment>');
        $output->writeln('<comment>Esses pedidos precisam ser cancelados manualmente no Sectra.</comment>');
        return Command::SUCCESS;
    }

    private function showOrderSyncStatus(OutputInterface $output): int
    {
        $output->writeln('<info>ERP Order Sync - Status</info>');
        $output->writeln('');
        $output->writeln('Comandos disponíveis:');
        $output->writeln('');
        $output->writeln('  <comment>--pending, -p</comment>      Lista pedidos pendentes de envio');
        $output->writeln('  <comment>--send, -s PEDIDO</comment>  Envia pedido específico para o ERP');
        $output->writeln('  <comment>--status</comment>           Sincroniza status do ERP para Magento');
        $output->writeln('  <comment>--canceled</comment>         Detecta pedidos cancelados no Magento ainda abertos no ERP');
        $output->writeln('  <comment>--dry-run</comment>          Simula sem fazer alterações');
        $output->writeln('');
        $output->writeln('Exemplos:');
        $output->writeln('  php bin/magento erp:sync:orders --pending');
        $output->writeln('  php bin/magento erp:sync:orders --send 000000123');
        $output->writeln('  php bin/magento erp:sync:orders --status');

        return Command::SUCCESS;
    }
}
