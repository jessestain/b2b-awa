<?php
/** PROBLEMA B/C — evidencias de banco: rokan_faq, cms faq, FK delete rule. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function dump(string $title, array $r): void
{
    echo "\n### $title\n";
    if (!$r) { echo "(vazio)\n"; return; }
    echo implode(' | ', array_keys($r[0])) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

dump('SHOW CREATE TABLE authorization_rule', $pdo->query('SHOW CREATE TABLE authorization_rule')->fetchAll(PDO::FETCH_ASSOC));

dump('tabelas rokan_*', $pdo->query("SHOW TABLES LIKE 'rokan%'")->fetchAll(PDO::FETCH_NUM));

$has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rokan_faq'")->fetchColumn();
echo "\nrokan_faq existe? " . ($has ? 'SIM' : 'NAO') . "\n";
if ($has) {
    dump('rokan_faq (contagem e amostra)', $pdo->query('SELECT * FROM rokan_faq LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));
    dump('rokan_faq total', $pdo->query('SELECT COUNT(*) AS total FROM rokan_faq')->fetchAll(PDO::FETCH_ASSOC));
}

dump('setup_module (modulos com registro de setup)', $pdo->query("SELECT module, schema_version, data_version FROM setup_module WHERE module LIKE '%Faq%' OR module LIKE '%StoreSetup%' OR module LIKE '%Report%' ORDER BY module")->fetchAll(PDO::FETCH_ASSOC));

dump('patch_list (StoreSetup)', $pdo->query("SELECT patch_name FROM patch_list WHERE patch_name LIKE '%AyoSeedContent%' ORDER BY patch_name")->fetchAll(PDO::FETCH_ASSOC));

dump('cms_page com faq', $pdo->query("SELECT page_id, identifier, is_active FROM cms_page WHERE identifier LIKE '%faq%' OR title LIKE '%FAQ%'")->fetchAll(PDO::FETCH_ASSOC));

dump('url_rewrite faq', $pdo->query("SELECT url_rewrite_id, request_path, target_path, entity_type FROM url_rewrite WHERE request_path LIKE '%faq%' LIMIT 10")->fetchAll(PDO::FETCH_ASSOC));

dump('authorization_rule das roles 189/191', $pdo->query("SELECT rule_id, role_id, resource_id, permission, assert_id FROM authorization_rule WHERE role_id IN (189,191) ORDER BY role_id, rule_id")->fetchAll(PDO::FETCH_ASSOC));
