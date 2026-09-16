<?php

declare(strict_types=1);


// IP Whitelist — acesso restrito a conexões locais e túneis SSH autorizados.
(static function(): void {
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $xff      = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $allowed  = ['127.0.0.1', '::1'];
    $isLocal  = in_array($clientIp, $allowed, true);
    $xffOk    = ($xff === '' || in_array(trim(explode(',', $xff)[0]), $allowed, true));
    if (!$isLocal || !$xffOk) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acesso restrito. Use túnel SSH autorizado.']);
        exit;
    }
})();
/**
 * Verifica se o MySQL do Sectra OpenCardB2B aponta para a nuvem Magento.
 * Abra no browser do PC Sectra e compare o PIN com o configurado no Sectra.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$envFile = dirname(__DIR__) . '/app/etc/env.php';
$env = require $envFile;
$db = $env['db']['connection']['default'] ?? null;
if (!$db) {
    http_response_code(503);
    echo json_encode(['error' => 'db config missing']);
    exit;
}

$dsn = isset($db['unix_socket'])
    ? "mysql:unix_socket={$db['unix_socket']};dbname={$db['dbname']};charset=utf8"
    : "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8";

try {
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'mysql: ' . $e->getMessage()]);
    exit;
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sectra_bridge_ping (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        pin VARCHAR(16) NOT NULL,
        updated_at DATETIME NOT NULL,
        last_remote VARCHAR(45) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$pin = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$cfConnectingIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
$runtimeNonce = bin2hex(random_bytes(4));


$stmt = $pdo->prepare(
    'INSERT INTO sectra_bridge_ping (id, pin, updated_at, last_remote)
     VALUES (1, :pin, NOW(), :remote)
     ON DUPLICATE KEY UPDATE pin = VALUES(pin), updated_at = VALUES(updated_at), last_remote = VALUES(last_remote)'
);
$stmt->execute([':pin' => $pin, ':remote' => $remote]);

$exportCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM oc_customer WHERE code = '' AND status = 1"
)->fetchColumn();
$ocOrder = (int) $pdo->query('SELECT COUNT(*) FROM oc_order')->fetchColumn();

$response = [
    'code_version' => 'sectra-mysql-pin-v2',
    'status' => 'ok',
    'runtime_nonce' => $runtimeNonce,
    'cloud_mysql_pin' => $pin,
    'pin_updated_at' => date('c'),
    'your_ip' => $remote,
    'forwarded_for' => $forwardedFor,
    'cf_connecting_ip' => $cfConnectingIp,
    'magento_db_host' => $db['host'] ?? 'socket',
    'magento_db_name' => $db['dbname'] ?? '',
    'sectra_must_use_host' => '72.61.94.22',
    'sectra_must_use_port' => 3306,
    'sectra_must_use_database' => 'magento',
    'verify_in_sectra_mysql' => "SELECT pin, updated_at FROM sectra_bridge_ping WHERE id = 1",
    'expected_pin' => $pin,
    'export_queue_count' => $exportCount,
    'oc_order_count' => $ocOrder,
    'diagnosis' => $exportCount > 0
        ? "Nuvem OK: fila export = {$exportCount}. Se Sectra mostra 0 de 0, MySQL do Sectra NAO aponta para esta nuvem."
        : 'Nuvem fila export = 0. Verifique bridge/cron.',
    'next_urls' => [
        'export_query' => 'https://awamotos.com/sectra-export-query.php',
        'export_status' => 'https://awamotos.com/sectra-export-status.php',
    ],
];



echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
