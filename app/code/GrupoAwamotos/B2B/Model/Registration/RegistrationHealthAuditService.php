<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Registration;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;

/**
 * Continuous health audit for B2B registration data quality (read-only).
 */
class RegistrationHealthAuditService
{
    /** @var array<string, int>|null */
    private ?array $attributeIds = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Filesystem $filesystem,
        private readonly B2bRegistrationGuard $registrationGuard
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function collectSummary(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attrs = $this->resolveAttributeIds();
        $baseFrom = $this->buildCnpjBaseFrom($attrs);
        $addressTable = $this->resourceConnection->getTableName('customer_address_entity');
        $textTable = $this->resourceConnection->getTableName('customer_entity_text');

        $noPhone = $this->countEmptyVarchar($baseFrom, $attrs['b2b_phone']);
        $noRazao = $this->countEmptyVarchar($baseFrom, $attrs['b2b_razao_social']);
        $noErp = $this->countEmptyVarchar($baseFrom, $attrs['erp_customer_sync_status']);
        $noOrigin = $this->countEmptyVarchar($baseFrom, $attrs['b2b_origin_host']);
        $noCampaign = $this->countEmptyVarchar($baseFrom, $attrs['b2b_registration_campaign']);

        $phoneInAddressNotB2b = (int) $connection->fetchOne(
            "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
             LEFT JOIN customer_entity_varchar phone
                ON phone.entity_id = ce.entity_id AND phone.attribute_id = {$attrs['b2b_phone']}
             INNER JOIN {$addressTable} addr
                ON addr.parent_id = ce.entity_id AND addr.telephone IS NOT NULL AND addr.telephone != ''
             WHERE phone.value IS NULL OR phone.value = ''"
        );

        $testQa = $this->countTestQaAccounts($baseFrom, $attrs, $textTable);
        $manualPending = max(0, $noPhone - $testQa);

        $total = (int) $connection->fetchOne("SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}");

        return [
            'total_with_cnpj' => $total,
            'no_b2b_phone' => $noPhone,
            'no_b2b_razao_social' => $noRazao,
            'no_erp_customer_sync_status' => $noErp,
            'no_b2b_origin_host' => $noOrigin,
            'no_b2b_registration_campaign' => $noCampaign,
            'phone_in_address_not_b2b_phone' => $phoneInAddressNotB2b,
            'manual_correction_pending' => $manualPending,
            'test_qa_accounts' => $testQa,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCommercialPendingCustomers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attrs = $this->resolveAttributeIds();
        $textTable = $this->resourceConnection->getTableName('customer_entity_text');
        $addressTable = $this->resourceConnection->getTableName('customer_address_entity');

        $select = $connection->select()
            ->from(['ce' => $this->resourceConnection->getTableName('customer_entity')], [
                'entity_id',
                'email',
            ])
            ->joinInner(
                ['cnpj' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "cnpj.entity_id = ce.entity_id AND cnpj.attribute_id = {$attrs['b2b_cnpj']} AND cnpj.value != ''",
                ['cnpj' => 'value']
            )
            ->joinLeft(
                ['phone' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "phone.entity_id = ce.entity_id AND phone.attribute_id = {$attrs['b2b_phone']}",
                ['b2b_phone' => 'value']
            )
            ->joinLeft(
                ['razao' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "razao.entity_id = ce.entity_id AND razao.attribute_id = {$attrs['b2b_razao_social']}",
                ['razao_social' => 'value']
            )
            ->joinLeft(
                ['erp' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "erp.entity_id = ce.entity_id AND erp.attribute_id = {$attrs['erp_customer_sync_status']}",
                ['status_erp' => 'value']
            )
            ->joinLeft(
                ['notes' => $textTable],
                "notes.entity_id = ce.entity_id AND notes.attribute_id = {$attrs['b2b_admin_notes']}",
                ['admin_notes' => 'value']
            )
            ->where('phone.value IS NULL OR phone.value = \'\'')
            ->order('ce.entity_id ASC');

        $rows = $connection->fetchAll($select);
        $result = [];

        foreach ($rows as $row) {
            $customerId = (int) $row['entity_id'];
            $email = (string) $row['email'];
            $adminNotes = (string) ($row['admin_notes'] ?? '');

            if ($this->registrationGuard->isTestOrQaAccount($customerId, $email, $adminNotes)) {
                continue;
            }

            $problems = ['sem_b2b_phone'];
            $addrPhone = (string) $connection->fetchOne(
                "SELECT telephone FROM {$addressTable} WHERE parent_id = ? AND telephone IS NOT NULL AND telephone != '' LIMIT 1",
                [$customerId]
            );

            if ($addrPhone !== '') {
                $problems[] = 'telefone_no_endereco_sem_b2b_phone';
            } else {
                $problems[] = 'sem_telefone_em_endereco';
            }

            if (trim((string) ($row['razao_social'] ?? '')) === '') {
                $problems[] = 'sem_razao_social';
            }

            $statusErp = (string) ($row['status_erp'] ?? '');
            $acao = $statusErp === 'customer_validated_in_erp'
                ? 'Verificar telefone no ERP/Sectra antes de contato manual'
                : 'Contato manual / validar telefone com cliente';

            $result[] = [
                'entity_id' => $customerId,
                'email' => $email,
                'cnpj' => (string) ($row['cnpj'] ?? ''),
                'razao_social' => (string) ($row['razao_social'] ?? ''),
                'telefone' => (string) ($row['b2b_phone'] ?? ''),
                'status_erp' => $statusErp,
                'problema' => implode(', ', $problems),
                'acao_recomendada' => $acao,
                'observacao' => $addrPhone !== ''
                    ? 'Existe telefone no endereço — revisar sincronização manual.'
                    : 'Sem telefone em b2b_phone e sem endereço com telephone — correção manual.',
            ];
        }

        return $result;
    }

    /**
     * @return array{path: string, rows: int}
     */
    public function exportHealthCsv(string $relativePath): array
    {
        $summary = $this->collectSummary();
        $rows = $this->fetchAllHealthIssues();

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create('export');
        $relativePath = $this->normalizeExportPath($relativePath);
        $absolutePath = $directory->getAbsolutePath($relativePath);

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível criar CSV: ' . $absolutePath);
        }

        fputcsv($handle, ['metrica', 'valor'], ';', '"', '\\');
        foreach ($summary as $metric => $value) {
            fputcsv($handle, [$metric, $value], ';', '"', '\\');
        }

        fputcsv($handle, [], ';', '"', '\\');
        fputcsv($handle, [
            'entity_id', 'email', 'cnpj', 'problema', 'tipo_conta',
        ], ';', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['entity_id'],
                $row['email'],
                $row['cnpj'],
                $row['problema'],
                $row['tipo_conta'],
            ], ';', '"', '\\');
        }

        fclose($handle);

        return ['path' => $absolutePath, 'rows' => count($rows)];
    }

    /**
     * @return array{path: string, rows: int}
     */
    public function exportCommercialPendingCsv(string $relativePath): array
    {
        $rows = $this->fetchCommercialPendingCustomers();
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create('export');
        $relativePath = $this->normalizeExportPath($relativePath);
        $absolutePath = $directory->getAbsolutePath($relativePath);

        $handle = fopen($absolutePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível criar CSV: ' . $absolutePath);
        }

        fputcsv($handle, [
            'entity_id', 'email', 'cnpj', 'razao_social', 'telefone',
            'status_erp', 'problema', 'acao_recomendada', 'observacao',
        ], ';', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['entity_id'],
                $row['email'],
                $row['cnpj'],
                $row['razao_social'],
                $row['telefone'],
                $row['status_erp'],
                $row['problema'],
                $row['acao_recomendada'],
                $row['observacao'],
            ], ';', '"', '\\');
        }

        fclose($handle);

        return ['path' => $absolutePath, 'rows' => count($rows)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllHealthIssues(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attrs = $this->resolveAttributeIds();
        $textTable = $this->resourceConnection->getTableName('customer_entity_text');

        $select = $connection->select()
            ->from(['ce' => $this->resourceConnection->getTableName('customer_entity')], ['entity_id', 'email'])
            ->joinInner(
                ['cnpj' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "cnpj.entity_id = ce.entity_id AND cnpj.attribute_id = {$attrs['b2b_cnpj']} AND cnpj.value != ''",
                ['cnpj' => 'value']
            )
            ->joinLeft(
                ['phone' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "phone.entity_id = ce.entity_id AND phone.attribute_id = {$attrs['b2b_phone']}",
                ['b2b_phone' => 'value']
            )
            ->joinLeft(
                ['razao' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "razao.entity_id = ce.entity_id AND razao.attribute_id = {$attrs['b2b_razao_social']}",
                ['b2b_razao_social' => 'value']
            )
            ->joinLeft(
                ['erp' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "erp.entity_id = ce.entity_id AND erp.attribute_id = {$attrs['erp_customer_sync_status']}",
                ['erp_status' => 'value']
            )
            ->joinLeft(
                ['origin' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "origin.entity_id = ce.entity_id AND origin.attribute_id = {$attrs['b2b_origin_host']}",
                ['b2b_origin_host' => 'value']
            )
            ->joinLeft(
                ['camp' => $this->resourceConnection->getTableName('customer_entity_varchar')],
                "camp.entity_id = ce.entity_id AND camp.attribute_id = {$attrs['b2b_registration_campaign']}",
                ['b2b_registration_campaign' => 'value']
            )
            ->joinLeft(
                ['notes' => $textTable],
                "notes.entity_id = ce.entity_id AND notes.attribute_id = {$attrs['b2b_admin_notes']}",
                ['admin_notes' => 'value']
            )
            ->where(
                '(phone.value IS NULL OR phone.value = \'\')'
                . ' OR (razao.value IS NULL OR razao.value = \'\')'
                . ' OR (erp.value IS NULL OR erp.value = \'\')'
                . ' OR (origin.value IS NULL OR origin.value = \'\')'
                . ' OR (camp.value IS NULL OR camp.value = \'\')'
            )
            ->order('ce.entity_id ASC');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $customerId = (int) $row['entity_id'];
            $email = (string) $row['email'];
            $notes = (string) ($row['admin_notes'] ?? '');
            $isTest = $this->registrationGuard->isTestOrQaAccount($customerId, $email, $notes);

            $issues = [];
            if (trim((string) ($row['b2b_phone'] ?? '')) === '') {
                $issues[] = 'sem_b2b_phone';
            }
            if (trim((string) ($row['b2b_razao_social'] ?? '')) === '') {
                $issues[] = 'sem_razao_social';
            }
            if (trim((string) ($row['erp_status'] ?? '')) === '') {
                $issues[] = 'sem_erp_status';
            }
            if (trim((string) ($row['b2b_origin_host'] ?? '')) === '') {
                $issues[] = 'sem_origin_host';
            }
            if (trim((string) ($row['b2b_registration_campaign'] ?? '')) === '') {
                $issues[] = 'sem_campaign';
            }

            $result[] = [
                'entity_id' => $customerId,
                'email' => $email,
                'cnpj' => (string) ($row['cnpj'] ?? ''),
                'problema' => implode(', ', $issues),
                'tipo_conta' => $isTest ? 'teste_qa' : 'comercial',
            ];
        }

        return $result;
    }

    private function countEmptyVarchar(string $baseFrom, int $attributeId): int
    {
        $connection = $this->resourceConnection->getConnection();

        // "legacy_unknown" e um PLACEHOLDER gravado pelo backfill de atribuicao:
        // ocupa a coluna e NAO e dado. Sem excluir, a auditoria reporta 460 "sem origem"
        // quando o real e ~8.600. Medido em 03/10/2026: o placeholder e 'legacy_unknown' em
        // b2b_origin_host e 'legacy' em b2b_registration_campaign (strings DIFERENTES). Existe APENAS em
        // b2b_origin_host e b2b_registration_campaign (b2b_phone e b2b_razao_social: zero).
        return (int) $connection->fetchOne(
            "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
             LEFT JOIN customer_entity_varchar attr
                ON attr.entity_id = ce.entity_id AND attr.attribute_id = {$attributeId}
             WHERE attr.value IS NULL OR attr.value = '' OR attr.value IN ('legacy_unknown', 'legacy')"
        );
    }

    private function countTestQaAccounts(string $baseFrom, array $attrs, string $textTable): int
    {
        $connection = $this->resourceConnection->getConnection();
        $ids = implode(',', B2bRegistrationGuard::KNOWN_TEST_QA_IDS);

        return (int) $connection->fetchOne(
            "SELECT COUNT(DISTINCT ce.entity_id) {$baseFrom}
             LEFT JOIN customer_entity_varchar phone
                ON phone.entity_id = ce.entity_id AND phone.attribute_id = {$attrs['b2b_phone']}
             LEFT JOIN {$textTable} notes
                ON notes.entity_id = ce.entity_id AND notes.attribute_id = {$attrs['b2b_admin_notes']}
             WHERE (phone.value IS NULL OR phone.value = '')
               AND (
                    ce.entity_id IN ({$ids})
                    OR ce.email LIKE '%qa.b2b.%'
                    OR ce.email LIKE '%@jesssestain.com.br'
                    OR notes.value LIKE '%Conta de teste%'
                    OR notes.value LIKE '%Conta QA%'
               )"
        );
    }

    /**
     * @return array<string, int>
     */
    private function resolveAttributeIds(): array
    {
        if ($this->attributeIds !== null) {
            return $this->attributeIds;
        }

        $connection = $this->resourceConnection->getConnection();
        $codes = [
            'b2b_cnpj', 'b2b_phone', 'b2b_razao_social', 'b2b_origin_host',
            'b2b_registration_campaign', 'erp_customer_sync_status', 'b2b_admin_notes',
        ];

        $this->attributeIds = [];
        foreach ($codes as $code) {
            $this->attributeIds[$code] = (int) $connection->fetchOne(
                "SELECT ea.attribute_id FROM eav_attribute ea
                 INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                 WHERE ea.attribute_code = ? AND et.entity_type_code = 'customer'",
                [$code]
            );
        }

        return $this->attributeIds;
    }

    /**
     * @param array<string, int> $attrs
     */
    private function buildCnpjBaseFrom(array $attrs): string
    {
        return "
            FROM customer_entity ce
            INNER JOIN customer_entity_varchar cnpj
                ON cnpj.entity_id = ce.entity_id
                AND cnpj.attribute_id = {$attrs['b2b_cnpj']}
                AND cnpj.value != ''
        ";
    }

    private function normalizeExportPath(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        return str_starts_with($relativePath, 'export/')
            ? $relativePath
            : 'export/' . basename($relativePath);
    }
}
