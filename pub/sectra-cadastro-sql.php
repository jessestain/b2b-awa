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
$sqlFile = dirname(__DIR__) . '/var/log/sectra_export_order_clients_latest.sql';
$fallback = dirname(__DIR__) . '/var/log/sectra_register_clients_latest.sql';

if (!is_readable($sqlFile) && is_readable($fallback)) {
    $sqlFile = $fallback;
}

if (!is_readable($sqlFile)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'SQL nao gerado ainda',
        'hint' => 'bin/magento erp:sectra:status --export-sql',
    ]);
    exit;
}

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Arquivo SQL vazio']);
    exit;
}

$sql = "-- PREFERENCIA: use Exportar Clientes no Sectra desktop (calcula VALIDADOR correto).\n"
    . "-- Este SQL usa hash Magento que pode diferir do Sectra. Use so se Exportar Clientes falhar.\n\n"
    . $sql;


header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="sectra-export-order-clients.sql"');
header('Cache-Control: no-store');
echo $sql;
