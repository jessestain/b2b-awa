<?php
/** PROBLEMA B — dependencia viva? dump cru. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$checker = $om->get('Magento\\Framework\\Module\\DependencyChecker');

foreach ([
    'checkDependenciesWhenDisableModules([Rokanthemes_Faq])' => fn() => $checker->checkDependenciesWhenDisableModules(['Rokanthemes_Faq']),
    'checkDependenciesWhenDisableModules([StoreSetup])' => fn() => $checker->checkDependenciesWhenDisableModules(['GrupoAwamotos_StoreSetup']),
    'checkDependenciesWhenEnableModules([StoreSetup])' => fn() => $checker->checkDependenciesWhenEnableModules(['GrupoAwamotos_StoreSetup']),
] as $label => $fn) {
    echo "=== $label ===\n";
    echo 'count=' . count($fn()) . "\n";
    echo json_encode($fn(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
}

echo "=== ModuleList (habilitados) tem Rokanthemes_Faq? ===\n";
$list = $om->get('Magento\\Framework\\Module\\ModuleList');
echo 'Rokanthemes_Faq enabled? ' . var_export($list->has('Rokanthemes_Faq'), true) . "\n";
echo 'StoreSetup enabled? ' . var_export($list->has('GrupoAwamotos_StoreSetup'), true) . "\n";
echo 'Mirasvit_Report enabled? ' . var_export($list->has('Mirasvit_Report'), true) . "\n";
