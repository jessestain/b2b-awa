<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface as ErpConnectionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Auditoria SOMENTE LEITURA: cliente B2B x Sectra x carteira da vendedora.
 *
 * Por que existe (achados de 16/10/2026):
 *  - Nao ha reconciliacao inversa de cliente: quem vira ATCLIENTE != 'S' no Sectra
 *    continua comprando, e o CustomerPriceProvider le FATORPRECO sem filtrar ATCLIENTE.
 *  - SyncAttendantFromErp so corrige a carteira quando VENDPREF tem atendente
 *    correspondente; sem match o cliente fica com a vendedora sorteada (round-robin) em
 *    silencio e so aparece como contador "noMatch" no log.
 *
 * Este comando NAO altera nada: mostra o tamanho de cada problema para decidir a
 * correcao (bloquear? re-atribuir?) com numero na mao.
 */
class AuditErpReadinessCommand extends Command
{
    private const CHUNK = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ErpConnectionInterface $erp,
        private readonly Filesystem $filesystem
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:audit:erp-readiness')
            ->setDescription('Audita cliente x Sectra x vendedora vinculada (somente leitura)')
            ->addOption('no-csv', null, InputOption::VALUE_NONE, 'Nao grava CSV, so mostra o resumo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->erp->hasAvailableDriver()) {
            $output->writeln('<error>Driver do Sectra indisponivel; auditoria nao pode comparar com o ERP.</error>');
            return Command::FAILURE;
        }

        $db = $this->resource->getConnection();
        $erpCodeAttr = $this->customerAttrId('erp_code');
        $approvalAttr = $this->customerAttrId('b2b_approval_status');
        if ($erpCodeAttr <= 0 || $approvalAttr <= 0) {
            $output->writeln('<error>Atributos erp_code / b2b_approval_status nao encontrados.</error>');
            return Command::FAILURE;
        }

        $attendantsTable = $this->resource->getTableName('grupoawamotos_b2b_attendants');
        $mapTable = $this->resource->getTableName('grupoawamotos_b2b_customer_attendant');
        $varchar = $this->resource->getTableName('customer_entity_varchar');

        // Atendentes
        $attendants = $db->fetchAll("SELECT attendant_id, name, is_active, erp_seller_code FROM {$attendantsTable}");
        $activeBySeller = [];
        $attendantById = [];
        $withoutSellerCode = [];
        foreach ($attendants as $a) {
            $attendantById[(int) $a['attendant_id']] = $a;
            if ((int) $a['is_active'] === 1) {
                if ($a['erp_seller_code'] === null) {
                    $withoutSellerCode[] = $a['name'];
                } else {
                    $activeBySeller[(int) $a['erp_seller_code']] = (int) $a['attendant_id'];
                }
            }
        }

        // Clientes com erp_code
        $customers = $db->fetchAll(
            "SELECT ce.entity_id, ce.email, erp.value AS erp_code, ap.value AS approval, ca.attendant_id
             FROM {$this->resource->getTableName('customer_entity')} ce
             INNER JOIN {$varchar} erp
                ON erp.entity_id = ce.entity_id AND erp.attribute_id = {$erpCodeAttr} AND erp.value <> ''
             LEFT JOIN {$varchar} ap
                ON ap.entity_id = ce.entity_id AND ap.attribute_id = {$approvalAttr}
             LEFT JOIN {$mapTable} ca ON ca.customer_id = ce.entity_id"
        );

        $approvedNoErp = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM {$varchar} ap
             WHERE ap.attribute_id = {$approvalAttr} AND ap.value = 'approved'
               AND NOT EXISTS (
                   SELECT 1 FROM {$varchar} erp
                   WHERE erp.entity_id = ap.entity_id AND erp.attribute_id = {$erpCodeAttr} AND erp.value <> ''
               )"
        );

        // Sectra, em blocos
        $erpRows = [];
        $queryErrors = 0;
        $codes = array_values(array_unique(array_map(static fn (array $c): string => (string) $c['erp_code'], $customers)));
        foreach (array_chunk($codes, self::CHUNK) as $chunk) {
            try {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = $this->erp->query(
                    "SELECT CODIGO, CKCLIENTE, ATCLIENTE, VENDPREF FROM dbo.FN_FORNECEDORES WHERE CODIGO IN ({$placeholders})",
                    $chunk
                );
                foreach ($rows as $row) {
                    $erpRows[(string) $row['CODIGO']] = $row;
                }
            } catch (\Throwable $e) {
                $queryErrors++;
                $output->writeln('<error>Falha consultando bloco do Sectra: ' . $e->getMessage() . '</error>');
            }
        }

        if ($queryErrors > 0) {
            $output->writeln('<error>Resultado incompleto (' . $queryErrors . ' bloco(s) falharam); nao confie nos totais.</error>');
        }

        // Vendedores validos no Sectra
        $erpSellers = [];
        try {
            foreach ($this->erp->query('SELECT CODIGO FROM dbo.FN_VENDEDORES WHERE CODIGO > 0') as $row) {
                $erpSellers[(int) $row['CODIGO']] = true;
            }
        } catch (\Throwable $e) {
            $output->writeln('<comment>FN_VENDEDORES indisponivel: ' . $e->getMessage() . '</comment>');
        }

        $counts = [
            'erp_not_found' => 0,
            'erp_not_client' => 0,
            'erp_inactive' => 0,
            'no_vendpref' => 0,
            'vendpref_without_attendant' => 0,
            'attendant_diverges' => 0,
            'no_attendant' => 0,
        ];
        $approvedBlockedInErp = 0;
        $issues = [];

        foreach ($customers as $c) {
            $code = (string) $c['erp_code'];
            $approved = $c['approval'] === 'approved';
            $current = $c['attendant_id'] !== null ? (int) $c['attendant_id'] : null;
            $problems = [];
            $vendPref = null;
            $expected = null;

            if ($queryErrors === 0 || isset($erpRows[$code])) {
                if (!isset($erpRows[$code])) {
                    $problems[] = 'erp_not_found';
                } else {
                    $row = $erpRows[$code];
                    $vendPref = (int) ($row['VENDPREF'] ?? 0);
                    if (($row['CKCLIENTE'] ?? 'N') !== 'S') {
                        $problems[] = 'erp_not_client';
                    }
                    if (($row['ATCLIENTE'] ?? 'N') !== 'S') {
                        $problems[] = 'erp_inactive';
                    }
                    if ($vendPref <= 0) {
                        $problems[] = 'no_vendpref';
                    } elseif (!isset($activeBySeller[$vendPref])) {
                        $problems[] = 'vendpref_without_attendant';
                    } else {
                        $expected = $activeBySeller[$vendPref];
                        if ($current !== null && $current !== $expected) {
                            $problems[] = 'attendant_diverges';
                        }
                    }
                }
            }
            if ($current === null) {
                $problems[] = 'no_attendant';
            }

            if ($problems === []) {
                continue;
            }
            foreach ($problems as $p) {
                $counts[$p]++;
            }
            if ($approved && array_intersect($problems, ['erp_not_found', 'erp_not_client', 'erp_inactive'])) {
                $approvedBlockedInErp++;
            }
            $issues[] = [
                (int) $c['entity_id'],
                (string) $c['email'],
                $code,
                (string) $c['approval'],
                implode(', ', $problems),
                $vendPref,
                $current !== null ? ($attendantById[$current]['name'] ?? ('#' . $current)) : '',
                $expected !== null ? ($attendantById[$expected]['name'] ?? ('#' . $expected)) : '',
            ];
        }

        // Atendentes com codigo de vendedor que nao existe no Sectra
        $sellerNotInErp = [];
        if ($erpSellers !== []) {
            foreach ($attendants as $a) {
                if ($a['erp_seller_code'] !== null && !isset($erpSellers[(int) $a['erp_seller_code']])) {
                    $sellerNotInErp[] = $a['name'] . ' (' . $a['erp_seller_code'] . ')';
                }
            }
        }

        $output->writeln('<info>Auditoria cliente x Sectra x vendedora (somente leitura)</info>');
        $output->writeln(sprintf('Clientes Magento com erp_code:         <comment>%d</comment>', count($customers)));
        $output->writeln(sprintf('Aprovados SEM erp_code:                <comment>%d</comment> (sem preco de lista do cliente)', $approvedNoErp));
        $output->writeln('');
        $output->writeln('<comment>Cliente x Sectra</comment>');
        $output->writeln(sprintf('  Codigo nao existe no Sectra:         %d', $counts['erp_not_found']));
        $output->writeln(sprintf('  Existe mas CKCLIENTE != S:           %d', $counts['erp_not_client']));
        $output->writeln(sprintf('  Cliente INATIVO (ATCLIENTE != S):    %d', $counts['erp_inactive']));
        $output->writeln(sprintf('  -> aprovados no site e bloqueados no Sectra: <error>%d</error>', $approvedBlockedInErp));
        $output->writeln('');
        $output->writeln('<comment>Carteira da vendedora</comment>');
        $output->writeln(sprintf('  Sem VENDPREF no Sectra:              %d', $counts['no_vendpref']));
        $output->writeln(sprintf('  VENDPREF sem atendente cadastrada:   %d (carteira sorteada em silencio)', $counts['vendpref_without_attendant']));
        $output->writeln(sprintf('  Carteira diverge do Sectra:          %d', $counts['attendant_diverges']));
        $output->writeln(sprintf('  Sem atendente nenhuma:               %d', $counts['no_attendant']));
        $output->writeln('');
        $output->writeln(sprintf('Atendentes ativas SEM erp_seller_code: %d %s', count($withoutSellerCode), $withoutSellerCode ? '(' . implode(', ', $withoutSellerCode) . ')' : ''));
        $output->writeln(sprintf('Atendentes com codigo inexistente no Sectra: %d %s', count($sellerNotInErp), $sellerNotInErp ? '(' . implode(', ', $sellerNotInErp) . ')' : ''));
        $output->writeln('');
        $output->writeln('<comment>Nao exclui contas de teste/QA (B2bRegistrationGuard); revise o CSV antes de agir.</comment>');

        if ($input->getOption('no-csv')) {
            return $queryErrors > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $dir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir->create('export');
        $path = $dir->getAbsolutePath('export/auditoria_clientes_sectra_' . date('Ymd_His') . '.csv');
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $output->writeln('<error>Nao foi possivel criar o CSV: ' . $path . '</error>');
            return Command::FAILURE;
        }
        fputcsv($handle, ['customer_id', 'email', 'erp_code', 'aprovacao', 'problemas', 'vendpref', 'atendente_atual', 'atendente_esperada'], ';', '"', '\\');
        foreach ($issues as $line) {
            fputcsv($handle, $line, ';', '"', '\\');
        }
        fclose($handle);
        $output->writeln('CSV: <info>' . $path . '</info> (' . count($issues) . ' linhas)');

        return $queryErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function customerAttrId(string $code): int
    {
        $db = $this->resource->getConnection();

        return (int) $db->fetchOne(
            $db->select()
                ->from($this->resource->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', $code)
                ->where('entity_type_id = ?', 1)
        );
    }
}
