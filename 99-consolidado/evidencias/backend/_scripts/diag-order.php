<?php
/** B/A — ordem dos modulos habilitados vs config.php, e conteudo da pagina FAQ. SOMENTE LEITURA. */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$enabled = $om->get('Magento\\Framework\\Module\\ModuleList')->getNames();
$cfg = include $root . '/app/etc/config.php';
$cfgOn = [];
foreach ($cfg['modules'] as $n => $on) {
    if ($on) {
        $cfgOn[] = $n;
    }
}

echo 'enabled count = ' . count($enabled) . ' | config.php on = ' . count($cfgOn) . "\n";
echo 'MESMA ORDEM? ' . var_export($enabled === $cfgOn, true) . "\n";
if ($enabled !== $cfgOn) {
    echo "primeiras divergencias:\n";
    for ($i = 0; $i < max(count($enabled), count($cfgOn)); $i++) {
        $a = $enabled[$i] ?? '-';
        $b = $cfgOn[$i] ?? '-';
        if ($a !== $b) {
            echo "  [$i] enabled=$a  config=$b\n";
            if ($i > 30) { break; }
        }
    }
}
$iS = array_search('GrupoAwamotos_StoreSetup', $enabled, true);
echo "StoreSetup idx no enabled = " . var_export($iS, true) . "\n";
echo "vizinhos: " . implode(', ', array_slice($enabled, max(0, $iS - 3), 7)) . "\n";

$env = include $root . '/app/etc/env.php';
$d = $env['db']['connection']['default'];
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['dbname']), $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $pdo->query("SELECT page_id, identifier, is_active, CHAR_LENGTH(content) AS len,
    (LOCATE('Rokanthemes', content) > 0) AS usa_rokanthemes,
    (LOCATE('rokan_faq', content) > 0) AS usa_rokan_faq,
    (LOCATE('{{block', content) > 0) AS tem_block
    FROM cms_page WHERE identifier = 'faq' ORDER BY page_id")->fetchAll(PDO::FETCH_ASSOC);
echo "\n### cms_page faq\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
$blk = $pdo->query("SELECT page_id, SUBSTRING(content, GREATEST(1, LOCATE('{{block', content) - 20), 160) AS trecho
    FROM cms_page WHERE identifier='faq' AND LOCATE('{{block', content) > 0")->fetchAll(PDO::FETCH_ASSOC);
echo "\n### trechos com {{block}}\n";
foreach ($blk as $r) {
    echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
