<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Customer;

use GrupoAwamotos\B2B\Model\Registration\B2bRegistrationGuard;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface as ErpConnectionInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Marca (em b2b_admin_notes) clientes aprovados no site que o Sectra diz nao serem
 * mais cliente ativo. NUNCA bloqueia, NUNCA despromove, NUNCA deleta — ver
 * CustomerErpStatusGuard para a assimetria de risco.
 *
 * Idempotente: um cliente so e marcado uma vez por motivo; rodar de novo nao
 * duplica a nota. Se o cliente voltar a ficar ativo no Sectra, a marcacao
 * existente nao e removida automaticamente (fora do escopo desta v1 — remocao
 * e decisao humana, feita direto no admin).
 */
class CustomerErpStatusFlagService
{
    private const CHUNK = 500;
    private const NOTE_TAG_PREFIX = '[ERP:';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ErpConnectionInterface $erp,
        private readonly LoggerInterface $logger,
        private readonly B2bRegistrationGuard $registrationGuard
    ) {
    }

    /**
     * @return array{
     *   skipped:bool, skip_reason:?string, total_checked:int, candidates:int,
     *   flagged:int, already_flagged:int, excluded_test_qa:int, over_limit:bool,
     *   max_ratio:float, by_reason:array<string,int>, sample:list<array{entity_id:int,email:string,erp_code:string,reason:string}>
     * }
     */
    public function run(bool $dryRun, float $maxRatio): array
    {
        $result = [
            'skipped' => true,
            'skip_reason' => null,
            'total_checked' => 0,
            'candidates' => 0,
            'flagged' => 0,
            'already_flagged' => 0,
            'excluded_test_qa' => 0,
            'over_limit' => false,
            'max_ratio' => $maxRatio,
            'by_reason' => [],
            'sample' => [],
        ];

        if (!$this->erp->hasAvailableDriver()) {
            $result['skip_reason'] = 'erp_driver_unavailable';
            $this->logger->warning('[B2B ErpStatusFlag] ERP driver unavailable, skipping');
            return $result;
        }

        $db = $this->resource->getConnection();
        $erpCodeAttr = $this->customerAttrId('erp_code');
        $approvalAttr = $this->customerAttrId('b2b_approval_status');
        $notesAttr = $this->customerAttrId('b2b_admin_notes');

        if ($erpCodeAttr <= 0 || $approvalAttr <= 0 || $notesAttr <= 0) {
            $result['skip_reason'] = 'attributes_not_found';
            $this->logger->error('[B2B ErpStatusFlag] erp_code/b2b_approval_status/b2b_admin_notes attribute missing');
            return $result;
        }

        $varchar = $this->resource->getTableName('customer_entity_varchar');
        $textTable = $this->resource->getTableName('customer_entity_text');

        $customers = $db->fetchAll(
            "SELECT ce.entity_id, ce.email, erp.value AS erp_code, notes.value AS notes
             FROM {$this->resource->getTableName('customer_entity')} ce
             INNER JOIN {$varchar} erp
                ON erp.entity_id = ce.entity_id AND erp.attribute_id = {$erpCodeAttr} AND erp.value <> ''
             INNER JOIN {$varchar} ap
                ON ap.entity_id = ce.entity_id AND ap.attribute_id = {$approvalAttr} AND ap.value = 'approved'
             LEFT JOIN {$textTable} notes
                ON notes.entity_id = ce.entity_id AND notes.attribute_id = {$notesAttr}"
        );

        $result['skipped'] = false;
        $result['total_checked'] = count($customers);

        if ($customers === []) {
            return $result;
        }

        $codes = array_values(array_unique(array_map(static fn(array $c): string => (string) $c['erp_code'], $customers)));
        $erpRows = [];
        foreach (array_chunk($codes, self::CHUNK) as $chunk) {
            try {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                foreach ($this->erp->query(
                    "SELECT CODIGO, CKCLIENTE, ATCLIENTE FROM dbo.FN_FORNECEDORES WHERE CODIGO IN ({$placeholders})",
                    $chunk
                ) as $row) {
                    $erpRows[(string) $row['CODIGO']] = $row;
                }
            } catch (\Throwable $e) {
                $result['skip_reason'] = 'erp_query_failed';
                $this->logger->error('[B2B ErpStatusFlag] ERP batch query failed: ' . $e->getMessage());
                return $result;
            }
        }

        $candidates = [];
        foreach ($customers as $c) {
            $code = (string) $c['erp_code'];
            $found = isset($erpRows[$code]);
            $ck = $found ? (string) ($erpRows[$code]['CKCLIENTE'] ?? '') : null;
            $at = $found ? (string) ($erpRows[$code]['ATCLIENTE'] ?? '') : null;

            $reason = CustomerErpStatusGuard::reasonToFlag($found, $ck, $at);
            if ($reason === null) {
                continue;
            }

            $entityId = (int) $c['entity_id'];
            $email = (string) $c['email'];
            $notes = (string) ($c['notes'] ?? '');

            if ($this->registrationGuard->isTestOrQaAccount($entityId, $email, $notes)) {
                $result['excluded_test_qa']++;
                continue;
            }

            if ($this->isAlreadyFlagged($notes, $reason)) {
                $result['already_flagged']++;
                continue;
            }

            $result['by_reason'][$reason] = ($result['by_reason'][$reason] ?? 0) + 1;
            $candidates[] = [
                'entity_id' => $entityId,
                'email' => $email,
                'erp_code' => $code,
                'reason' => $reason,
                'notes' => $notes,
            ];
        }

        $result['candidates'] = count($candidates);
        $result['sample'] = array_map(
            static fn(array $c): array => array_diff_key($c, ['notes' => null]),
            array_slice($candidates, 0, 30)
        );
        $result['over_limit'] = !CustomerErpStatusGuard::withinSafetyLimit(
            $result['candidates'],
            $result['total_checked'],
            $maxRatio
        );

        $this->logger->info('[B2B ErpStatusFlag] Evaluated', [
            'dry_run' => $dryRun,
            'total_checked' => $result['total_checked'],
            'candidates' => $result['candidates'],
            'by_reason' => $result['by_reason'],
            'already_flagged' => $result['already_flagged'],
            'excluded_test_qa' => $result['excluded_test_qa'],
            'over_limit' => $result['over_limit'],
        ]);

        if ($candidates === [] || $dryRun) {
            return $result;
        }

        if ($result['over_limit']) {
            $result['skip_reason'] = 'safety_limit_exceeded';
            $this->logger->error('[B2B ErpStatusFlag] Aborted: candidate volume above safety limit', [
                'candidates' => $result['candidates'],
                'total_checked' => $result['total_checked'],
                'max_ratio' => $maxRatio,
            ]);
            return $result;
        }

        $flagged = 0;
        $today = date('Y-m-d');
        foreach ($candidates as $candidate) {
            try {
                $tag = self::NOTE_TAG_PREFIX . $candidate['reason'] . ' ' . $today . '] ';
                $line = $tag . sprintf(
                    'Cliente aprovado no site, mas %s no Sectra (codigo %s). Verificar antes de qualquer acao comercial.',
                    $candidate['reason'] === CustomerErpStatusGuard::REASON_NOT_FOUND
                        ? 'o codigo nao foi encontrado'
                        : 'o cliente esta inativo',
                    $candidate['erp_code']
                );
                $newNotes = $candidate['notes'] !== ''
                    ? $line . "\n" . $candidate['notes']
                    : $line;

                $db->insertOnDuplicate(
                    $this->resource->getTableName('customer_entity_text'),
                    [
                        'attribute_id' => $notesAttr,
                        'store_id' => 0,
                        'entity_id' => $candidate['entity_id'],
                        'value' => $newNotes,
                    ],
                    ['value']
                );

                $flagged++;
                $this->logger->info('[B2B ErpStatusFlag] Customer flagged', [
                    'entity_id' => $candidate['entity_id'],
                    'erp_code' => $candidate['erp_code'],
                    'reason' => $candidate['reason'],
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('[B2B ErpStatusFlag] Failed to flag customer', [
                    'entity_id' => $candidate['entity_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result['flagged'] = $flagged;

        return $result;
    }

    private function isAlreadyFlagged(string $notes, string $reason): bool
    {
        return str_contains($notes, self::NOTE_TAG_PREFIX . $reason);
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
