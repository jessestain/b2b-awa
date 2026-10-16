<?php
/**
 * ACK manual de pedido já existente no Sectra (reconciliação comercial).
 *
 * Uso: bin/magento b2b:sectra:manual-ack <increment_id> <erp_order_code> [--reason="..."]
 *
 * NÃO altera dados do ERP — apenas grava o vínculo no entity_map, comenta o
 * histórico do pedido no Magento e marca o pedido como importado (mesmo caminho
 * do ACK automático, porém acionado por decisão humana explícita, pedido a pedido).
 */
declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface as ErpConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ManualOrderAckCommand extends Command
{
    public function __construct(
        private readonly OrderPullInterface $orderPull,
        private readonly ErpConnectionInterface $erpConnection,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:sectra:manual-ack')
            ->setDescription('Grava o ACK de um pedido já existente no Sectra após decisão humana (pedido a pedido)')
            ->addArgument(
                'increment_id',
                InputArgument::REQUIRED,
                'Increment ID do pedido no Magento (ex: 000000116)'
            )
            ->addArgument(
                'erp_order_code',
                InputArgument::REQUIRED,
                'CODIGO do pedido no Sectra (VE_PEDIDO.CODIGO, ex: 196568)'
            )
            ->addOption(
                'reason',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Justificativa gravada no histórico do pedido',
                'ACK manual autorizado pelo time comercial'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Área já definida pelo bootstrap CLI — seguro ignorar.
        }

        $incrementId = trim((string) $input->getArgument('increment_id'));
        $erpCode = trim((string) $input->getArgument('erp_order_code'));
        $reason = trim((string) $input->getOption('reason'));

        if ($incrementId === '' || $erpCode === '') {
            $output->writeln(
                '<error>Informe increment_id e erp_order_code (ex: bin/magento b2b:sectra:manual-ack 000000116 196568)</error>'
            );
            return Command::FAILURE;
        }

        if (!ctype_digit($erpCode) || (int) $erpCode <= 0) {
            $output->writeln('<error>erp_order_code deve ser um inteiro positivo (VE_PEDIDO.CODIGO)</error>');
            return Command::FAILURE;
        }

        // Valida que o CODIGO existe no Sectra (leitura segura — não altera nada).
        try {
            $rows = $this->erpConnection->query(
                'SELECT PEDIDOWEB FROM VE_PEDIDO WHERE CODIGO = ?',
                [(int) $erpCode]
            );
            if (!is_array($rows) || count($rows) === 0) {
                $output->writeln(sprintf(
                    '<error>CODIGO %s não encontrado em VE_PEDIDO — confira antes de prosseguir</error>',
                    $erpCode
                ));
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao validar no Sectra: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $response = $this->orderPull->acknowledgeOrder($incrementId, (string) (int) $erpCode, $reason);
            $success = (bool) ($response[0]['success'] ?? false);
            $message = (string) ($response[0]['message'] ?? '');
        } catch (\Throwable $e) {
            $output->writeln('<error>ACK falhou: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($success) {
            $output->writeln(sprintf(
                '<info>ACK gravado: %s <-> ERP %s (%s)</info>',
                $incrementId,
                $erpCode,
                $reason
            ));
            return Command::SUCCESS;
        }

        $output->writeln('<error>ACK não confirmado: ' . ($message !== '' ? $message : 'sem detalhe') . '</error>');
        return Command::FAILURE;
    }
}

