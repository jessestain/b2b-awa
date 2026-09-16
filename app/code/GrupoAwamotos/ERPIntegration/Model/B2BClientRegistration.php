<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Psr\Log\LoggerInterface;

/**
 * Registers B2B clients in Sectra's GR_INTEGRACAOVALIDADOR table.
 *
 * The Sectra "Importar Pedidos AWA" feature validates that a client exists
 * in GR_INTEGRACAOVALIDADOR for the "OpenCardB2B - Cadastro de Cliente" origin
 * before importing a web order. Clients not registered will fail with
 * "Cliente não foi encontrado".
 *
 * This service uses a separate write-enabled connection (configured in admin)
 * to INSERT client registrations. Falls back gracefully when write access
 * is not available.
 */
class B2BClientRegistration
{
    /** OpenCardB2B - Cadastro de Cliente */
    private const ORIGEM_CLIENTE = '7D4C6FBD-62CF-427F-A0ED-3C06602F05D7';

    /** OpenCardB2B - Pré-cadastro de Cliente (Importar Clientes Prospect) */
    private const ORIGEM_PROSPECT = '753ADB36-27F8-4910-84BB-D7E26279C5A8';

    /** OpenCardB2B - Cadastro de Cliente Endereço */
    private const ORIGEM_ENDERECO = 'FEB11981-5319-49EB-9F1E-4BA02BD22B90';

    /** OpenCardB2B - Cadastro de Produto */
    private const ORIGEM_PRODUTO = 'CF672D58-4F70-48D6-91D0-32652F86D217';
    private const WARNING_COOLDOWN_SECONDS = 21600;
    private ConnectionInterface $readConnection;
    private Helper $helper;
    private LoggerInterface $logger;
    private ?\PDO $writeConnection = null;
    private bool $writePermissionChecked = false;
    private bool $writePermissionGranted = true;
    /** @var array<int, bool> */
    private array $erpNativeClientCache = [];

    public function __construct(
        ConnectionInterface $readConnection,
        Helper $helper,
        LoggerInterface $logger
    ) {
        $this->readConnection = $readConnection;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Check if a client is registered in Sectra B2B integration.
     *
     * Accepts either:
     * 1. ORIGEM_CLIENTE (7D4C6FBD) — full "Exportar Clientes" registration
     * 2. ORIGEM_PROSPECT (753ADB36) with CHAVE matching exactly — client was imported
     *    via "Importar Clientes Prospect" and Sectra assigned that CHAVE directly.
     *    When oc_order.customer_id matches this CHAVE, Sectra can locate the client.
     *
     * Note: ORIGEM_PROSPECT via alias (CHAVEEXTERNA match) is NOT sufficient alone,
     * because in that scenario oc_order.customer_id would differ from the CHAVE.
     */
    public function isClientRegistered(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        try {
            $directCadastro = $this->hasValidatorEntry(self::ORIGEM_CLIENTE, $erpClientCode);
            if ($directCadastro) {
                return true;
            }

            $prospectChave = $this->resolveProspectIntegrationChave($erpClientCode);
            $aliasCadastro = $prospectChave !== null
                && $prospectChave !== $erpClientCode
                && $this->hasValidatorEntry(self::ORIGEM_CLIENTE, $prospectChave);

            if ($aliasCadastro) {
                return true;
            }

            // H_PROSPECT_DIRECT: client was imported as prospect and Sectra assigned
            // this exact CHAVE — oc_order.customer_id will match, Sectra can locate client.
            $prospectByChave = $this->hasValidatorEntry(self::ORIGEM_PROSPECT, $erpClientCode);
            if ($prospectByChave) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] Check failed (fail-closed): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Client exists in ERP as an established (non-prospect) customer with valid CNPJ.
     *
     * Used as fallback when GR_INTEGRACAOVALIDADOR entry is absent. The new PS-based
     * order import writes directly to VE_PEDIDO and does not re-validate this table,
     * so the gate adds no safety value for clients already known to the ERP.
     */
    public function isExistingErpNativeClient(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        $lookupCode = $this->resolveErpSupplierLookupCode($erpClientCode);

        try {
            $exists = $this->readConnection->fetchOne(
                "SELECT TOP 1 1 AS found
                 FROM FN_FORNECEDORES
                 WHERE CODIGO = ?
                   AND CKCLIENTE = 'S'
                   AND (CKPROSPECT IS NULL OR CKPROSPECT = 'N' OR CKPROSPECT = '')
                   AND LEN(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                       COALESCE(CGC,''), '.',''),'/',''),'-',''),' ',''),',','')) >= 14",
                [$lookupCode]
            );

            return is_array($exists);
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] ERP native client fallback check failed: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Client already exists as a real ERP customer (not prospect stub) with valid CNPJ.
     *
     * Used when Sectra "Exportar Clientes" cannot run but FN_FORNECEDORES already has
     * the client and prospect is in GR_INTEGRACAOVALIDADOR — sufficient for oc_order release.
     */
    public function isErpNativeB2bClient(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        if (array_key_exists($erpClientCode, $this->erpNativeClientCache)) {
            return $this->erpNativeClientCache[$erpClientCode];
        }

        if (!$this->isClientProspectRegistered($erpClientCode)) {
            return $this->erpNativeClientCache[$erpClientCode] = false;
        }

        $lookupCode = $this->resolveErpSupplierLookupCode($erpClientCode);

        try {
            $row = $this->readConnection->fetchOne(
                "SELECT TOP 1 CGC, CKPROSPECT
                 FROM FN_FORNECEDORES
                 WHERE CODIGO = ? AND CKCLIENTE = 'S'",
                [$lookupCode]
            );

            if (!is_array($row)) {
                return $this->erpNativeClientCache[$erpClientCode] = false;
            }

            $cgc = preg_replace('/\D/', '', trim((string) ($row['CGC'] ?? ''))) ?: '';
            if (strlen($cgc) < 14 || preg_match('/^0+$/', $cgc) === 1) {
                return $this->erpNativeClientCache[$erpClientCode] = false;
            }

            return $this->erpNativeClientCache[$erpClientCode]
                = strtoupper(trim((string) ($row['CKPROSPECT'] ?? 'N'))) === 'N';
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] ERP native client check failed: ' . $e->getMessage());

            return $this->erpNativeClientCache[$erpClientCode] = false;
        }
    }

    /**
     * Full "Cadastro de Cliente" row (ORIGEM_CLIENTE / 7D4C6FBD).
     *
     * Distinct from {@see isClientRegistered()}: prospect-only (753ADB36) is NOT Cadastro.
     * Bridge confirmed + Importar Pedidos require this origin (or alias Cadastro).
     */
    public function hasCadastroCliente(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        return $this->hasValidatorEntry(self::ORIGEM_CLIENTE, $erpClientCode);
    }

    /**
     * Whether Magento may release held B2B orders to oc_order for Sectra Importar Pedidos.
     *
     * Runtime evidence from Sectra desktop: native ERP customers still fail
     * "Cliente não foi encontrado" when the B2B validator origin is missing.
     * Keep this gate aligned with the desktop import contract.
     *
     * Autonomy: when ERP write_connection is enabled, Magento may INSERT Cadastro via
     * {@see registerClient()} (same contract as Sectra "Exportar Clientes") — no desktop click.
     */
    public function isClientReadyForSectraOrderImport(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }
        // STRICT gate: only release B2B orders when a full Cadastro de Cliente
        // entry (7D4C6FBD) exists. Prospect (753ADB36) alone is not enough.
        if ($this->hasCadastroCliente($erpClientCode)) {
            return true;
        }
        // Accept alias: when prospect CHAVE was remapped (e.g. 18771 → 19195)
        $prospectChave = $this->resolveProspectIntegrationChave($erpClientCode);
        return $prospectChave !== null
            && $prospectChave !== $erpClientCode
            && $this->hasCadastroCliente($prospectChave);
    }

    /**
     * Active ERP price-list codes (FATORPRECO) keyed by FN_FORNECEDORES.CODIGO.
     *
     * Used by the OpenCart bridge so oc_customer.customer_group_id matches Sectra.
     *
     * @param list<int> $erpClientCodes
     * @return array<int, int> erpCode => fatorPreco
     */
    public function getActiveFatorPrecoByClientCodes(array $erpClientCodes): array
    {
        $codes = [];
        foreach ($erpClientCodes as $code) {
            $code = (int) $code;
            if ($code > 0) {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return [];
        }

        $map = [];
        try {
            foreach (array_chunk(array_keys($codes), 200) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = $this->readConnection->query(
                    "SELECT f.CODIGO,
                            CASE WHEN fp.CKATIVO = 'S' THEN f.FATORPRECO ELSE NULL END AS FATORPRECO
                       FROM FN_FORNECEDORES f
                       LEFT JOIN VE_FATORPRECO fp ON fp.CODIGO = f.FATORPRECO
                      WHERE f.CKCLIENTE = 'S'
                        AND f.CODIGO IN ({$placeholders})",
                    $chunk
                );
                foreach ($rows as $row) {
                    $erpCode = (int) ($row['CODIGO'] ?? 0);
                    $fator = (int) round((float) ($row['FATORPRECO'] ?? 0));
                    if ($erpCode > 0 && $fator > 0) {
                        $map[$erpCode] = $fator;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[B2BClientRegistration] getActiveFatorPrecoByClientCodes failed: ' . $e->getMessage()
            );
        }

        return $map;
    }

    /**
     * FN_FORNECEDORES.CODIGO for bridge clients whose prospect CHAVE differs (19195 → 18771).
     */
    public function resolveErpSupplierLookupCode(int $erpClientCode): int
    {
        $bridgeKey = $this->resolveBridgeKeyForProspectChave($erpClientCode);

        return ($bridgeKey !== null && $bridgeKey > 0) ? $bridgeKey : $erpClientCode;
    }

    /**
     * ERP CHAVE assigned by Sectra "Importar Clientes Prospect" for a bridge customer_id.
     *
     * Existing ERP clients may get a new CODIGO (CHAVE) while CHAVEEXTERNA keeps the bridge key
     * (e.g. CHAVE=19195, CHAVEEXTERNA=18771).
     */
    public function resolveProspectIntegrationChave(int $bridgeKey): ?int
    {
        if ($bridgeKey <= 0) {
            return null;
        }

        try {
            $chave = $this->readConnection->fetchColumn(
                "SELECT CHAVE
                   FROM GR_INTEGRACAOVALIDADOR
                  WHERE INTEGRACAOORIGEM = :origem
                    AND CHAVEEXTERNA = :ext",
                [
                    ':origem' => self::ORIGEM_PROSPECT,
                    ':ext' => (string) $bridgeKey,
                ]
            );

            if ($chave === false || $chave === null || $chave === '') {
                return null;
            }

            $code = (int) $chave;
            return $code > 0 ? $code : null;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] Prospect CHAVE lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Customer_id Sectra reads during Exportar Clientes.
     *
     * Runtime evidence (2026-06-23): cliente #7 exportou com oc_customer.customer_id=7
     * (FN CKPROSPECT=N). Aliases com prospect 19195 exportavam stub CKPROSPECT=S em vez
     * do nativo 18771 — Exportar Clientes não gravava Cadastro 7D4C6FBD para o bridge.
     */
    public function resolveSectraExportCustomerId(int $bridgeKey): int
    {
        if ($this->isErpNativeB2bClient($bridgeKey)) {
            return $bridgeKey;
        }

        $prospectChave = $this->resolveProspectIntegrationChave($bridgeKey);

        return ($prospectChave !== null && $prospectChave > 0) ? $prospectChave : $bridgeKey;
    }

    /**
     * Check if a client was already imported via Sectra "Importar Clientes Prospect".
     */
    public function isClientProspectRegistered(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        try {
            if ($this->hasValidatorEntry(self::ORIGEM_PROSPECT, $erpClientCode)) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] Prospect check failed (fail-closed): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bridge customer_id (CHAVEEXTERNA) => prospect ERP CHAVE when they differ.
     *
     * @return array<int, int>
     */
    public function getProspectBridgeAliasMap(): array
    {
        try {
            $rows = $this->readConnection->query(
                "SELECT CHAVE, CHAVEEXTERNA FROM GR_INTEGRACAOVALIDADOR WHERE INTEGRACAOORIGEM = :origem",
                [':origem' => self::ORIGEM_PROSPECT]
            );

            $map = [];
            foreach ($rows as $row) {
                $bridgeKey = (int) ($row['CHAVEEXTERNA'] ?? 0);
                $prospectChave = (int) ($row['CHAVE'] ?? 0);
                if ($bridgeKey > 0 && $prospectChave > 0 && $prospectChave !== $bridgeKey) {
                    $map[$bridgeKey] = $prospectChave;
                }
            }

            return $map;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] Prospect alias map failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Bridge customer_id when a prospect row uses a different ERP CHAVE.
     */
    public function resolveBridgeKeyForProspectChave(int $prospectChave): ?int
    {
        if ($prospectChave <= 0) {
            return null;
        }

        foreach ($this->getProspectBridgeAliasMap() as $bridgeKey => $aliasChave) {
            if ($aliasChave === $prospectChave) {
                return $bridgeKey;
            }
        }

        return null;
    }

    /**
     * @return int[]
     */
    public function getProspectRegisteredClientCodes(): array
    {
        try {
            $rows = $this->readConnection->query(
                "SELECT CHAVE, CHAVEEXTERNA FROM GR_INTEGRACAOVALIDADOR WHERE INTEGRACAOORIGEM = :origem",
                [':origem' => self::ORIGEM_PROSPECT]
            );

            return $this->collectNumericValidatorKeys($rows);
        } catch (\Exception $e) {
            if ($this->shouldLogWarningWithCooldown('b2b_prospect_codes_fetch')) {
                $this->logger->warning(
                    '[B2B Registration] Failed to fetch prospect client codes: ' . $e->getMessage()
                );
            }

            return [];
        }
    }

    /**
     * ERP clients that already have sales history (skip Importar Clientes Prospect).
     *
     * @param int[] $candidateCodes
     * @return int[]
     */
    public function getErpClientCodesWithSalesHistory(array $candidateCodes): array
    {
        $candidateCodes = array_values(array_filter(array_map('intval', $candidateCodes), static fn (int $id): bool => $id > 0));
        if ($candidateCodes === []) {
            return [];
        }

        $found = [];

        try {
            foreach (array_chunk($candidateCodes, 200) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = $this->readConnection->query(
                    "SELECT DISTINCT CLIENTE AS codigo
                     FROM VE_PEDIDO
                     WHERE CLIENTE IN ({$placeholders})",
                    $chunk
                );

                foreach ($rows as $row) {
                    $code = (int) ($row['codigo'] ?? 0);
                    if ($code > 0) {
                        $found[] = $code;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] Failed to check ERP sales history: ' . $e->getMessage()
            );
        }

        return $found;
    }

    /**
     * Get all ERP client codes currently registered in Sectra validator.
     *
     * @return int[]
     */
    public function getRegisteredClientCodes(): array
    {
        try {
            $rows = $this->readConnection->query(
                "SELECT CHAVE, CHAVEEXTERNA FROM GR_INTEGRACAOVALIDADOR WHERE INTEGRACAOORIGEM = :origem",
                [':origem' => self::ORIGEM_CLIENTE]
            );

            return $this->collectNumericValidatorKeys($rows);
        } catch (\Exception $e) {
            if ($this->shouldLogWarningWithCooldown('b2b_registered_codes_fetch')) {
                $this->logger->warning('[B2B Registration] Failed to fetch registered client codes: ' . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * @param int[] $candidateProductIds
     * @return int[] Registered Sectra product IDs (CHAVEEXTERNA) from subset
     */
    public function getRegisteredProductIds(array $candidateProductIds): array
    {
        if ($candidateProductIds === []) {
            return [];
        }

        $registered = [];

        try {
            foreach (array_chunk($candidateProductIds, 200) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $params = array_merge(
                    [self::ORIGEM_PRODUTO],
                    array_map(static fn (int $id): string => (string) $id, $chunk)
                );

                $rows = $this->readConnection->query(
                    "SELECT CHAVEEXTERNA
                     FROM GR_INTEGRACAOVALIDADOR
                     WHERE INTEGRACAOORIGEM = ?
                       AND CHAVEEXTERNA IN ({$placeholders})",
                    $params
                );

                foreach ($rows as $row) {
                    $id = (int) ($row['CHAVEEXTERNA'] ?? 0);
                    if ($id > 0) {
                        $registered[] = $id;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] Product validator check failed: ' . $e->getMessage());
        }

        return $registered;
    }

    /**
     * FN_FORNECEDORES has this CODIGO as a real customer (CKCLIENTE=S).
     */
    private function erpSupplierExistsAsCliente(int $erpClientCode): bool
    {
        $lookupCode = $this->resolveErpSupplierLookupCode($erpClientCode);
        if ($lookupCode <= 0) {
            return false;
        }

        try {
            $found = $this->readConnection->fetchColumn(
                "SELECT TOP 1 CODIGO FROM FN_FORNECEDORES WHERE CODIGO = ? AND CKCLIENTE = 'S'",
                [$lookupCode]
            );

            return $found !== false && $found !== null && (int) $found > 0;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] FN_FORNECEDORES lookup failed for ' . $erpClientCode . ': ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Register a client Cadastro (ORIGEM_CLIENTE) in GR_INTEGRACAOVALIDADOR.
     *
     * Autonomous alternative to Sectra desktop "Exportar Clientes" when write_connection
     * is enabled. Prospect-only rows must NOT short-circuit this method — Importar Pedidos
     * and oc_customer_b2b_confirmed require Cadastro (7D4C6FBD).
     *
     * Safety: refuses codes absent from FN_FORNECEDORES (CKCLIENTE=S).
     *
     * @return bool True if Cadastro exists or was inserted successfully
     */
    public function registerClient(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        // Only Cadastro counts as done — prospect-only must still insert 7D4C6FBD.
        if ($this->hasCadastroCliente($erpClientCode)) {
            $this->logger->info("[B2B Registration] Client $erpClientCode already has Cadastro de Cliente");
            return true;
        }

        if (!$this->erpSupplierExistsAsCliente($erpClientCode)) {
            $this->logger->warning(
                "[B2B Registration] Refusing Cadastro for $erpClientCode — not CKCLIENTE in FN_FORNECEDORES"
            );
            return false;
        }

        $pdo = $this->getWriteConnection();
        if (!$pdo) {
            $this->logger->info('[B2B Registration] Write connection not available — skipping auto-registration for client ' . $erpClientCode);
            return false;
        }

        try {
            $validadorHash = $this->getClientValidatorHash($erpClientCode);

            // 1. Register client
            $stmt = $pdo->prepare(
                "INSERT INTO GR_INTEGRACAOVALIDADOR
                 (INTEGRACAOORIGEM, CHAVE, VALIDADOR, CHAVEEXTERNA, DTSINCRONIZACAO)
                 VALUES (:origem, :chave, :validador, :ext, GETDATE())"
            );
            $stmt->execute([
                ':origem' => self::ORIGEM_CLIENTE,
                ':chave' => (string) $erpClientCode,
                ':validador' => $validadorHash,
                ':ext' => (string) $erpClientCode,
            ]);

            // 2. Register address
            $enderecoHash = $this->getAddressValidatorHash($erpClientCode);

            // Get next CHAVEEXTERNA for address
            $stmtMax = $pdo->prepare(
                "SELECT MAX(CAST(CHAVEEXTERNA AS INT))
                 FROM GR_INTEGRACAOVALIDADOR WHERE INTEGRACAOORIGEM = :origem"
            );
            $stmtMax->execute([':origem' => self::ORIGEM_ENDERECO]);
            $maxExt = (int) $stmtMax->fetchColumn();
            $nextExt = $maxExt > 0 ? $maxExt + 1 : 20000;

            $stmt2 = $pdo->prepare(
                "INSERT INTO GR_INTEGRACAOVALIDADOR
                 (INTEGRACAOORIGEM, CHAVE, VALIDADOR, CHAVEEXTERNA, DTSINCRONIZACAO)
                 VALUES (:origem, :chave, :validador, :ext, GETDATE())"
            );
            $stmt2->execute([
                ':origem' => self::ORIGEM_ENDERECO,
                ':chave' => $erpClientCode . ';1',
                ':validador' => $enderecoHash,
                ':ext' => (string) $nextExt,
            ]);

            $this->logger->info("[B2B Registration] Client $erpClientCode registered successfully");
            return true;
        } catch (\Exception $e) {
            if ($this->isInsertPermissionDeniedError($e)) {
                $this->writePermissionChecked = true;
                $this->writePermissionGranted = false;
                $this->writeConnection = null;
                $this->logger->warning(
                    '[B2B Registration] INSERT permission denied on GR_INTEGRACAOVALIDADOR. '
                    . 'Automatic registration disabled for current process.'
                );
                return false;
            }

            $this->logger->error('[B2B Registration] Failed to register client ' . $erpClientCode . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create FN_FORNECEDORES prospect stub (CKPROSPECT=S) — Magento-side equivalent of
     * Sectra "Importar Clientes Prospect". Requires write_connection INSERT on FN_FORNECEDORES.
     *
     * Does not invent a full fiscal master: uses the same minimal defaults Sectra writes
     * for new prospects (FATORPRECO=1, endereço placeholder when absent).
     *
     * @param array{
     *     razao?: string,
     *     fantasia?: string,
     *     ie?: string,
     *     endereco?: string,
     *     numero?: string,
     *     bairro?: string,
     *     cidade?: string,
     *     cep?: string,
     *     uf?: string
     * } $profile
     * @return int|null New FN_FORNECEDORES.CODIGO or null on failure
     */
    public function createErpProspectStub(string $cnpjDigits, array $profile = []): ?int
    {
        $cnpjDigits = preg_replace('/\D+/', '', $cnpjDigits) ?? '';
        if (strlen($cnpjDigits) !== 14) {
            $this->logger->warning('[B2B Registration] createErpProspectStub refused — CNPJ must have 14 digits');
            return null;
        }

        $existing = $this->findErpCodigoByCnpjDigits($cnpjDigits);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = $this->getWriteConnection();
        if (!$pdo) {
            $this->logger->warning('[B2B Registration] createErpProspectStub — write connection unavailable');
            return null;
        }

        $razao = $this->truncateErpString((string) ($profile['razao'] ?? ('CNPJ ' . $cnpjDigits)), 60);
        $fantasia = $this->truncateErpString((string) ($profile['fantasia'] ?? $razao), 60);
        $ie = $this->truncateErpString((string) ($profile['ie'] ?? 'ISENTO'), 20);
        if ($ie === '') {
            $ie = 'ISENTO';
        }
        $endereco = $this->truncateErpString((string) ($profile['endereco'] ?? '.'), 60) ?: '.';
        $numero = $this->truncateErpString((string) ($profile['numero'] ?? '.'), 10) ?: '.';
        $bairro = $this->truncateErpString((string) ($profile['bairro'] ?? '.'), 40) ?: '.';
        $cidade = $this->truncateErpString((string) ($profile['cidade'] ?? 'ARARAQUARA'), 40) ?: 'ARARAQUARA';
        $cep = $this->formatCep((string) ($profile['cep'] ?? ''));
        $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($profile['uf'] ?? 'SP')) ?: 'SP', 0, 2));
        $cgcMasked = $this->formatCnpjMask($cnpjDigits);

        try {
            $pdo->beginTransaction();
            $maxStmt = $pdo->query('SELECT MAX(CODIGO) FROM FN_FORNECEDORES WITH (UPDLOCK, HOLDLOCK)');
            $nextCode = ((int) $maxStmt->fetchColumn()) + 1;
            if ($nextCode <= 0) {
                $pdo->rollBack();
                return null;
            }

            $sql = "INSERT INTO FN_FORNECEDORES (
                CODIGO,DTCADASTRO,CKFORNECEDOR,CKTRANSPORTADOR,CKCLIENTE,CKINDUSTRIA,
                ATFORNECEDOR,ATTRANSPORTADOR,ATCLIENTE,STFORNECEDOR,STTRANSPORTADOR,STCLIENTE,
                CKPESSOA,RAZAO,FANTASIA,CGC,INSCEST,ENDERECO,NUMERO,BAIRRO,CIDADE,CEP,UF,
                FATORPRECO,CKETIQUETACLI,CKETIQUETAFOR,CKETIQUETATRA,CKLISTACLI,CKLISTAFOR,CKLISTATRA,
                DIASMENTFORN,CKSUFRAMA,CKIMPRODUTIVO,CKPRODUTOR,
                CKAVALIACAO1,CKAVALIACAO2,CKAVALIACAO3,CKAVALIACAO4,CKAVALIACAO5,CKAVALIACAO6,CKAVALIACAO7,CKAVALIACAO8,
                CKASSISTENCIA,FILIAL,CATEGORIAFOR,CATEGORIATRA,CATEGORIACLI,EXIGETERCEIRO,PAIS,VLRLIMCREDITO,
                CKRETERISS,PERCISS,CKCONSUMIDOR,CKSEGURO,REGIMETRIBUTARIO,SCCLIENTE,SCFORNECEDOR,SCTRANSPORTADOR,
                CKTPESTOQUE,DESONERACAO,PERCCONTRIBINSS,CKCONTRIBINSS,REGIMEAPURACAO,PONTUACAO,CKCONTRIBUINTE,
                CKPROSPECT,ATPROSPECT,CKRESTRITOCLI,CKFLUXO,CKRESTRITOFOR,TPFATOR,PERCFATOR,
                CKCODIGOBARRAXML,CKCODIGOBARRADANFE,PERCPISSERVICO,PERCPISPRODUTO,PERCCOFINSSERVICO,PERCCOFINSPRODUTO,
                QTDEITENSXML,PERCCOMISSAO,TPFATORCOMPRA,PERCFATORCOMPRA,CKFATOR,CKQUALIDADECLI,CKQUALIDADEFOR,CKQUALIDADETRA,
                INSERTNAMECLI,INSERTDATECLI,USUARIOCLI,DTALTERACAOCLI,MUNICIPIO,ENTTPPESSOA
            ) VALUES (
                :codigo, CAST(GETDATE() AS DATE),'N','N','S','N',
                'N','N','S','L','L','L',
                'J',:razao,:fantasia,:cgc,:ie,:endereco,:numero,:bairro,:cidade,:cep,:uf,
                1,'S','S','S','S','S','S',
                0,'N','N','N',
                'N','N','N','N','N','N','N','N',
                'N',1,0,0,0,'N',1058,0,
                'N',0,'N','N',0,'L','L','L',
                'N',0,0,'N',0,0,'N',
                'S','S','N','S','N','V',1,
                'N','N',0,0,0,0,
                0,-1,'N',0,'N','L','L','L',
                'MAGENTO_AWA',CAST(GETDATE() AS DATE),'MAGENTO_AWA',GETDATE(),3503208,'J'
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo' => $nextCode,
                ':razao' => $razao,
                ':fantasia' => $fantasia,
                ':cgc' => $cgcMasked,
                ':ie' => $ie,
                ':endereco' => $endereco,
                ':numero' => $numero,
                ':bairro' => $bairro,
                ':cidade' => $cidade,
                ':cep' => $cep,
                ':uf' => $uf,
            ]);
            $pdo->commit();

            $this->logger->info(sprintf(
                '[B2B Registration] Created ERP prospect stub CODIGO=%d CNPJ=%s',
                $nextCode,
                $cnpjDigits
            ));

            return $nextCode;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logger->error('[B2B Registration] createErpProspectStub failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Promote a Magento-created ERP prospect to a native client.
     *
     * Only rows owned by MAGENTO_AWA are eligible. Prospects created manually
     * in Sectra are deliberately left untouched.
     */
    public function promoteMagentoProspectToNativeClient(int $erpClientCode): bool
    {
        if ($erpClientCode <= 0) {
            return false;
        }

        try {
            $row = $this->readConnection->fetchOne(
                "SELECT CKCLIENTE, CKPROSPECT, ATPROSPECT, INSERTNAMECLI
                   FROM FN_FORNECEDORES
                  WHERE CODIGO = ?",
                [$erpClientCode]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] Native promotion lookup failed: ' . $e->getMessage()
            );
            return false;
        }

        if (!is_array($row) || strtoupper(trim((string) ($row['CKCLIENTE'] ?? ''))) !== 'S') {
            return false;
        }

        $isProspect = strtoupper(trim((string) ($row['CKPROSPECT'] ?? ''))) === 'S'
            || strtoupper(trim((string) ($row['ATPROSPECT'] ?? ''))) === 'S';
        $ownedByMagento = strtoupper(trim((string) ($row['INSERTNAMECLI'] ?? ''))) === 'MAGENTO_AWA';
        if (!$ownedByMagento) {
            if (!$isProspect) {
                return true;
            }
            $this->logger->warning(sprintf(
                '[B2B Registration] Refusing to promote ERP prospect %d — not owned by MAGENTO_AWA',
                $erpClientCode
            ));
            return false;
        }

        $pdo = $this->getWriteConnection();
        if (!$pdo) {
            return false;
        }

        try {
            if ($isProspect) {
                $statement = $pdo->prepare(
                    "UPDATE FN_FORNECEDORES
                        SET CKPROSPECT = 'N',
                            ATPROSPECT = 'N',
                            USUARIOCLI = 'MAGENTO_AWA',
                            DTALTERACAOCLI = GETDATE()
                      WHERE CODIGO = :codigo
                        AND CKCLIENTE = 'S'
                        AND CKPROSPECT = 'S'
                        AND INSERTNAMECLI = 'MAGENTO_AWA'"
                );
                $statement->execute([':codigo' => $erpClientCode]);

                if ($statement->rowCount() !== 1) {
                    $this->logger->warning(sprintf(
                        '[B2B Registration] ERP prospect %d was not promoted — guarded UPDATE matched no row',
                        $erpClientCode
                    ));
                    return false;
                }
            }

            $promoted = $this->isExistingErpNativeClient($erpClientCode);
            $companyRowsReady = $this->ensureClientCompanyRows($pdo, $erpClientCode);
            $this->logger->info(sprintf(
                '[B2B Registration] ERP prospect %d native promotion: %s; company rows: %s',
                $erpClientCode,
                $promoted ? 'success' : 'verification failed',
                $companyRowsReady ? 'complete' : 'incomplete'
            ));
            return $promoted && $companyRowsReady;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '[B2B Registration] Failed to promote ERP prospect %d: %s',
                $erpClientCode,
                $e->getMessage()
            ));
            return false;
        }
    }

    public function getClientCompanyRowCount(int $erpClientCode): int
    {
        if ($erpClientCode <= 0) {
            return 0;
        }

        try {
            return (int) $this->readConnection->fetchColumn(
                'SELECT COUNT(*) FROM FN_FORNECEDORESCOMP WHERE FORNECEDOR = ?',
                [$erpClientCode]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] Client company row count failed: ' . $e->getMessage()
            );
            return 0;
        }
    }

    public function getExpectedClientCompanyRowCount(): int
    {
        try {
            return (int) $this->readConnection->fetchColumn('SELECT COUNT(*) FROM GR_FILIAL');
        } catch (\Exception $e) {
            $this->logger->warning(
                '[B2B Registration] Expected company row count failed: ' . $e->getMessage()
            );
            return 0;
        }
    }

    private function ensureClientCompanyRows(\PDO $pdo, int $erpClientCode): bool
    {
        $statement = $pdo->prepare(
            'INSERT INTO FN_FORNECEDORESCOMP (FORNECEDOR, FILIAL, PERCFRETE)
             SELECT ?, filial.CODIGO, 0
               FROM GR_FILIAL filial
              WHERE NOT EXISTS (
                    SELECT 1
                      FROM FN_FORNECEDORESCOMP existing
                     WHERE existing.FORNECEDOR = ?
                       AND existing.FILIAL = filial.CODIGO
              )'
        );
        $statement->execute([$erpClientCode, $erpClientCode]);

        $expected = $this->getExpectedClientCompanyRowCount();
        return $expected > 0 && $this->getClientCompanyRowCount($erpClientCode) === $expected;
    }

    /**
     * @return int|null FN_FORNECEDORES.CODIGO when CNPJ already exists as CKCLIENTE
     */
    public function findErpCodigoByCnpjDigits(string $cnpjDigits): ?int
    {
        $cnpjDigits = preg_replace('/\D+/', '', $cnpjDigits) ?? '';
        if ($cnpjDigits === '') {
            return null;
        }

        try {
            $row = $this->readConnection->fetchOne(
                "SELECT TOP 1 CODIGO
                   FROM FN_FORNECEDORES
                  WHERE CKCLIENTE = 'S'
                    AND REPLACE(REPLACE(REPLACE(ISNULL(CGC,''),'.',''),'/',''),'-','') = ?
                  ORDER BY CASE WHEN CKPROSPECT = 'N' THEN 0 ELSE 1 END, CODIGO",
                [$cnpjDigits]
            );
            if (!is_array($row)) {
                return null;
            }
            $code = (int) ($row['CODIGO'] ?? 0);
            return $code > 0 ? $code : null;
        } catch (\Exception $e) {
            $this->logger->warning('[B2B Registration] findErpCodigoByCnpjDigits failed: ' . $e->getMessage());
            return null;
        }
    }

    private function formatCnpjMask(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if (strlen($digits) !== 14) {
            return $digits;
        }

        return substr($digits, 0, 2) . '.'
            . substr($digits, 2, 3) . '.'
            . substr($digits, 5, 3) . '/'
            . substr($digits, 8, 4) . '-'
            . substr($digits, 12, 2);
    }

    private function formatCep(string $cep): string
    {
        $digits = preg_replace('/\D+/', '', $cep) ?? '';
        if (strlen($digits) === 8) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
        }

        return '00000-000';
    }

    private function truncateErpString(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    /**
     * Register multiple clients in bulk.
     *
     * Tries write access first; for each failure logs details and continues.
     * Returns a summary with counts of registered, already_registered, and failed.
     *
     * @param int[] $erpClientCodes
     * @return array{registered: int, already_registered: int, failed: int, no_write_access: bool}
     */
    public function registerBulk(array $erpClientCodes): array
    {
        $result = [
            'registered' => 0,
            'already_registered' => 0,
            'failed' => 0,
            'no_write_access' => false,
        ];

        if (empty($erpClientCodes)) {
            return $result;
        }

        if (!$this->hasWriteAccess()) {
            $result['no_write_access'] = true;
            $result['failed'] = count(array_unique($erpClientCodes));
            return $result;
        }

        // Pre-fetch registered codes in one query for efficiency
        $registeredCodes = array_fill_keys($this->getRegisteredClientCodes(), true);

        foreach (array_unique($erpClientCodes) as $code) {
            $code = (int) $code;
            if ($code <= 0) {
                continue;
            }

            if (isset($registeredCodes[$code])) {
                $result['already_registered']++;
                continue;
            }

            if ($this->registerClient($code)) {
                $result['registered']++;
                $registeredCodes[$code] = true; // avoid double registration in same batch
            } else {
                $result['failed']++;
                if (!$this->hasWriteAccess()) {
                    // Write access was lost mid-batch (e.g. permission denied error)
                    $result['no_write_access'] = true;
                    $result['failed'] += count(array_unique($erpClientCodes)) - $result['registered']
                        - $result['already_registered'] - $result['failed'];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Get list of unregistered clients from pending Magento orders
     *
     * @return array<int, array{erp_code: int, razao: string, cgc: string}>
     */
    public function getUnregisteredClients(array $erpClientCodes): array
    {
        $unregistered = [];

        foreach ($erpClientCodes as $code) {
            $code = (int) $code;
            // Missing Cadastro (not merely prospect) → still needs registration SQL / auto-insert.
            if ($code <= 0 || $this->hasCadastroCliente($code)) {
                continue;
            }

            try {
                $cli = $this->readConnection->fetchOne(
                    "SELECT RAZAO, CGC FROM FN_FORNECEDORES WHERE CODIGO = :cod AND CKCLIENTE = 'S'",
                    [':cod' => $code]
                );
                if ($cli) {
                    $unregistered[] = [
                        'erp_code' => $code,
                        'razao' => trim($cli['RAZAO'] ?? ''),
                        'cgc' => trim($cli['CGC'] ?? ''),
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->warning('[B2BClientRegistration::getUnregisteredClients] Exceção ignorada: ' . $e->getMessage());
                // Skip this client
            }
        }

        return $unregistered;
    }

    /**
     * Generate SQL INSERT statements for manual execution by DBA
     */
    public function generateRegistrationSQL(array $erpClientCodes): string
    {
        $unregistered = $this->getUnregisteredClients($erpClientCodes);

        if (empty($unregistered)) {
            return "-- Todos os clientes ja estao registrados no validador Sectra.\n";
        }

        $sql = "-- SQL gerado automaticamente pelo Magento B2B\n";
        $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Executar no SQL Server Management Studio com permissao de escrita\n\n";
        $sql .= "BEGIN TRANSACTION;\n\n";

        // Get next address CHAVEEXTERNA
        try {
            $maxExt = (int) $this->readConnection->fetchColumn(
                "SELECT MAX(CAST(CHAVEEXTERNA AS INT)) FROM GR_INTEGRACAOVALIDADOR WHERE INTEGRACAOORIGEM = :o",
                [':o' => self::ORIGEM_ENDERECO]
            );
        } catch (\Exception $e) {
            $maxExt = 19999;
        }
        $nextExt = $maxExt + 1;

        foreach ($unregistered as $c) {
            $code = $c['erp_code'];
            $hash = $this->getClientValidatorHash($code);
            $hashEnd = $this->getAddressValidatorHash($code);

            $sql .= "-- Cliente: " . $c['razao'] . " (CNPJ: " . $c['cgc'] . ")\n";
            $sql .= "INSERT INTO GR_INTEGRACAOVALIDADOR (INTEGRACAOORIGEM, CHAVE, VALIDADOR, CHAVEEXTERNA, DTSINCRONIZACAO)\n";
            $sql .= "VALUES ('" . self::ORIGEM_CLIENTE . "', '$code', '$hash', '$code', GETDATE());\n";
            $sql .= "INSERT INTO GR_INTEGRACAOVALIDADOR (INTEGRACAOORIGEM, CHAVE, VALIDADOR, CHAVEEXTERNA, DTSINCRONIZACAO)\n";
            $sql .= "VALUES ('" . self::ORIGEM_ENDERECO . "', '$code;1', '$hashEnd', '$nextExt', GETDATE());\n\n";
            $nextExt++;
        }

        $sql .= "COMMIT;\n";
        return $sql;
    }

    /**
     * Build the Sectra validator hash for a B2B client.
     */
    public function getClientValidatorHash(int $erpClientCode): string
    {
        return $this->buildValidatorHash([
            'CODIGO' => $erpClientCode,
            'source' => 'magento_b2b',
        ]);
    }

    /**
     * Build the Sectra validator hash for the default B2B address.
     */
    public function getAddressValidatorHash(int $erpClientCode): string
    {
        return $this->buildValidatorHash([
            'CODIGO' => $erpClientCode,
            'ENDERECO' => 1,
            'source' => 'magento_b2b',
        ]);
    }

    /**
     * Check if write connection is available
     */
    public function hasWriteAccess(): bool
    {
        return $this->getWriteConnection() !== null;
    }

    /**
     * Get or create write-enabled PDO connection
     */
    private function getWriteConnection(): ?\PDO
    {
        if ($this->writePermissionChecked && !$this->writePermissionGranted) {
            return null;
        }

        if ($this->writeConnection !== null) {
            return $this->writeConnection;
        }

        if (!$this->helper->isWriteConnectionEnabled()) {
            return null;
        }

        $writeUser = $this->helper->getWriteUsername();
        $writePass = $this->helper->getWritePassword();
        $readUser = $this->helper->getUsername();

        if (empty($writeUser)) {
            return null;
        }

        if ($this->isSameSqlServerUser($writeUser, $readUser)
            && $this->shouldLogWarningWithCooldown('b2b_write_user_matches_read')
        ) {
            $this->logger->warning(
                '[B2B Registration] Write connection is using the same SQL Server user as the read connection. '
                . 'If this user does not have INSERT on GR_INTEGRACAOVALIDADOR, disable write_connection or '
                . 'configure a dedicated write-enabled user.'
            );
        }

        try {
            $host = $this->helper->getHost();
            $port = $this->helper->getPort();
            $database = $this->helper->getDatabase();

            // Match ERP read connection DSN (dblib 7.4 + dbname) — bare host DSN fails on this host.
            $dsn = sprintf(
                'dblib:host=%s:%d;dbname=%s;version=7.4;charset=UTF-8',
                $host,
                $port,
                $database
            );
            $this->writeConnection = new \PDO($dsn, $writeUser, $writePass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 15,
            ]);

            if (!$this->validateWritePermission($this->writeConnection)) {
                $this->writeConnection = null;
                return null;
            }

            $this->logger->info('[B2B Registration] Write connection established');
            return $this->writeConnection;
        } catch (\Exception $e) {
            if ($this->shouldLogWarningWithCooldown('b2b_write_connection_failed')) {
                $this->logger->error('[B2B Registration] Write connection failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Validate INSERT permission on Sectra validator table.
     */
    private function validateWritePermission(\PDO $pdo): bool
    {
        if ($this->writePermissionChecked) {
            return $this->writePermissionGranted;
        }

        $this->writePermissionChecked = true;

        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM fn_my_permissions('dbo.GR_INTEGRACAOVALIDADOR', 'OBJECT') "
                . "WHERE permission_name = 'INSERT'"
            );
            $canInsert = (int) $stmt->fetchColumn();

            $this->writePermissionGranted = $canInsert > 0;

            if (!$this->writePermissionGranted) {
                if ($this->shouldLogWarningWithCooldown('b2b_insert_permission')) {
                    $this->logger->warning(
                        '[B2B Registration] Write user has no INSERT permission on dbo.GR_INTEGRACAOVALIDADOR.'
                    );
                }
            }
        } catch (\Exception $e) {
            // Keep flow resilient when permission metadata cannot be queried.
            $this->writePermissionGranted = true;
            if ($this->shouldLogWarningWithCooldown('b2b_insert_permission_check_error')) {
                $this->logger->warning(
                    '[B2B Registration] Could not verify INSERT permission: ' . $e->getMessage()
                );
            }
        }

        return $this->writePermissionGranted;
    }

    /**
     * Detect SQL Server INSERT permission denial.
     */
    private function isInsertPermissionDeniedError(\Throwable $exception): bool
    {
        return stripos($exception->getMessage(), 'INSERT permission was denied') !== false;
    }

    private function isSameSqlServerUser(string $leftUser, string $rightUser): bool
    {
        $leftUser = trim($leftUser);
        $rightUser = trim($rightUser);

        if ($leftUser === '' || $rightUser === '') {
            return false;
        }

        return strcasecmp($leftUser, $rightUser) === 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function collectNumericValidatorKeys(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            foreach (['CHAVE', 'CHAVEEXTERNA'] as $field) {
                $raw = trim((string) ($row[$field] ?? ''));
                if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
                    continue;
                }

                $code = (int) $raw;
                if ($code > 0) {
                    $codes[$code] = true;
                }
            }
        }

        return array_map('intval', array_keys($codes));
    }

    private function hasValidatorEntry(string $origem, int $code): bool
    {
        $result = $this->readConnection->fetchOne(
            "SELECT CHAVE
               FROM GR_INTEGRACAOVALIDADOR
              WHERE INTEGRACAOORIGEM = :origem
                AND (
                    CHAVE = :code
                    OR CHAVEEXTERNA = :code
                )",
            [
                ':code' => (string) $code,
                ':origem' => $origem,
            ]
        );

        if (is_array($result)) {
            $result = reset($result);
        }

        return $result !== false && $result !== null && trim((string) $result) !== '';
    }

    /**
     * @param array<string, int|string> $payload
     */
    private function buildValidatorHash(array $payload): string
    {
        return strtoupper(hash('sha256', (string) json_encode($payload)));
    }

    /**
     * Limit repetitive warnings across cron runs.
     */
    private function shouldLogWarningWithCooldown(string $key): bool
    {
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        $lockDir = rtrim($basePath, '/') . '/var/locks';

        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return true;
        }

        $file = $lockDir . '/erp_warn_' . preg_replace('/[^a-z0-9_]+/i', '_', $key) . '.lock';
        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return true;
        }

        @chmod($file, 0666);

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return true;
        }

        try {
            rewind($handle);
            $last = (int) trim((string) stream_get_contents($handle));
            $now = time();

            if ($last > 0 && ($now - $last) < self::WARNING_COOLDOWN_SECONDS) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $now);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
