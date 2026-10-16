<?php
/**
 * PROBLEMA C — remove os 2 papeis de ACL orfaos (role_id 189 e 191).
 * Pre-condicoes verificadas em runtime; aborta se qualquer uma falhar.
 * A FK authorization_rule.role_id -> authorization_role.role_id e ON DELETE CASCADE.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ids = [189, 191];

// --- PRE-CONDICOES ---
echo "=== PRE-CONDICOES ===\n";
$roles = $pdo->query('SELECT role_id, role_name, user_id, role_type, parent_id FROM authorization_role WHERE role_id IN (189,191)')->fetchAll(PDO::FETCH_ASSOC);
foreach ($roles as $r) {
    echo json_encode($r) . "\n";
}
if (count($roles) !== 2) {
    fwrite(STDERR, "ABORTA: esperava 2 papeis\n");
    exit(1);
}
foreach ($roles as $r) {
    if ($r['role_type'] !== 'U') {
        fwrite(STDERR, "ABORTA: role {$r['role_id']} nao e do tipo U\n");
        exit(2);
    }
    $u = $pdo->query('SELECT COUNT(*) FROM admin_user WHERE user_id = ' . (int)$r['user_id'])->fetchColumn();
    if ((int)$u !== 0) {
        fwrite(STDERR, "ABORTA: admin_user {$r['user_id']} EXISTE\n");
        exit(3);
    }
}
$kids = $pdo->query('SELECT COUNT(*) FROM authorization_role WHERE parent_id IN (189,191)')->fetchColumn();
if ((int)$kids !== 0) {
    fwrite(STDERR, "ABORTA: existem $kids papeis filhos\n");
    exit(4);
}
$fks = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'authorization_role'")->fetchAll(PDO::FETCH_ASSOC);
echo "FKs de entrada: " . json_encode($fks) . "\n";

$rAntes = (int)$pdo->query('SELECT COUNT(*) FROM authorization_role')->fetchColumn();
$uAntes = (int)$pdo->query('SELECT COUNT(*) FROM authorization_rule')->fetchColumn();
echo "ANTES: authorization_role=$rAntes authorization_rule=$uAntes\n";

// --- ACAO (transacional) ---
$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM authorization_role WHERE role_id IN (189,191)');
    $del->execute();
    echo 'linhas deletadas em authorization_role = ' . $del->rowCount() . "\n";
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ABORTA (rollback): ' . $e->getMessage() . "\n");
    exit(5);
}

// --- POS-CONDICOES ---
echo "\n=== DEPOIS ===\n";
$rDepois = (int)$pdo->query('SELECT COUNT(*) FROM authorization_role')->fetchColumn();
$uDepois = (int)$pdo->query('SELECT COUNT(*) FROM authorization_rule')->fetchColumn();
echo "authorization_role=$rDepois (delta " . ($rDepois - $rAntes) . ") authorization_rule=$uDepois (delta " . ($uDepois - $uAntes) . ")\n";
$sobraram = $pdo->query('SELECT COUNT(*) FROM authorization_role WHERE role_id IN (189,191)')->fetchColumn();
echo "linhas 189/191 restantes = $sobraram\n";
$regras = $pdo->query('SELECT COUNT(*) FROM authorization_rule WHERE role_id IN (189,191)')->fetchColumn();
echo "regras 189/191 restantes = $regras\n";
$orf = $pdo->query("SELECT r.role_id, r.role_name, r.user_id FROM authorization_role r
    LEFT JOIN admin_user u ON u.user_id = r.user_id
    WHERE r.role_type='U' AND u.user_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
echo "papeis tipo U orfaos restantes = " . count($orf) . " " . json_encode($orf) . "\n";
$orfG = $pdo->query("SELECT r.role_id, r.role_name, r.user_id FROM authorization_role r
    LEFT JOIN admin_user u ON u.user_id = r.user_id
    WHERE r.role_type='G' AND r.user_id <> 0 AND u.user_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
echo "papeis tipo G com user_id inexistente = " . count($orfG) . " " . json_encode($orfG) . "\n";
$pais = $pdo->query("SELECT r.role_id, r.role_name FROM authorization_role r
    LEFT JOIN authorization_role p ON p.role_id = r.parent_id
    WHERE r.parent_id <> 0 AND p.role_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
echo "papeis com parent inexistente = " . count($pais) . " " . json_encode($pais) . "\n";
echo "\narvore final (role_id|parent|tipo|user|nome):\n";
foreach ($pdo->query('SELECT role_id, parent_id, role_type, user_id, role_name FROM authorization_role ORDER BY parent_id, role_id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '  ' . implode(' | ', $r) . "\n";
}
