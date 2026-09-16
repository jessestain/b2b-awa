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
 * Simula o SELECT do OpenCardB2B "Exportar Clientes" (code='' AND status=1).
 * Abra no PC do Sectra: se não mostrar 6 linhas com IDs nativos, o MySQL do Sectra
 * NÃO está apontando para a nuvem Magento.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Surrogate-Control: no-store');

$envFile = dirname(__DIR__) . '/app/etc/env.php';
$env = require $envFile;
$db = $env['db']['connection']['default'];
$dsn = isset($db['unix_socket'])
    ? "mysql:unix_socket={$db['unix_socket']};dbname={$db['dbname']};charset=utf8"
    : "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8";

$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$forwardedFor = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
$cfConnectingIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
$runtimeNonce = bin2hex(random_bytes(4));

$rows = $pdo->query(
    "SELECT customer_id, firstname, lastname, email, telephone, code, status,
            customer_group_id, address_id,
            JSON_UNQUOTE(JSON_EXTRACT(custom_field, '$.\"6\"')) AS cnpj
     FROM oc_customer
     WHERE code = '' AND status = 1
     ORDER BY customer_id"
)->fetchAll(PDO::FETCH_ASSOC);

$expected = [2541, 11134, 16161, 17421, 18202];
$actual = array_map('intval', array_column($rows, 'customer_id'));
$match = ($actual === $expected);

$response = [
    'code_version' => 'sectra-export-query-v2',
    'status' => 'ok',
    'runtime_nonce' => $runtimeNonce,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'forwarded_for' => $forwardedFor,
    'cf_connecting_ip' => $cfConnectingIp,
    'mysql_host_used_by_magento' => $db['host'] ?? 'socket',
    'sectra_export_query_rows' => count($rows),
    'expected_customer_ids' => $expected,
    'actual_customer_ids' => $actual,
    'ids_match_expected' => $match,
    'rows' => $rows,
    'diagnosis' => $match
        ? 'MySQL OK — Sectra deve ver 5 de 5. Execute Exportar Clientes no desktop.'
        : 'MySQL do Sectra provavelmente aponta para banco ERRADO. Configure host 72.61.94.22, DB magento.',
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
