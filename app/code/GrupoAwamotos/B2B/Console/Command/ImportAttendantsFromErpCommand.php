<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface as ErpConnectionInterface;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports ERP vendors as B2B attendants.
 *
 * Strategy: find all distinct VENDPREF codes in FN_FORNECEDORES (client table),
 * join back to get the vendor row (same table, different record) for name/contact.
 * Creates new attendants or updates existing ones by erp_seller_code.
 *
 * Usage:
 *   php bin/magento b2b:attendant:import-from-erp
 *   php bin/magento b2b:attendant:import-from-erp --dry-run
 *   php bin/magento b2b:attendant:import-from-erp -d sales -m 300
 */
class ImportAttendantsFromErpCommand extends Command
{
    private ErpConnectionInterface $erpConnection;
    private AttendantManager $attendantManager;
    private State $state;

    public function __construct(
        ErpConnectionInterface $erpConnection,
        AttendantManager $attendantManager,
        State $state,
        ?string $name = null
    ) {
        $this->erpConnection    = $erpConnection;
        $this->attendantManager = $attendantManager;
        $this->state            = $state;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:attendant:import-from-erp')
            ->setDescription('Importa vendedores do ERP Sectra/Crossel como atendentes B2B')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Exibe o que seria importado sem salvar nada')
            ->addOption('department', 'd', InputOption::VALUE_OPTIONAL, 'Departamento padrao para novos atendentes', 'sales')
            ->addOption('max-customers', 'm', InputOption::VALUE_OPTIONAL, 'Limite maximo de clientes por atendente', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area already set — ignore
        }

        $dryRun     = (bool) $input->getOption('dry-run');
        $department = (string) $input->getOption('department');
        $maxCust    = (int) $input->getOption('max-customers');

        if ($dryRun) {
            $output->writeln('<comment>[DRY-RUN] Nenhuma alteracao sera salva.</comment>');
        }

        if (!$this->erpConnection->hasAvailableDriver()) {
            $output->writeln('<error>Driver SQL Server nao disponivel. Instale sqlsrv ou pdo_sqlsrv e configure o modulo ERPIntegration.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Consultando vendedores no ERP (FN_FORNECEDORES via VENDPREF)...</info>');

        try {
            // Find all distinct VENDPREF sellers used by active clients.
            // Join back to FN_FORNECEDORES to get the vendor details (same table).
            $sql = "SELECT"
                 . " v.CODIGO        AS erp_seller_code,"
                 . " v.RAZAO         AS razao,"
                 . " v.FANTASIA      AS fantasia,"
                 . " c.EMAIL         AS email,"
                 . " c.FONE1         AS fone1,"
                 . " c.FONECEL       AS fonecel,"
                 . " COUNT(f.CODIGO) AS total_clientes"
                 . " FROM dbo.FN_FORNECEDORES f"
                 . " INNER JOIN dbo.FN_FORNECEDORES v ON v.CODIGO = f.VENDPREF"
                 . " LEFT  JOIN dbo.FN_CONTATO c ON c.FORNECEDOR = v.CODIGO AND c.PRINCIPAL = 'S'"
                 . " WHERE f.CKCLIENTE = 'S'"
                 . " AND f.VENDPREF IS NOT NULL"
                 . " AND f.VENDPREF > 0"
                 . " GROUP BY v.CODIGO, v.RAZAO, v.FANTASIA, c.EMAIL, c.FONE1, c.FONECEL"
                 . " ORDER BY total_clientes DESC";

            $sellers = $this->erpConnection->query($sql);
        } catch (\Exception $e) {
            $output->writeln('<error>Erro ao consultar ERP: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($sellers)) {
            $output->writeln('<comment>Nenhum vendedor com clientes ativos encontrado no ERP.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d vendedor(es) encontrado(s) no ERP.</info>', count($sellers)));

        // Index existing attendants by erp_seller_code for O(1) lookup
        $existingByCode = [];
        foreach ($this->attendantManager->getActiveAttendants() as $att) {
            if (!empty($att['erp_seller_code'])) {
                $existingByCode[(int) $att['erp_seller_code']] = $att;
            }
        }

        $table = new Table($output);
        $table->setHeaders(['ERP Code', 'Nome', 'Email', 'Telefone', 'Clientes ERP', 'Acao']);

        $created = 0;
        $updated = 0;
        $usedEmails = []; // track within this import to avoid duplicate email collisions

        foreach ($sellers as $seller) {
            $erpCode     = (int) $seller['erp_seller_code'];
            $fantasia    = trim((string) ($seller['fantasia'] ?? ''));
            $razao       = trim((string) ($seller['razao'] ?? ''));
            $name        = $fantasia ?: $razao ?: ('Vendedor ' . $erpCode);
            $rawEmail    = $this->normalizeEmail((string) ($seller['email'] ?? ''));
            $phone       = trim((string) ($seller['fonecel'] ?: $seller['fone1'] ?? ''));

            // Deduplicate emails: if already used by another vendor in this batch,
            // fall back to a unique placeholder so the DB unique constraint is not violated
            if ($rawEmail && isset($usedEmails[$rawEmail])) {
                $email = 'vendedor' . $erpCode . '@awamotos.com.br';
            } else {
                $email = $rawEmail;
                if ($rawEmail) {
                    $usedEmails[$rawEmail] = $erpCode;
                }
            }
            $clientCount = (int) ($seller['total_clientes'] ?? 0);

            if (isset($existingByCode[$erpCode])) {
                $action   = 'ATUALIZAR';
                $existing = $existingByCode[$erpCode];

                if (!$dryRun) {
                    $this->attendantManager->saveAttendant([
                        'attendant_id'      => $existing['attendant_id'],
                        'name'              => $name,
                        'email'             => $email ?: $existing['email'],
                        'phone'             => $phone ?: $existing['phone'],
                        'whatsapp'          => $phone ?: $existing['whatsapp'],
                        'department'        => $existing['department'],
                        'is_active'         => 1,
                        'max_customers'     => $existing['max_customers'],
                        'erp_seller_code'   => (string) $erpCode,
                        'chatwoot_agent_id' => $existing['chatwoot_agent_id'] ?? null,
                    ]);
                }
                $updated++;
            } else {
                $action = 'CRIAR';

                if (!$dryRun) {
                    $this->attendantManager->saveAttendant([
                        'name'            => $name,
                        'email'           => $email ?: ('vendedor' . $erpCode . '@awamotos.com.br'),
                        'phone'           => $phone,
                        'whatsapp'        => $phone,
                        'department'      => $department,
                        'is_active'       => 1,
                        'max_customers'   => $maxCust,
                        'erp_seller_code' => (string) $erpCode,
                    ]);
                }
                $created++;
            }

            $table->addRow([
                $erpCode,
                mb_strimwidth($name, 0, 30, '...'),
                $email ?: '-',
                $phone ?: '-',
                $clientCount,
                $action,
            ]);
        }

        $table->render();

        if (!$dryRun) {
            $output->writeln(sprintf(
                '<info>Concluido: %d atendente(s) criado(s), %d atualizado(s).</info>',
                $created,
                $updated
            ));
            $output->writeln('<comment>Proximo passo: execute "b2b:attendant:manage assign-unassigned" para vincular clientes sem atendente.</comment>');
        } else {
            $output->writeln(sprintf(
                '<info>[DRY-RUN] Seria: %d criado(s), %d atualizado(s). Nada foi salvo.</info>',
                $created,
                $updated
            ));
        }

        return Command::SUCCESS;
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '';
    }
}
