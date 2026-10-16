<?php
/** PROBLEMA C — diagnostico SOMENTE LEITURA dos papeis de ACL orfaos. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']);
$pdo = new PDO($dsn, $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function dump(string $title, array $r): void
{
    echo "\n### $title\n";
    if (!$r) {
        echo "(vazio)\n";
        return;
    }
    $cols = array_keys($r[0]);
    echo implode(' | ', $cols) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

echo "DB = " . $d['dbname'] . "\n";

dump('authorization_role (todas)', rows($pdo,
    "SELECT role_id, parent_id, tree_level, sort_order, role_type, user_id, user_type, role_name
     FROM authorization_role ORDER BY role_id"));

dump('admin_user (todos)', rows($pdo,
    "SELECT user_id, username, email, is_active, LENGTH(password) AS pw_len FROM admin_user ORDER BY user_id"));

dump('os 2 papeis: os admins existem?', rows($pdo,
    "SELECT r.role_id, r.role_name, r.user_id, u.user_id AS admin_existe
     FROM authorization_role r LEFT JOIN admin_user u ON u.user_id = r.user_id
     WHERE r.role_id IN (189,191)"));

dump('filhos de 189/191 em authorization_role', rows($pdo,
    "SELECT role_id, parent_id, role_name, user_id FROM authorization_role WHERE parent_id IN (189,191)"));

dump('authorization_rule por papel', rows($pdo,
    "SELECT role_id, COUNT(*) AS regras FROM authorization_rule WHERE role_id IN (189,191) GROUP BY role_id"));

dump('FKs que APONTAM para authorization_role', rows($pdo,
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE REFERENCED_TABLE_NAME = 'authorization_role' AND TABLE_SCHEMA = DATABASE()"));

dump('FKs de authorization_role', rows($pdo,
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_NAME = 'authorization_role' AND REFERENCED_TABLE_NAME IS NOT NULL
       AND TABLE_SCHEMA = DATABASE()"));

dump('FKs que apontam para authorization_rule', rows($pdo,
    "SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE REFERENCED_TABLE_NAME = 'authorization_rule' AND TABLE_SCHEMA = DATABASE()"));

dump('SHOW CREATE TABLE authorization_role', rows($pdo, "SHOW CREATE TABLE authorization_role"));

dump('admin_user com role_id 189/191 no campo user_id (procura cruzada)', rows($pdo,
    "SELECT user_id, username FROM admin_user WHERE user_id IN (2,153)"));

dump('roles cujo user_id NAO existe em admin_user (todos os orfaos)', rows($pdo,
    "SELECT r.role_id, r.role_name, r.user_id, r.role_type
     FROM authorization_role r LEFT JOIN admin_user u ON u.user_id = r.user_id
     WHERE r.user_id IS NOT NULL AND u.user_id IS NULL ORDER BY r.role_id"));

dump('total de linhas', rows($pdo,
    "SELECT (SELECT COUNT(*) FROM authorization_role) AS roles,
            (SELECT COUNT(*) FROM authorization_rule) AS regras"));
