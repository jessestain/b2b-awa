<?php
/**
 * PROBLEMA A — teste funcional ponta a ponta do Mirasvit_Report (ATIVO) usando o ReportApi.
 * SOMENTE LEITURA.
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

echo "=== 1) DI: preferences de ReportApi (declaradas em Mirasvit_Report/etc/di.xml) ===\n";
foreach ([
    'Mirasvit\\ReportApi\\Api\\SchemaInterface',
    'Mirasvit\\ReportApi\\Api\\RequestBuilderInterface',
    'Mirasvit\\ReportApi\\Api\\RequestInterface',
    'Mirasvit\\ReportApi\\Api\\ResponseInterface',
    'Mirasvit\\ReportApi\\Api\\Service\\TableServiceInterface',
    'Mirasvit\\Report\\Api\\Repository\\ReportRepositoryInterface',
] as $iface) {
    t($iface, function () use ($om, $iface) {
        return get_class($om->get($iface));
    });
}

echo "\n=== 2) Schema (le mst_report.xml pelo FileResolver do Magento) ===\n";
$schema = null;
t('getTables()', function () use ($om, &$schema) {
    $schema = $om->get('Mirasvit\\ReportApi\\Api\\SchemaInterface');
    return count($schema->getTables()) . ' tabelas';
});
t('hasTable(sales_order)', function () use (&$schema) {
    return var_export($schema->hasTable('sales_order'), true);
});
t('getTable(sales_order)->getColumns()', function () use (&$schema) {
    return count($schema->getTable('sales_order')->getColumns()) . ' colunas';
});
t('getType(money)', function () use (&$schema) {
    return get_class($schema->getType('money'));
});

echo "\n=== 3) Tabelas configuradas (amostra) ===\n";
if ($schema) {
    $names = [];
    foreach ($schema->getTables() as $tab) {
        $names[] = $tab->getName();
    }
    echo '  ' . implode(', ', array_slice($names, 0, 15)) . "\n";
}

echo "\n=== 4) Relatorios registrados (ReportRepository) ===\n";
t('getList()', function () use ($om) {
    $repo = $om->get('Mirasvit\\Report\\Api\\Repository\\ReportRepositoryInterface');
    $list = $repo->getList();
    $names = [];
    foreach ($list as $r) {
        $names[] = is_object($r) && method_exists($r, 'getIdentifier') ? $r->getIdentifier() : get_debug_type($r);
    }
    return count($names) . ' relatorios: ' . implode(', ', array_slice($names, 0, 15));
});
t('get("order")', function () use ($om) {
    $repo = $om->get('Mirasvit\\Report\\Api\\Repository\\ReportRepositoryInterface');
    $r = $repo->get('order');
    return is_object($r) ? get_class($r) : get_debug_type($r);
});

echo "\n=== 5) PONTA A PONTA: RequestBuilder -> process() -> linhas do banco ===\n";
t('sales_order: entity_id, increment_id (3 linhas)', function () use ($om) {
    $builder = $om->get('Mirasvit\\ReportApi\\Api\\RequestBuilderInterface');
    $request = $builder->create();
    $request->setTable('sales_order');
    $request->setColumns(['entity_id', 'increment_id']);
    $request->setPageSize(3);
    $request->setCurrentPage(1);
    $response = $request->process();
    $items = $response->getItems();
    $out = [];
    foreach ($items as $it) {
        $out[] = json_encode($it->getData());
    }
    return count($items) . ' linhas :: ' . implode(' | ', $out);
});
t('SQL gerado', function () use ($om) {
    $builder = $om->get('Mirasvit\\ReportApi\\Api\\RequestBuilderInterface');
    $request = $builder->create();
    $request->setTable('sales_order');
    $request->setColumns(['entity_id']);
    $request->setPageSize(1);
    return substr(preg_replace('/\s+/', ' ', (string)$request->getQuery()), 0, 220);
});
