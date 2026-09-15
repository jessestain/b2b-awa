<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Registration\NormalizeLegacyRegistrationService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NormalizeLegacyRegistrationCommand extends Command
{
    public function __construct(
        private readonly NormalizeLegacyRegistrationService $normalizeService,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:registration:normalize-legacy')
            ->setDescription('Orquestra auditoria e backfills seguros para cadastros B2B legacy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem gravar (padrão se --apply omitido)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Aplica alterações no banco')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limita clientes por etapa de backfill', '200')
            ->addOption('from-id', null, InputOption::VALUE_OPTIONAL, 'entity_id mínimo (inclusivo)')
            ->addOption('to-id', null, InputOption::VALUE_OPTIONAL, 'entity_id máximo (inclusivo)')
            ->addOption('skip-phone', null, InputOption::VALUE_NONE, 'Pula backfill de telefone')
            ->addOption('skip-razao', null, InputOption::VALUE_NONE, 'Pula backfill de razão social (cache CNPJ + OC legacy)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run') || !$apply;
        $limit = max(1, (int) $input->getOption('limit'));
        $fromId = $input->getOption('from-id') !== null ? (int) $input->getOption('from-id') : null;
        $toId = $input->getOption('to-id') !== null ? (int) $input->getOption('to-id') : null;
        $skipPhone = (bool) $input->getOption('skip-phone');
        $skipRazao = (bool) $input->getOption('skip-razao');

        if ($apply && $input->getOption('dry-run')) {
            $output->writeln('<error>Use --dry-run OU --apply, não ambos.</error>');

            return Command::FAILURE;
        }

        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }

        $output->writeln('');
        $output->writeln('<info>=== Normalização cadastros B2B legacy ===</info>');
        $output->writeln($dryRun ? '<comment>Modo DRY-RUN</comment>' : '<info>Modo APPLY</info>');
        $output->writeln(sprintf('Limite por etapa: %d', $limit));

        $report = $this->normalizeService->execute(
            !$dryRun && $apply,
            $limit,
            $fromId,
            $toId,
            $skipPhone,
            $skipRazao
        );

        $this->renderAuditBlock($output, 'Auditoria ANTES', $report['audit_before']);

        if ($report['phone'] !== null) {
            $phone = $report['phone'];
            $output->writeln('');
            $output->writeln('<info>Backfill telefone (b2b_phone ← endereço)</info>');
            $output->writeln(sprintf('  Analisados: %d | Atualizados: %d | Falhas: %d', $phone['analyzed'], $phone['updated'], $phone['failed']));
        }

        if ($report['razao_cache'] !== null) {
            $razao = $report['razao_cache'];
            $output->writeln('');
            $output->writeln('<info>Backfill razão social (b2b_razao_social ← cache CNPJ)</info>');
            $output->writeln(sprintf(
                '  Analisados: %d | Atualizados: %d | Sem cache: %d | Falhas: %d',
                $razao['analyzed'],
                $razao['updated'],
                $razao['no_cache'],
                $razao['failed']
            ));
            if ($razao['backup_path'] !== null) {
                $output->writeln(sprintf('  Backup: %s', $razao['backup_path']));
            }
        }

        if ($report['razao_oc'] !== null) {
            $razao = $report['razao_oc'];
            $output->writeln('');
            $output->writeln('<info>Backfill razão social (b2b_razao_social ← oc_customer.custom_field)</info>');
            $output->writeln(sprintf(
                '  Analisados: %d | Atualizados: %d | CNPJ divergente: %d | Falhas: %d',
                $razao['analyzed'],
                $razao['updated'],
                $razao['cnpj_mismatch'],
                $razao['failed']
            ));
            if ($razao['backup_path'] !== null) {
                $output->writeln(sprintf('  Backup: %s', $razao['backup_path']));
            }
        }

        $this->renderAuditBlock($output, 'Auditoria DEPOIS', $report['audit_after'] ?? []);

        $output->writeln('');

        $before = $report['audit_before'];
        $after = $report['audit_after'] ?? [];
        if ($after !== []) {
            $output->writeln('<info>Delta</info>');
            $output->writeln(sprintf(
                '  sem_b2b_phone: %d → %d',
                $before['no_phone'],
                $after['no_phone']
            ));
            $output->writeln(sprintf(
                '  sem_b2b_razao_social: %d → %d',
                $before['no_razao_social'],
                $after['no_razao_social']
            ));
            $output->writeln(sprintf(
                '  pode_backfill_oc_legacy: %d → %d',
                $before['can_backfill_razao_from_oc_legacy'] ?? 0,
                $after['can_backfill_razao_from_oc_legacy'] ?? 0
            ));
        }

        $failed = ($report['phone']['failed'] ?? 0)
            + ($report['razao_cache']['failed'] ?? 0)
            + ($report['razao_oc']['failed'] ?? 0);
        if ($apply && $failed > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $audit
     */
    private function renderAuditBlock(OutputInterface $output, string $title, array $audit): void
    {
        $output->writeln('');
        $output->writeln('<info>' . $title . '</info>');
        if ($audit === []) {
            $output->writeln('  (indisponível)');

            return;
        }

        $output->writeln(sprintf('  Total com CNPJ: %d', $audit['total_with_cnpj']));
        $output->writeln(sprintf('  sem_b2b_phone: %d', $audit['no_phone']));
        $output->writeln(sprintf('  sem_b2b_razao_social: %d', $audit['no_razao_social']));
        $output->writeln(sprintf('  sem_telefone_endereco: %d', $audit['no_address_phone']));
        $output->writeln(sprintf('  sem_company_endereco: %d', $audit['no_address_company']));
        $output->writeln(sprintf('  pode_copiar_telefone: %d', $audit['can_copy_phone']));
        $output->writeln(sprintf('  pode_copiar_razao_endereco: %d', $audit['can_copy_razao_from_address']));
        $output->writeln(sprintf('  pode_backfill_oc_legacy: %d', $audit['can_backfill_razao_from_oc_legacy'] ?? 0));
        $output->writeln(sprintf('  legacy_unknown: %d', $audit['legacy_unknown']));
        $output->writeln(sprintf('  erp_status_vazio: %d', $audit['erp_status_empty']));
        $output->writeln(sprintf('  pendentes_backfill: %d', $audit['pending_backfill']));
    }
}
