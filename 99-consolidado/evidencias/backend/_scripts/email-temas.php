<?php
/** task-42 — qual tema cada store usa, e quais temas tem Magento_Email/email/header.html. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function dump(string $t, array $r): void
{
    echo "\n### $t\n";
    if (!$r) { echo "(vazio)\n"; return; }
    echo implode(' | ', array_keys($r[0])) . "\n";
    foreach ($r as $row) {
        echo implode(' | ', array_map(static fn($v) => $v === null ? 'NULL' : (string)$v, $row)) . "\n";
    }
}

dump('design/theme/theme_id por escopo', $pdo->query(
    "SELECT config_id, scope, scope_id, path, value FROM core_config_data WHERE path LIKE 'design/theme/%' OR path LIKE 'design/package/%' ORDER BY path, scope_id"
)->fetchAll(PDO::FETCH_ASSOC));

dump('temas instalados', $pdo->query(
    "SELECT theme_id, parent_id, theme_path, theme_title, is_featured, area FROM theme ORDER BY area, theme_path"
)->fetchAll(PDO::FETCH_ASSOC));

echo "\n### quais temas tem Magento_Email/email/header.html (theme + parentes)\n";
$rows = $pdo->query("SELECT theme_id, parent_id, theme_path, area FROM theme ORDER BY area, theme_path")->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($rows as $r) {
    $byId[$r['theme_id']] = $r;
}
// vendor themes vivem em vendor/magento/theme-*
$vendorMap = [
    'frontend/Magento/luma' => 'vendor/magento/theme-frontend-luma',
    'frontend/Magento/blank' => 'vendor/magento/theme-frontend-blank',
    'adminhtml/Magento/backend' => 'vendor/magento/theme-adminhtml-backend',
];
foreach ($rows as $r) {
    $path = $r['theme_path'];
    if (strpos($path, 'AWA_Custom') !== false || strpos($path, 'Rokanthemes') !== false || strpos($path, 'ayo') !== false) {
        $dir = $root . '/app/design/' . $path;
    } elseif (isset($vendorMap[$path])) {
        $dir = $root . '/' . $vendorMap[$path];
    } else {
        $dir = $root . '/app/design/' . $path;
    }
    $has = is_file($dir . '/Magento_Email/email/header.html');
    printf("  theme_id=%-4s %-45s area=%-10s header.html=%s\n", $r['theme_id'], $path, $r['area'], $has ? 'SIM' : 'NAO');
    // subir a cadeia de pais
    $p = $r['parent_id'];
    $guard = 0;
    while ($p && isset($byId[$p]) && $guard++ < 5) {
        $pp = $byId[$p];
        $pdir = (strpos($pp['theme_path'], 'Magento/') === 0) ? $vendorMap[$pp['theme_path']] ?? '' : 'app/design/' . $pp['theme_path'];
        $pfull = (strpos($pdir, 'vendor') === 0) ? $root . '/' . $pdir : $root . '/' . $pdir;
        printf("        pai: %-40s header.html=%s\n", $pp['theme_path'], is_file($pfull . '/Magento_Email/email/header.html') ? 'SIM' : 'NAO');
        $p = $pp['parent_id'];
    }
}

echo "\n### IMPACTO: registros B2B pendentes x falhas de e-mail\n";
$pdo2 = $pdo->query("SELECT COUNT(*) n FROM email_template")->fetchColumn();
echo "  (email_template total = $pdo2)\n";
