<?php
declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Console\Command;

use GrupoAwamotos\ERPIntegration\Model\ErpOrderReconciler;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * erp:orders:reconcile
 *
 * Simula por padrao; grava apenas com --apply.
 *
 * Codigos de saida (para uso em cron/alertas):
 *   0 = nada a fazer, ou tudo reconciliado sem ressalva
 *   1 = erro ao reconciliar algum pedido
 *   2 = existe pedido para conferencia humana (estrutura divergente)
 */
class ReconcileOrdersCommand extends Command
{
    public const SAIDA_OK = 0;
    public const SAIDA_ERRO = 1;
    public const SAIDA_REVISAO_HUMANA = 2;

    private ErpOrderReconciler $reconciler;
    private AppState $appState;

    public function __construct(
        ErpOrderReconciler $reconciler,
        AppState $appState
    ) {
        $this->reconciler = $reconciler;
        $this->appState = $appState;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('erp:orders:reconcile')
            ->setDescription(
                'Fecha pedidos que ja existem no Sectra (VE_PEDIDO.PEDIDOWEB = 200000 + entity_id) '
                . 'mas seguem pendentes no Magento porque o ACK nunca retornou. Simula por padrao.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Grava a reconciliacao. Sem esta flag, apenas simula.'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximo de pedidos pendentes avaliados por execucao',
                '200'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Reconcilia tambem os pedidos cuja estrutura de itens difere da do ERP'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_REQUIRED,
                'Caminho para gravar o relatorio completo em JSON'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area ja definida -- a CLI do Magento lanca aqui com frequencia.
        }

        $aplicar = (bool) $input->getOption('apply');
        $forcar = (bool) $input->getOption('force');
        $limite = (int) $input->getOption('limit');
        $json = $input->getOption('json');

        $output->writeln('');
        $output->writeln(
            $aplicar
                ? '<info>Reconciliacao de pedidos ERP -- MODO APPLY (gravando)</info>'
                : '<comment>Reconciliacao de pedidos ERP -- SIMULACAO (nada sera gravado; use --apply)</comment>'
        );

        try {
            $relatorio = $this->reconciler->reconciliar($aplicar, $limite, $forcar);
        } catch (\Throwable $e) {
            $output->writeln('<error>Falha ao reconciliar: ' . $e->getMessage() . '</error>');
            return self::SAIDA_ERRO;
        }

        $output->writeln(sprintf('Pedidos pendentes no Magento : <info>%d</info>', $relatorio['pendentes_no_magento']));
        $output->writeln(sprintf('Destes, ja existem no ERP    : <info>%d</info>', $relatorio['pendentes_que_ja_estao_no_erp']));
        $output->writeln('');

        $linhas = $aplicar ? $relatorio['reconciliados'] : $relatorio['a_reconciliar'];

        if ($linhas) {
            $output->writeln($aplicar ? 'Reconciliados agora:' : 'Serao reconciliados:');
            $table = new Table($output);
            $table->setHeaders(['Pedido', 'entity_id', 'Ref. ERP', 'CODIGO ERP', 'Linhas M/E', 'Valor Magento', 'Valor ERP']);
            foreach ($linhas as $linha) {
                $table->addRow([
                    $linha['increment_id'],
                    $linha['entity_id'],
                    $linha['referencia_erp'],
                    $linha['erp_codigo'],
                    $linha['linhas_magento'] . '/' . $linha['linhas_erp'],
                    $linha['valor_magento'] === null ? '-' : number_format((float) $linha['valor_magento'], 2, ',', '.'),
                    $linha['valor_erp'] === null ? '-' : number_format((float) $linha['valor_erp'], 2, ',', '.'),
                ]);
            }
            $table->render();
        } elseif (!$relatorio['requer_conferencia']) {
            $output->writeln('<info>Nada a reconciliar.</info>');
        }

        if ($relatorio['requer_conferencia']) {
            $output->writeln('');
            $output->writeln('<comment>Requer conferencia humana -- pedido existe no ERP, mas a estrutura de itens difere:</comment>');
            $table = new Table($output);
            $table->setHeaders(['Pedido', 'entity_id', 'Ref. ERP', 'CODIGO ERP', 'Linhas M/E', 'Valor Magento', 'Valor ERP']);
            foreach ($relatorio['requer_conferencia'] as $linha) {
                $table->addRow([
                    $linha['increment_id'],
                    $linha['entity_id'],
                    $linha['referencia_erp'],
                    $linha['erp_codigo'],
                    $linha['linhas_magento'] . '/' . $linha['linhas_erp'],
                    $linha['valor_magento'] === null ? '-' : number_format((float) $linha['valor_magento'], 2, ',', '.'),
                    $linha['valor_erp'] === null ? '-' : number_format((float) $linha['valor_erp'], 2, ',', '.'),
                ]);
            }
            $table->render();
            $output->writeln(
                'Nada foi gravado para estes. Confirme com o ERP e, se for o caso, rode com --force.'
            );
        }

        if ($relatorio['erros']) {
            $output->writeln('');
            foreach ($relatorio['erros'] as $erro) {
                $output->writeln(sprintf('<error>Erro em %s: %s</error>', $erro['increment_id'], (string) $erro['erro']));
            }
        }

        if ($json) {
            $caminho = (string) $json;
            $gravado = @file_put_contents(
                $caminho,
                (string) json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $output->writeln($gravado
                ? sprintf('Relatorio JSON: %s', $caminho)
                : sprintf('<error>Nao foi possivel gravar o relatorio em %s</error>', $caminho));
        }

        $output->writeln('');

        if ($relatorio['erros']) {
            return self::SAIDA_ERRO;
        }
        if ($relatorio['requer_conferencia']) {
            return self::SAIDA_REVISAO_HUMANA;
        }

        return self::SAIDA_OK;
    }
}