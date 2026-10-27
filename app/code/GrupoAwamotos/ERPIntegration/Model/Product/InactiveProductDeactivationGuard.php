<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Product;

/**
 * Prevents wiping the Magento catalog when the Sectra material query is empty.
 *
 * An empty MT_MATERIAL result is treated as a connectivity/query failure, not as
 * "every SKU was discontinued".
 */
final class InactiveProductDeactivationGuard
{
    public static function shouldDeactivate(int $allErpCount, int $activeErpCount): bool
    {
        return $allErpCount > 0 && $activeErpCount > 0;
    }
}
