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
 * Diagnóstico Exportar Clientes — use no PC do Sectra antes de Exportar/Importar.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Surrogate-Control: no-store');
header('CDN-Cache-Control: no-store');

$envFile = dirname(__DIR__) . '/app/etc/env.php';
if (!is_readable($envFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'env.php unreadable']);
    exit;
}

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


$exportIds = $pdo->query(
    "SELECT customer_id FROM oc_customer WHERE code = '' AND status = 1 ORDER BY customer_id"
)->fetchAll(PDO::FETCH_COLUMN);

$clients = [];
$allMagentoReady = true;
foreach ($exportIds as $exportId) {
    $exportId = (int) $exportId;
    $row = $pdo->prepare(
        'SELECT customer_id, firstname, lastname, email, telephone, code, status, address_id,
                JSON_UNQUOTE(JSON_EXTRACT(custom_field, \'$."6"\')) AS cnpj
         FROM oc_customer WHERE customer_id = ?'
    );
    $row->execute([$exportId]);
    $customer = $row->fetch(PDO::FETCH_ASSOC) ?: [];

    $b2bStmt = $pdo->prepare('SELECT 1 FROM oc_customer_b2b_confirmed WHERE customer_id = ?');
    $b2bStmt->execute([$exportId]);
    $b2bConfirmed = (bool) $b2bStmt->fetchColumn();

    $addrStmt = $pdo->prepare('SELECT COUNT(*) FROM oc_address WHERE customer_id = ?');
    $addrStmt->execute([$exportId]);
    $addressCount = (int) $addrStmt->fetchColumn();

    $tel = preg_replace('/\D/', '', (string) ($customer['telephone'] ?? ''));
    $ready = $b2bConfirmed
        && $addressCount > 0
        && strlen($tel) >= 10
        && trim((string) ($customer['cnpj'] ?? '')) !== ''
        && (string) ($customer['code'] ?? '') === '';

    if (!$ready) {
        $allMagentoReady = false;
    }

    $clients[] = [
        'export_customer_id' => $exportId,
        'name' => trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? '')),
        'cnpj' => $customer['cnpj'] ?? '',
        'code' => $customer['code'] ?? '',
        'b2b_confirmed' => $b2bConfirmed,
        'addresses' => $addressCount,
        'magento_ready' => $ready,
    ];
}

$ocOrder = (int) $pdo->query('SELECT COUNT(*) FROM oc_order')->fetchColumn();

$exportCompleted = array_reduce(
    $clients,
    static fn (bool $carry, array $c): bool => $carry || trim((string) ($c['code'] ?? '')) !== '',
    false
);

$expectedIds = [2541, 11134, 16161, 17421, 18202, 18771];
$actualIds = array_map('intval', $exportIds);
$idsMatch = ($actualIds === $expectedIds);

$response = [
    'status' => 'ok',
    'magento_ready_for_export' => $allMagentoReady,
    'export_count' => count($exportIds),
    'exportar_clientes_expected' => count($exportIds) . ' de ' . count($exportIds),
    'oc_order' => $ocOrder,
    'importar_pedidos_expected' => $ocOrder . ' de ' . $ocOrder,
    'clients' => $clients,
    'export_completed' => $exportCompleted,
    'blocker' => $exportCompleted
        ? null
        : ($allMagentoReady && $idsMatch
            ? 'PENDENTE NO SECTRA DESKTOP: execute Exportar Clientes (6 de 6). Magento OK; oc_customer.code ainda vazio.'
            : 'Magento fila export inconsistente — rode cron ou verifique MySQL OpenCardB2B.'),
    'sectra_step_1' => 'OpenCardB2B → Exportar Clientes / Cadastro de Cliente (' . count($exportIds) . ' clientes)',
    'sectra_step_2' => 'OpenCardB2B → Importar Pedidos AWA (' . $ocOrder . ' pedidos)',
    'warning' => 'NÃO execute Importar Pedidos antes de Exportar Clientes concluir.',
    'after_export_check' => 'oc_customer.code deve ser preenchido (não vazio) nos 6 IDs',
    'cadastro_sql_fallback' => 'https://awamotos.com/sectra-cadastro-sql.php',
    'mysql_pin_test_url' => 'https://awamotos.com/sectra-mysql-pin.php',
    'export_ids_are_native_bridge' => $idsMatch,
    'expected_export_ids' => $expectedIds,
    'actual_export_ids' => $actualIds,
    'sectra_export_query_url' => 'https://awamotos.com/sectra-export-query.php',
    'mysql_check_hint' => 'No PC Sectra: abra sectra_export_query_url — deve listar exatamente os 6 IDs nativos acima',
];



echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
