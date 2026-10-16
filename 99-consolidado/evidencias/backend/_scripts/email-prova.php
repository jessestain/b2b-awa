<?php
/**
 * task-42 — PROVA da causa-raiz: a resolucao do header.html depende da AREA.
 * SOMENTE LEITURA.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

echo "=== 1) existencia dos diretorios de e-mail por area ===\n";
foreach (['frontend', 'adminhtml'] as $a) {
    $d = $root . "/vendor/magento/module-email/view/$a/email";
    printf("  Magento_Email/view/%-10s/email  %s\n", $a, is_dir($d) ? 'EXISTE (' . implode(', ', array_map('basename', glob($d . '/*.html'))) . ')' : '*** NAO EXISTE ***');
}
$th = $root . '/app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Email/email';
echo '  tema AWA_Custom/ayo_home5_child/Magento_Email/email  ' . (is_dir($th) ? 'EXISTE (' . implode(', ', array_map('basename', glob($th . '/*.html'))) . ')' : 'NAO EXISTE') . "\n";

echo "\n=== 2) a REGRA de fallback (RulePool) ===\n";
echo "  <theme_dir>/<module_name>/email   E   <module_dir>/view/<area>/email\n";

echo "\n=== 3) resolucao REAL por area (mesma classe que o Magento usa) ===\n";
$design = $om->get('Magento\\Framework\\View\\DesignInterface');
$design->setArea('frontend');
$design->setDesignTheme('AWA_Custom/ayo_home5_child');
$theme = $design->getDesignTheme();
echo '  tema resolvido: ' . $theme->getFullPath() . "\n";
$resolver = $om->get('Magento\\Framework\\View\\Design\\FileResolution\\Fallback\\EmailTemplateFile');
foreach (['frontend', 'adminhtml'] as $area) {
    foreach (['header.html', 'footer.html'] as $file) {
        $r = $resolver->getFile($area, $theme, 'pt_BR', $file, 'Magento_Email');
        printf("  area=%-10s %-12s -> %s\n", $area, $file, $r === false ? '*** FALSE (dispara a excecao) ***' : $r);
    }
}

echo "\n=== 4) o tema ADMIN (usado quando area=adminhtml) tem o arquivo? ===\n";
$design->setArea('adminhtml');
$design->setDesignTheme('Magento/backend');
$adminTheme = $design->getDesignTheme();
echo '  tema admin: ' . $adminTheme->getFullPath() . "\n";
foreach (['header.html', 'footer.html'] as $file) {
    $r = $resolver->getFile('adminhtml', $adminTheme, 'pt_BR', $file, 'Magento_Email');
    printf("  area=adminhtml tema=backend %-12s -> %s\n", $file, $r === false ? '*** FALSE ***' : $r);
}

echo "\n=== 5) o template do B2B existe no banco e usa o header? ===\n";
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $pdo->prepare("SELECT template_id, template_code, template_type, orig_template_code, LENGTH(template_text) len,
    (LOCATE('design/email/header_template', template_text) > 0) usa_header,
    (LOCATE('design/email/footer_template', template_text) > 0) usa_footer
    FROM email_template WHERE template_code = ?");
$st->execute(['grupoawamotos_b2b_admin_new_customer']);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
echo "\n  -- quantos templates no banco usam o header --\n";
foreach ($pdo->query("SELECT COUNT(*) total, SUM(LOCATE('design/email/header_template', template_text) > 0) com_header
                      FROM email_template")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '  ' . json_encode($r) . "\n";
}
echo "\n  -- colunas de email_template --\n";
foreach ($pdo->query('SHOW COLUMNS FROM email_template')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['Field']} | {$r['Type']} | null={$r['Null']}\n";
}
echo "\n  -- existem design_email_header_template / footer? --\n";
foreach ($pdo->query("SELECT template_id, template_code FROM email_template WHERE template_code IN ('design_email_header_template','design_email_footer_template')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '  ' . json_encode($r) . "\n";
}
echo "  (vazio acima = nao existem)\n";

echo "\n=== 6) as linhas de config apontadas pelo lead ===\n";
foreach ($pdo->query("SELECT config_id, scope, scope_id, path, value FROM core_config_data WHERE config_id IN (3406,3407) OR path LIKE 'design/email/%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
