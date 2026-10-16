<?php
/**
 * task-37 — VERIFICACAO FINAL (pos-mudanca). SOMENTE LEITURA.
 * Cobre os 3 problemas. Saida pensada para virar evidencia bruta.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

function t($label, callable $fn)
{
    try {
        echo "OK   $label => " . $fn() . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $label => " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

echo "############ A) Mirasvit_ReportApi (fora do config.php) ############\n";
$cr = $om->get('Magento\\Framework\\Component\\ComponentRegistrar');
echo 'ComponentRegistrar MODULE  Mirasvit_ReportApi => ' . var_export($cr->getPath('module', 'Mirasvit_ReportApi'), true) . "\n";
echo 'ComponentRegistrar LIBRARY Mirasvit_ReportApi => ' . var_export($cr->getPath('library', 'Mirasvit_ReportApi'), true) . "\n";
echo 'ComponentRegistrar MODULE  Mirasvit_Report    => ' . var_export($cr->getPath('module', 'Mirasvit_Report'), true) . "\n";
echo "etc/ do ReportApi: " . implode(', ', array_map('basename', glob($root . '/app/code/Mirasvit/ReportApi/etc/*'))) . "\n";
echo 'tem Setup/ ? ' . var_export(is_dir($root . '/app/code/Mirasvit/ReportApi/Setup'), true) . "\n";
echo 'tem di.xml/events.xml/webapi.xml/acl.xml ? ' . var_export(
    (bool)array_intersect(['di.xml', 'events.xml', 'webapi.xml', 'acl.xml', 'db_schema.xml'], array_map('basename', glob($root . '/app/code/Mirasvit/ReportApi/etc/*'))),
    true
) . "\n";
foreach ([
    'Mirasvit\\ReportApi\\Api\\SchemaInterface',
    'Mirasvit\\ReportApi\\Api\\RequestBuilderInterface',
    'Mirasvit\\ReportApi\\Api\\RequestInterface',
    'Mirasvit\\ReportApi\\Api\\ResponseInterface',
    'Mirasvit\\ReportApi\\Api\\Service\\TableServiceInterface',
] as $iface) {
    t("DI $iface", function () use ($om, $iface) {
        return get_class($om->get($iface));
    });
}
$schema = $om->get('Mirasvit\\ReportApi\\Api\\SchemaInterface');
echo 'Schema: ' . count($schema->getTables()) . ' tabelas, sales_order com ' . count($schema->getTable('sales_order')->getColumns()) . " colunas\n";
t('ponta a ponta sales_order', function () use ($om) {
    $b = $om->get('Mirasvit\\ReportApi\\Api\\RequestBuilderInterface');
    $r = $b->create();
    $r->setTable('sales_order');
    $r->setColumns(['entity_id', 'increment_id']);
    $r->setPageSize(3);
    $r->setCurrentPage(1);
    $resp = $r->process();
    $rows = [];
    foreach ($resp->getItems() as $it) {
        $rows[] = json_encode($it->getData());
    }
    return count($rows) . ' linhas :: ' . implode(' | ', $rows);
});

echo "\n############ B) StoreSetup x Rokanthemes_Faq ############\n";
$mx = $root . '/app/code/GrupoAwamotos/StoreSetup/etc/module.xml';
echo "module.xml sha256 = " . hash_file('sha256', $mx) . ' (' . filesize($mx) . " bytes)\n";
echo "module.xml conteudo:\n" . file_get_contents($mx) . "\n";
echo "referencias a Rokanthemes_Faq no module.xml: " . substr_count(file_get_contents($mx), 'Rokanthemes_Faq') . "\n";
$enabled = $om->get('Magento\\Framework\\Module\\ModuleList')->getNames();
$cfg = include $root . '/app/etc/config.php';
$cfgOn = [];
foreach ($cfg['modules'] as $n => $on) {
    if ($on) {
        $cfgOn[] = $n;
    }
}
echo 'enabled=' . count($enabled) . ' configOn=' . count($cfgOn) . ' MESMA ORDEM? ' . var_export($enabled === $cfgOn, true) . "\n";
$iS = array_search('GrupoAwamotos_StoreSetup', $enabled, true);
echo "StoreSetup idx=$iS vizinhos: " . implode(', ', array_slice($enabled, max(0, $iS - 2), 5)) . "\n";
echo 'StoreSetup enabled? ' . var_export($om->get('Magento\\Framework\\Module\\ModuleList')->has('GrupoAwamotos_StoreSetup'), true) . "\n";
echo 'Rokanthemes_Faq enabled? ' . var_export($om->get('Magento\\Framework\\Module\\ModuleList')->has('Rokanthemes_Faq'), true) . "\n";
$dc = $om->get('Magento\\Framework\\Module\\DependencyChecker');
echo 'checkDependenciesWhenDisableModules([Rokanthemes_Faq]) => ' . json_encode($dc->checkDependenciesWhenDisableModules(['Rokanthemes_Faq'])) . "\n";
echo 'checkDependenciesWhenEnableModules([StoreSetup])    => ' . json_encode($dc->checkDependenciesWhenEnableModules(['GrupoAwamotos_StoreSetup'])) . "\n";
$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($pdo->query("SELECT module, schema_version, data_version FROM setup_module WHERE module IN ('Rokanthemes_Faq','GrupoAwamotos_StoreSetup')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo 'setup_module: ' . json_encode($r) . "\n";
}
echo 'rokan_faq total = ' . $pdo->query('SELECT COUNT(*) FROM rokan_faq')->fetchColumn() . "\n";
echo 'cms_page faq = ' . json_encode($pdo->query("SELECT page_id, identifier, is_active FROM cms_page WHERE identifier='faq'")->fetchAll(PDO::FETCH_ASSOC)) . "\n";

echo "\n############ C) papeis de ACL orfaos ############\n";
echo 'authorization_role total = ' . $pdo->query('SELECT COUNT(*) FROM authorization_role')->fetchColumn() . "\n";
echo 'authorization_rule total = ' . $pdo->query('SELECT COUNT(*) FROM authorization_rule')->fetchColumn() . "\n";
echo 'linhas 189/191 em authorization_role = ' . $pdo->query('SELECT COUNT(*) FROM authorization_role WHERE role_id IN (189,191)')->fetchColumn() . "\n";
echo 'linhas 189/191 em authorization_rule = ' . $pdo->query('SELECT COUNT(*) FROM authorization_rule WHERE role_id IN (189,191)')->fetchColumn() . "\n";
echo 'papeis tipo U orfaos = ' . count($pdo->query("SELECT r.role_id FROM authorization_role r LEFT JOIN admin_user u ON u.user_id=r.user_id WHERE r.role_type='U' AND u.user_id IS NULL")->fetchAll()) . "\n";
echo 'papeis com parent inexistente = ' . count($pdo->query("SELECT r.role_id FROM authorization_role r LEFT JOIN authorization_role p ON p.role_id=r.parent_id WHERE r.parent_id<>0 AND p.role_id IS NULL")->fetchAll()) . "\n";
echo 'admins ativos = ' . $pdo->query('SELECT COUNT(*) FROM admin_user WHERE is_active=1')->fetchColumn() . "\n";
$fks = $pdo->query("SELECT CONSTRAINT_NAME, TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'authorization_role'")->fetchAll(PDO::FETCH_ASSOC);
echo 'FK de entrada: ' . json_encode($fks) . "\n";
