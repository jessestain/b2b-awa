<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model;

use Magento\Framework\App\RequestInterface;

/**
 * Verifica rotas permitidas para usuários restritos ao cockpit comercial.
 */
class CockpitAccessGuard
{
    private const MODULE_AWA_COMMERCIAL = 'awa_commercial';

    /** @var string[] */
    private const ALLOWED_FULL_PATHS = [
        // Magento backend route id is "adminhtml" (not "admin").
        'adminhtml/auth/logout',
        'adminhtml/auth/deniedcookie',
        'adminhtml/denied/index',
        'adminhtml/noroute/index',
        // Legacy aliases kept for safety.
        'admin/auth/logout',
        'admin/auth/deniedcookie',
        'admin/denied/index',
        'admin/noroute/index',
        'mui/index/render',
        'mui/bookmark/save',
        'mui/bookmark/delete',
        'mui/export/gridToCsv',
        'mui/export/gridToXml',
    ];

    /** @var string[] frontName das rotas permitidas (não confundir com getModuleName()) */
    private const ALLOWED_ROUTE_NAMES = [
        'awa_commercial',
    ];

    /**
     * Operação comercial no Admin Magento: criar/ver pedido e ficha do cliente.
     * Prefixo de route/controller[/action] em minúsculas.
     *
     * @var string[]
     */
    private const ALLOWED_PATH_PREFIXES = [
        'sales/order_create',
        'sales/order/view',
        'sales/order/index',
        'customer/index/edit',
        'customer/index/index',
        'loginascustomer',
    ];

    public function isRequestAllowed(RequestInterface $request): bool
    {
        $routeName = strtolower((string) $request->getRouteName());
        $controllerName = strtolower((string) $request->getControllerName());
        $actionName = strtolower((string) $request->getActionName());
        $fullPath = $routeName . '/' . $controllerName . '/' . $actionName;

        if (in_array($fullPath, self::ALLOWED_FULL_PATHS, true)) {
            return true;
        }

        if (in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return true;
        }

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if ($fullPath === $prefix || str_starts_with($fullPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
