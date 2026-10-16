<?php
/** Diagnostico 4b: como o composer resolve classes de app/code/<Vendor>. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
$loader = require $root . '/vendor/autoload.php';

echo "=== fallback dirs PSR-4 ===\n";
foreach ($loader->getFallbackDirsPsr4() as $d) {
    echo "  $d\n";
}
echo "=== findFile ===\n";
foreach ([
    'Mirasvit\\ReportApi\\Config\\Schema',
    'GrupoAwamotos\\B2B\\Helper\\Data',
] as $c) {
    printf("%-42s %s\n", $c, var_export($loader->findFile($c), true));
}
$p0 = $loader->getPrefixesPsr0();
echo "=== PSR-0: total " . count($p0) . " ===\n";
foreach ($p0 as $k => $v) {
    if ($k === '' || stripos((string)$k, 'mirasvit') !== false || stripos((string)$k, 'grupo') !== false) {
        printf("  '%s' => %s\n", (string)$k, implode(',', (array)$v));
    }
}
echo "classMapAuthoritative=" . var_export($loader->isClassMapAuthoritative(), true) . "\n";
$cm = $loader->getClassMap();
echo "classmap size=" . count($cm) . "\n";
foreach (['Mirasvit\\ReportApi\\Config\\Schema', 'GrupoAwamotos\\B2B\\Helper\\Data'] as $c) {
    printf("classmap[%s]=%s\n", $c, var_export($cm[$c] ?? null, true));
}
