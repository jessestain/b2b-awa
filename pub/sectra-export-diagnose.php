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
 * Diagnóstico: Exportar Clientes termina sem mensagem e sem gravar cadastro.
 * Abra no PC Sectra DEPOIS de clicar Exportar Clientes — compare export_runs.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$envFile = dirname(__DIR__) . '/app/etc/env.php';
$env = require $envFile;
$db = $env['db']['connection']['default'];
$dsn = isset($db['unix_socket'])
    ? "mysql:unix_socket={$db['unix_socket']};dbname={$db['dbname']};charset=utf8"
    : "mysql:host={$db['host']};dbname={$db['dbname']};charset=utf8";

$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sectra_export_audit (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(32) NOT NULL,
        customer_id INT UNSIGNED DEFAULT NULL,
        old_code VARCHAR(64) DEFAULT NULL,
        new_code VARCHAR(64) DEFAULT NULL,
        remote_addr VARCHAR(45) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$expectedIds = [2541, 11134, 16161, 17421, 18202, 18771];
$placeholders = implode(',', array_fill(0, count($expectedIds), '?'));

$exportQueue = $pdo->query(
    "SELECT customer_id FROM oc_customer WHERE code = '' AND status = 1 ORDER BY customer_id"
)->fetchAll(PDO::FETCH_COLUMN);

$orderClients = $pdo->prepare(
    "SELECT c.customer_id, c.code, c.status, c.telephone, c.address_id,
            JSON_UNQUOTE(JSON_EXTRACT(c.custom_field, '$.\"6\"')) AS cnpj,
            (SELECT COUNT(*) FROM oc_address a WHERE a.customer_id = c.customer_id) AS addresses,
            (SELECT 1 FROM oc_customer_b2b_confirmed b WHERE b.customer_id = c.customer_id) AS b2b_ok
     FROM oc_customer c
     WHERE c.customer_id IN ($placeholders)
     ORDER BY c.customer_id"
);
$orderClients->execute($expectedIds);
$clientRows = $orderClients->fetchAll(PDO::FETCH_ASSOC);

$pinRow = $pdo->query('SELECT pin, last_remote, updated_at FROM sectra_bridge_ping WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

$auditRows = $pdo->query(
    'SELECT event_type, customer_id, old_code, new_code, remote_addr, created_at
     FROM sectra_export_audit ORDER BY id DESC LIMIT 20'
)->fetchAll(PDO::FETCH_ASSOC);

$codesFilled = array_filter($clientRows, static fn (array $r): bool => trim((string) ($r['code'] ?? '')) !== '');
$exportCompleted = count($codesFilled) > 0;

$whySilent = [];
if (count($exportQueue) === 0) {
    $whySilent[] = 'Fila vazia na nuvem (code=\'\' AND status=1) — Sectra processa 0 e não mostra mensagem.';
}
if (count($exportQueue) === 6 && !$exportCompleted && $auditRows === []) {
    $whySilent[] = 'Nuvem tem 6 clientes, mas nenhum UPDATE em oc_customer.code foi registrado — OpenCardB2B provavelmente NÃO usa este MySQL (config interna do Sectra ≠ browser).';
}
if (count($exportQueue) === 6 && !$exportCompleted) {
    $whySilent[] = 'Cliente já existe no ERP (FN_FORNECEDORES CKPROSPECT=N) — algumas versões do OpenCardB2B pulam silenciosamente sem criar Cadastro 7D4C6FBD.';
}
if (!$exportCompleted) {
    $whySilent[] = 'Cadastro GR_INTEGRACAOVALIDADOR (7D4C6FBD) ainda ausente — Importar Pedidos falhará com "Cliente não foi encontrado".';
}

$response = [
    'status' => 'ok',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'export_completed_on_cloud' => $exportCompleted,
    'export_queue_count' => count($exportQueue),
    'export_queue_ids' => array_map('intval', $exportQueue),
    'order_clients' => $clientRows,
    'mysql_pin' => $pinRow,
    'export_audit_recent' => $auditRows,
    'why_silent_no_message' => $whySilent,
    'test_from_sectra_mysql_client' => [
        'step_1' => 'No cliente MySQL configurado NO SECTRA (não só no browser), execute:',
        'sql_pin' => 'SELECT pin FROM sectra_bridge_ping WHERE id = 1;',
        'expected_pin' => $pinRow['pin'] ?? null,
        'step_2' => 'Se PIN diferente → corrija host 72.61.94.22, porta 3306, database magento no OpenCardB2B.',
        'sql_queue' => "SELECT COUNT(*) FROM oc_customer WHERE code='' AND status=1;",
        'expected_queue' => 6,
        'sql_clients' => 'SELECT customer_id, firstname, code FROM oc_customer WHERE customer_id IN (2541,11134,16161,17421,18202,18771);',
    ],
    'plan_b_sql' => 'https://awamotos.com/sectra-cadastro-sql.php',
    'after_export_check' => 'https://awamotos.com/sectra-export-status.php',
];


echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
