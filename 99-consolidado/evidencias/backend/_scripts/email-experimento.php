<?php
/**
 * task-42 — EXPERIMENTO DECISIVO:
 * o 'area' do setTemplateOptions muda o themeModel que o Magento usa para achar o header.html?
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$design = $om->get('Magento\\Framework\\View\\DesignInterface');
echo "DesignInterface: area atual='" . $design->getArea() . "' tema='" . $design->getDesignTheme()->getFullPath() . "'\n\n";

$resolver = $om->get('Magento\\Framework\\View\\Design\\FileResolution\\Fallback\\EmailTemplateFile');

foreach (['frontend', 'adminhtml'] as $area) {
    $t = $om->create('Magento\\Email\\Model\\Template');
    $t->setDesignConfig(['area' => $area, 'store' => 1]);
    $p = $t->getDesignParams();
    echo "=== setDesignConfig(area=$area) ===\n";
    echo "  getDesignParams()['area']       = " . $p['area'] . "\n";
    echo "  getDesignParams()['themeModel'] = " . $p['themeModel']->getFullPath() . "\n";
    $r = $resolver->getFile($p['area'], $p['themeModel'], $p['locale'] ?? 'pt_BR', 'header.html', 'Magento_Email');
    echo "  resolucao de header.html        = " . ($r === false ? '*** FALSE ***' : $r) . "\n\n";
}

echo "=== e se a AREA do DesignInterface for trocada antes? ===\n";
foreach (['frontend', 'adminhtml'] as $designArea) {
    $design->setArea($designArea);
    echo "  -- design->setArea('$designArea') => tema '" . $design->getDesignTheme()->getFullPath() . "'\n";
    foreach (['frontend', 'adminhtml'] as $area) {
        $t = $om->create('Magento\\Email\\Model\\Template');
        $t->setDesignConfig(['area' => $area, 'store' => 1]);
        $p = $t->getDesignParams();
        $r = $resolver->getFile($p['area'], $p['themeModel'], 'pt_BR', 'header.html', 'Magento_Email');
        printf("       area=%-10s tema=%-38s -> %s\n", $area, $p['themeModel']->getFullPath(), $r === false ? 'FALSE' : 'OK');
    }
}
