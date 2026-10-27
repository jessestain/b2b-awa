<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * SQL Server Connection Class with Multiple Driver Support
 *
 * Supports:
 * - sqlsrv: Microsoft PHP Driver for SQL Server (recommended)
 * - dblib: FreeTDS driver (Linux)
 * - odbc: ODBC Driver
 *
 * Features:
 * - Circuit Breaker pattern for resilience
 * - Retry logic with exponential backoff
 * - Multiple driver support with auto-detection
 */
class Connection implements ConnectionInterface
{
    private const DRIVER_SQLSRV = 'sqlsrv';
    private const DRIVER_DBLIB = 'dblib';
    private const DRIVER_ODBC = 'odbc';

    /**
     * Maximum number of connection retry attempts
     */
    private const MAX_RETRIES = 3;

    /**
     * Base delay in milliseconds for exponential backoff
     */
    private const BASE_DELAY_MS = 100;

    /**
     * Maximum delay in milliseconds
     */
    private const MAX_DELAY_MS = 5000;

    /**
     * Maximum seconds a single query execution cycle (including retries) may take.
     * After this, executeWithRetry aborts to protect the PHP-FPM worker.
     */
    private const QUERY_WALL_TIMEOUT = 45;

    /**
     * Login/connect timeout for PDO. 30s × 3 drivers × 3 retries estourava o
     * request_terminate_timeout do FPM (90s) → 502 no dashboard B2B logado
     * depois de cache Redis sem lista de preço (slow log: Connection.php:419).
     */
    private const PDO_CONNECT_TIMEOUT = 5;

    /**
     * Probe TCP before new PDO(). Porta filtrada/fechada não deve bloquear o FPM.
     */
    private const TCP_PROBE_TIMEOUT = 5;

    /**
     * Transient error codes that should be retried
     */
    private const TRANSIENT_ERROR_CODES = [
        -2,      // Timeout
        -1,      // Connection problem
        10053,   // Connection aborted
        10054,   // Connection reset
        10060,   // Connection timed out
        10061,   // Connection refused
        233,     // Named pipe not ready
        64,      // Host is down
        121,     // Semaphore timeout
        258,     // Wait operation timed out
        20002,   // FreeTDS: Adaptive Server connection failed (transient overload)
        20009,   // FreeTDS: Unable to connect (momentary unavailability)
    ];

    private Helper $helper;
    private LoggerInterface $logger;
    private CircuitBreaker $circuitBreaker;
    private ?\PDO $connection = null;
    private array $availableDrivers = [];
    private int $connectionAttempts = 0;

    /** Timestamp of last successful isConnectionAlive() check (microtime) */
    private float $lastAliveCheck = 0.0;

    /** Seconds between repeated SELECT 1 health checks on the same connection */
    private const ALIVE_CHECK_TTL = 5;

    public function __construct(
        Helper $helper,
        LoggerInterface $logger,
        CircuitBreaker $circuitBreaker
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
        $this->detectAvailableDrivers();
    }

    /**
     * Detect available PDO drivers for SQL Server
     */
    private function detectAvailableDrivers(): void
    {
        $pdoDrivers = \PDO::getAvailableDrivers();

        if (in_array('sqlsrv', $pdoDrivers)) {
            $this->availableDrivers[] = self::DRIVER_SQLSRV;
        }
        if (in_array('dblib', $pdoDrivers)) {
            $this->availableDrivers[] = self::DRIVER_DBLIB;
        }
        if (in_array('odbc', $pdoDrivers)) {
            $this->availableDrivers[] = self::DRIVER_ODBC;
        }
    }

    /**
     * Get available drivers
     */
    public function getAvailableDrivers(): array
    {
        return $this->availableDrivers;
    }

    /**
     * Check if any SQL Server driver is available
     *
     * @throws \RuntimeException When no SQL Server driver is available
     */
    public function hasAvailableDriver(): bool
    {
        return !empty($this->availableDrivers);
    }

    /**
     * Get connection using the best available driver with retry logic and circuit breaker
     *
     * @throws \RuntimeException When connection cannot be established after retries
     */
    public function getConnection(): \PDO
    {
        // Check circuit breaker first
        if (!$this->circuitBreaker->isAvailable()) {
            $stats = $this->circuitBreaker->getStats();
            throw new CircuitBreakerOpenException(
                new Phrase(
                    'ERP connection circuit breaker is open. Retry in %1 seconds. State: %2',
                    [(int) ($stats['time_until_half_open'] ?? 0), (string) ($stats['state'] ?? '')]
                )
            );
        }

        if ($this->connection !== null) {
            // Verify connection is still alive
            if ($this->isConnectionAlive()) {
                return $this->connection;
            }
            // Connection is dead, reset and reconnect
            $this->connection = null;
            $this->lastAliveCheck = 0.0;
            $this->logger->info('[ERP] Connection was lost, reconnecting...');
        }

        if (!$this->hasAvailableDriver()) {
            throw new \RuntimeException(
                'Nenhum driver SQL Server disponível. ' .
                'Instale: php-sqlsrv (Microsoft), php-sybase (dblib/FreeTDS), ou configure ODBC.'
            );
        }

        $host = $this->helper->getHost();
        $port = $this->helper->getPort();
        $database = $this->helper->getDatabase();
        $username = $this->helper->getUsername();
        $password = $this->helper->getPassword();

        try {
            $this->assertTcpReachable((string) $host, (int) $port);
        } catch (\RuntimeException $e) {
            $this->circuitBreaker->recordFailure($e);
            throw $e;
        }

        // Get preferred driver from config or use auto-detection
        $preferredDriver = $this->helper->getDriver();
        $driversToTry = $this->getDriversToTry($preferredDriver);

        $lastException = null;

        foreach ($driversToTry as $driver) {
            // Try each driver with retry logic
            try {
                $connection = $this->connectWithRetry($driver, $host, $port, $database, $username, $password);

                if ($connection !== null) {
                    $this->connection = $connection;
                    $this->circuitBreaker->recordSuccess();
                    // Host/port intentionally omitted from INFO log to avoid exposing internal
                    // network topology in application logs; use erp:connection:test for details.
                    $this->logger->info(sprintf(
                        '[ERP] Connected to SQL Server using %s driver: database=%s (attempts: %d)',
                        $driver,
                        $database,
                        $this->connectionAttempts
                    ));
                    return $this->connection;
                }
            } catch (\PDOException $e) {
                $lastException = $e;
            }
        }

        // Record failure in circuit breaker
        $this->circuitBreaker->recordFailure($lastException);

        $this->logger->error('[ERP] All connection attempts failed', [
            'drivers_tried' => $driversToTry,
            'total_attempts' => $this->connectionAttempts,
            'circuit_breaker_state' => $this->circuitBreaker->getState(),
        ]);

        throw $lastException ?? new \RuntimeException('Connection failed with all available drivers after retries');
    }

    /**
     * Fail-fast if SQL Server TCP is closed/filtered. new PDO() at connectWithDriver
     * hung PHP-FPM ~90s (CDP 502 on B2B account after Redis cache flush).
     */
    private function assertTcpReachable(string $host, int $port): void
    {
        if ($host === '' || $port <= 0) {
            throw new \RuntimeException('ERP host/port missing');
        }

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, self::TCP_PROBE_TIMEOUT);
        $ok = is_resource($fp);
        if ($ok) {
            fclose($fp);
        }


        if (!$ok) {
            $this->logger->error('[ERP] TCP probe failed before PDO connect', [
                'errno' => $errno,
            ]);
            throw new \RuntimeException('ERP TCP unreachable');
        }
    }

    /**
     * Get circuit breaker instance for external access
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * Check if circuit breaker is open
     */
    public function isCircuitOpen(): bool
    {
        return $this->circuitBreaker->getState() === CircuitBreakerState::OPEN;
    }

    /**
     * Get circuit breaker statistics
     */
    public function getCircuitBreakerStats(): array
    {
        return $this->circuitBreaker->getStats();
    }

    /**
     * Reset circuit breaker to closed state
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreaker->reset();
    }

    /**
     * Check if connection is still alive
     */
    private function isConnectionAlive(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        // Skip the SELECT 1 round-trip if we checked very recently.
        // Avoids extra ERP latency on pages with multiple queries in the same request.
        $now = microtime(true);
        if ($now - $this->lastAliveCheck < self::ALIVE_CHECK_TTL) {
            return true;
        }

        try {
            $this->connection->query('SELECT 1');
            $this->lastAliveCheck = microtime(true);
            return true;
        } catch (\PDOException $e) {
            $this->lastAliveCheck = 0.0;
            return false;
        }
    }

    /**
     * Connect with retry logic and exponential backoff
     */
    private function connectWithRetry(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): ?\PDO {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->connectionAttempts++;

            try {
                return $this->connectWithDriver($driver, $host, $port, $database, $username, $password);
            } catch (\PDOException $e) {
                $lastException = $e;

                // Check if error is transient and should be retried
                if (!$this->isTransientError($e) || $attempt === self::MAX_RETRIES) {
                    $this->logger->warning(sprintf(
                        '[ERP] Failed to connect with %s driver (attempt %d/%d): %s',
                        $driver,
                        $attempt,
                        self::MAX_RETRIES,
                        $e->getMessage()
                    ));
                    break;
                }

                // Calculate delay with exponential backoff + jitter
                $delay = $this->calculateBackoffDelay($attempt);

                $this->logger->info(sprintf(
                    '[ERP] Connection attempt %d/%d failed with transient error, retrying in %dms: %s',
                    $attempt,
                    self::MAX_RETRIES,
                    $delay,
                    $e->getMessage()
                ));

                // Wait before retry
                usleep($delay * 1000);
            }
        }

        return null;
    }

    /**
     * Check if the exception represents a transient error that should be retried
     */
    private function isTransientError(\PDOException $e): bool
    {
        $code = (int) $e->getCode();
        $message = strtolower($e->getMessage());

        // Check error code
        if (in_array($code, self::TRANSIENT_ERROR_CODES, true)) {
            return true;
        }

        // Check message for transient error patterns
        $transientPatterns = [
            'timeout',
            'connection',
            'timed out',
            'communication link',
            'server is not available',
            'network-related',
            'temporarily unavailable',
            'deadlock',
            'lock request time out',
            'adaptive server connection failed',
            'unable to connect',
            'server unavailable',
        ];

        foreach ($transientPatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate backoff delay with exponential increase and jitter
     */
    private function calculateBackoffDelay(int $attempt): int
    {
        // Exponential backoff: base_delay * 2^(attempt-1)
        $delay = self::BASE_DELAY_MS * (2 ** ($attempt - 1));

        // Add jitter (±25% randomization)
        $jitter = (int) ($delay * 0.25);
        if ($jitter > 0) {
            // Jitter pseudo-aleatório determinístico (evita dependência de funções globais em análises estáticas)
            $range = ($jitter * 2) + 1;
            $offset = ((($attempt * 1103515245) + 12345) % $range) - $jitter;
            $delay += $offset;
        }

        // Cap at maximum delay
        return min($delay, self::MAX_DELAY_MS);
    }

    /**
     * Get list of drivers to try, with preferred driver first
     */
    private function getDriversToTry(string $preferredDriver): array
    {
        if ($preferredDriver === 'auto' || empty($preferredDriver)) {
            // Default priority: sqlsrv > dblib > odbc
            return $this->availableDrivers;
        }

        if (in_array($preferredDriver, $this->availableDrivers)) {
            return [$preferredDriver];
        }

        // Fallback to auto if preferred driver not available
        return $this->availableDrivers;
    }

    /**
     * Create PDO connection with specific driver
     */
    private function connectWithDriver(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password
    ): \PDO {
        // FreeTDS (dblib) requires TDSVER env var for proper protocol negotiation
        if ($driver === self::DRIVER_DBLIB) {
            putenv('TDSVER=7.4');
        }

        $dsn = $this->buildDsn($driver, $host, $port, $database);
        $options = $this->getConnectionOptions($driver);

        $pdo = new \PDO($dsn, $username, $password, $options);

        // dblib (FreeTDS) has no native query timeout attribute.
        // Enforce a server-side lock timeout via SET LOCK_TIMEOUT (ms)
        // to prevent runaway queries from blocking PHP-FPM workers.
        if ($driver === self::DRIVER_DBLIB) {
            $pdo->exec('SET LOCK_TIMEOUT 30000');
        }

        return $pdo;
    }

    /**
     * Build DSN string for specific driver
     */
    private function buildDsn(string $driver, string $host, int $port, string $database): string
    {
        switch ($driver) {
            case self::DRIVER_SQLSRV:
                // Microsoft SQL Server Driver for PHP
                $trustServerCertificate = $this->helper->getTrustServerCertificate() ? '1' : '0';
                $dsn = sprintf(
                    'sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=%s;Encrypt=1',
                    $host,
                    $port,
                    $database,
                    $trustServerCertificate
                );
                break;

            case self::DRIVER_DBLIB:
                // FreeTDS (dblib) — include dbname in DSN and version 7.4 for modern SQL Server
                $dsn = sprintf(
                    'dblib:host=%s:%d;dbname=%s;version=7.4;charset=UTF-8',
                    $host,
                    $port,
                    $database
                );
                break;

            case self::DRIVER_ODBC:
                // ODBC Driver 17/18 for SQL Server
                $odbcDriver = $this->getOdbcDriverName();
                $trustServerCertificate = $this->helper->getTrustServerCertificate() ? 'yes' : 'no';
                $dsn = sprintf(
                    'odbc:Driver={%s};Server=%s,%d;Database=%s;TrustServerCertificate=%s;Encrypt=yes;',
                    $odbcDriver,
                    $host,
                    $port,
                    $database,
                    $trustServerCertificate
                );
                break;

            default:
                throw new \InvalidArgumentException('Unsupported driver: ' . $driver);
        }

        return $dsn;
    }

    /**
     * Detect ODBC driver name
     */
    private function getOdbcDriverName(): string
    {
        // Check for common ODBC driver names
        $possibleDrivers = [
            'ODBC Driver 18 for SQL Server',
            'ODBC Driver 17 for SQL Server',
            'ODBC Driver 13 for SQL Server',
            'FreeTDS',
            'SQL Server',
        ];

        // Try to detect from system
        $odbcIniFiles = ['/etc/odbcinst.ini', '/usr/local/etc/odbcinst.ini'];
        foreach ($odbcIniFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                foreach ($possibleDrivers as $driver) {
                    if (stripos($content, "[$driver]") !== false) {
                        return $driver;
                    }
                }
            }
        }

        // Default to ODBC Driver 17
        return 'ODBC Driver 17 for SQL Server';
    }

    /**
     * Get PDO connection options
     */
    private function getConnectionOptions(string $driver): array
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => self::PDO_CONNECT_TIMEOUT,
        ];

        // Driver-specific options
        if ($driver === self::DRIVER_SQLSRV) {
            if (defined('PDO::SQLSRV_ATTR_DIRECT_QUERY')) {
                $options[(int) constant('PDO::SQLSRV_ATTR_DIRECT_QUERY')] = true;
            }

            if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
                $options[(int) constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')] = true;
            }

            // Limit query execution to 60s to prevent runaway queries from hanging cron
            if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                $options[(int) constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = 60;
            }
        }

        return $options;
    }

    /**
     * Test connection and return diagnostic info
     */
    public function testConnection(): array
    {
        // First check driver availability
        if (!$this->hasAvailableDriver()) {
            return [
                'success' => false,
                'message' => 'Nenhum driver SQL Server disponível.',
                'available_drivers' => [],
                'required_extensions' => [
                    'php-sqlsrv' => 'Driver Microsoft (recomendado)',
                    'php-sybase' => 'Driver FreeTDS (dblib)',
                    'php-odbc' => 'Driver ODBC'
                ],
                'installation_tips' => $this->getInstallationTips(),
                'circuit_breaker' => $this->circuitBreaker->getStats()
            ];
        }

        // Check circuit breaker state
        if (!$this->circuitBreaker->isAvailable()) {
            $stats = $this->circuitBreaker->getStats();
            return [
                'success' => false,
                'message' => sprintf(
                    'Circuit breaker está aberto. Tentativas bloqueadas por %d segundos.',
                    $stats['time_until_half_open']
                ),
                'available_drivers' => $this->availableDrivers,
                'circuit_breaker' => $stats,
                'troubleshooting' => [
                    'O circuit breaker foi ativado após múltiplas falhas de conexão.',
                    sprintf('Aguarde %d segundos para nova tentativa.', $stats['time_until_half_open']),
                    'Verifique se o servidor SQL Server está acessível.',
                    'Use o comando para resetar: bin/magento erp:circuit-breaker --reset'
                ]
            ];
        }

        try {
            $pdo = $this->getConnection();

            // Get SQL Server version and info
            $stmt = $pdo->query('SELECT @@VERSION AS version, DB_NAME() AS db_name, GETDATE() AS server_time, @@SERVERNAME AS server_name');
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Count tables
            $stmt2 = $pdo->query("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
            $tableCount = $stmt2->fetch(\PDO::FETCH_ASSOC);

            // Get some table names as sample
            $stmt3 = $pdo->query("SELECT TOP 10 TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
            $tables = $stmt3->fetchAll(\PDO::FETCH_COLUMN);

            return [
                'success' => true,
                'message' => 'Conexão estabelecida com sucesso!',
                'driver_used' => $this->getActiveDriverName(),
                'available_drivers' => $this->availableDrivers,
                'server_name' => $row['server_name'] ?? 'Unknown',
                'version' => $this->parseVersion($row['version'] ?? ''),
                'full_version' => $row['version'] ?? 'Unknown',
                'database' => $row['db_name'] ?? 'Unknown',
                'server_time' => $row['server_time'] ?? 'Unknown',
                'table_count' => (int) ($tableCount['cnt'] ?? 0),
                'sample_tables' => $tables,
                'connection_string' => $this->getMaskedConnectionString(),
                'circuit_breaker' => $this->circuitBreaker->getStats()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Falha na conexão: ' . $e->getMessage(),
                'available_drivers' => $this->availableDrivers,
                'error_code' => $e->getCode(),
                'troubleshooting' => $this->getTroubleshootingTips($e),
                'circuit_breaker' => $this->circuitBreaker->getStats()
            ];
        }
    }

    /**
     * Get active driver name
     */
    private function getActiveDriverName(): string
    {
        if ($this->connection === null) {
            return 'none';
        }
        return $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Parse SQL Server version string
     */
    private function parseVersion(string $fullVersion): string
    {
        if (preg_match('/Microsoft SQL Server (\d+)/', $fullVersion, $matches)) {
            $year = $matches[1];
            $editions = [
                '2022' => 'SQL Server 2022',
                '2019' => 'SQL Server 2019',
                '2017' => 'SQL Server 2017',
                '2016' => 'SQL Server 2016',
                '2014' => 'SQL Server 2014',
                '2012' => 'SQL Server 2012',
            ];
            return $editions[$year] ?? "SQL Server $year";
        }
        return 'SQL Server';
    }

    /**
     * Get masked connection string for display
     */
    private function getMaskedConnectionString(): string
    {
        $host = $this->helper->getHost();
        $port = $this->helper->getPort();
        $database = $this->helper->getDatabase();
        $username = $this->helper->getUsername();

        return sprintf('%s@%s:%d/%s', $username, $host, $port, $database);
    }

    /**
     * Get installation tips for SQL Server drivers
     */
    private function getInstallationTips(): array
    {
        return [
            'ubuntu_debian' => [
                'sqlsrv' => 'sudo apt-get install php-sqlsrv',
                'dblib' => 'sudo apt-get install php-sybase',
                'odbc' => 'sudo apt-get install php-odbc unixodbc-dev'
            ],
            'centos_rhel' => [
                'sqlsrv' => 'sudo yum install php-sqlsrv',
                'dblib' => 'sudo yum install php-mssql',
                'odbc' => 'sudo yum install php-odbc unixODBC'
            ],
            'pecl' => 'sudo pecl install sqlsrv pdo_sqlsrv',
            'docs' => 'https://docs.microsoft.com/en-us/sql/connect/php/installation-tutorial-linux-mac'
        ];
    }

    /**
     * Get troubleshooting tips based on error
     */
    private function getTroubleshootingTips(\Exception $e): array
    {
        $tips = [];
        $message = strtolower($e->getMessage());

        if (strpos($message, 'could not find driver') !== false) {
            $tips[] = 'Driver SQL Server não encontrado. Instale php-sqlsrv ou php-sybase.';
        }
        if (strpos($message, 'login failed') !== false || strpos($message, 'authentication') !== false) {
            $tips[] = 'Verifique usuário e senha.';
            $tips[] = 'Verifique se o usuário tem permissão para acessar o banco.';
        }
        if (strpos($message, 'timeout') !== false || strpos($message, 'connection') !== false) {
            $tips[] = 'Verifique se o servidor está acessível (ping).';
            $tips[] = 'Verifique se a porta 1433 está aberta no firewall.';
            $tips[] = 'Verifique se o SQL Server está configurado para aceitar conexões TCP/IP.';
        }
        if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false) {
            $tips[] = 'Problema de certificado SSL. TrustServerCertificate está habilitado.';
            $tips[] = 'Considere configurar certificado válido no SQL Server.';
        }
        if (strpos($message, 'database') !== false) {
            $tips[] = 'Verifique se o nome do banco de dados está correto.';
            $tips[] = 'Verifique se o usuário tem acesso ao banco especificado.';
        }

        if (empty($tips)) {
            $tips[] = 'Verifique os dados de conexão.';
            $tips[] = 'Consulte o log do SQL Server para mais detalhes.';
        }

        return $tips;
    }

    /**
     * Execute SELECT query with retry logic
     */
    public function query(string $sql, array $params = [], ?int $wallTimeout = null): array
    {
        return $this->executeWithRetry(function () use ($sql, $params) {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($sql); // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }, 'query', $wallTimeout);
    }

    /**
     * Execute INSERT/UPDATE/DELETE with retry logic
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->executeWithRetry(function () use ($sql, $params) {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($sql); // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query
            $stmt->execute($params);
            return $stmt->rowCount();
        }, 'execute');
    }

    /**
     * Fetch single row with retry logic
     */
    public function fetchOne(string $sql, array $params = [], ?int $wallTimeout = null): ?array
    {
        return $this->executeWithRetry(function () use ($sql, $params) {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($sql); // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }, 'fetchOne', $wallTimeout);
    }

    /**
     * Fetch single column value with retry logic
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        return $this->executeWithRetry(function () use ($sql, $params, $column) {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($sql); // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query
            $stmt->execute($params);
            return $stmt->fetchColumn($column);
        }, 'fetchColumn');
    }

    /**
     * Execute a callable with retry logic for transient errors
     *
     * @param callable $operation The database operation to execute
     * @param string $operationType Type of operation for logging
     * @return mixed Result of the operation
     * @throws \PDOException If all retries fail
     */
    private function executeWithRetry(callable $operation, string $operationType, ?int $wallTimeout = null)
    {
        $lastException = null;
        $startTime = microtime(true);
        $effectiveTimeout = $wallTimeout ?? self::QUERY_WALL_TIMEOUT;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Wall-clock guard: abort if we already consumed too much time
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $effectiveTimeout) {
                $msg = sprintf(
                    '[ERP] %s aborted — wall timeout of %ds exceeded (%.1fs elapsed, attempt %d)',
                    $operationType,
                    $effectiveTimeout,
                    $elapsed,
                    $attempt
                );
                $this->logger->error($msg);
                throw $lastException ?? new \RuntimeException($msg);
            }

            try {
                return $operation();
            } catch (\PDOException $e) {
                $lastException = $e;

                // If not a transient error or last attempt, don't retry
                if (!$this->isTransientError($e) || $attempt === self::MAX_RETRIES) {
                    throw $e;
                }

                // Reset connection on transient error
                $this->connection = null;
                $this->lastAliveCheck = 0.0;

                $delay = $this->calculateBackoffDelay($attempt);

                $this->logger->warning(sprintf(
                    '[ERP] %s failed (attempt %d/%d), retrying in %dms: %s',
                    $operationType,
                    $attempt,
                    self::MAX_RETRIES,
                    $delay,
                    $e->getMessage()
                ));

                usleep($delay * 1000);
            }
        }

        throw $lastException;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->getConnection()->rollBack();
    }

    /**
     * Disconnect from database
     */
    public function disconnect(): void
    {
        $this->connection = null;
        $this->lastAliveCheck = 0.0;
    }

    /**
     * Sanitize identifier to prevent SQL injection
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
    }

    /**
     * Check if currently connected
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }
}
