<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;

/**
 * Single source of truth for minicart storefront asset cache-bust tokens.
 *
 * Magento already versions static URLs via pub/static/deployed_version.txt
 * (/static/versionNNNN/...). The query token below is an intentional extra
 * bust for scripts loaded outside RequireJS merge or for KO templates that
 * cache aggressively. Keep RequireJS map/path query strings in sync manually
 * with RUNTIME when they must keep a ?v= suffix.
 */
final class MinicartAssetVersion
{
    /**
     * Bump when publishing minicart JS/PHTML that browsers may keep by query.
     */
    public const RUNTIME = '20260903-navfix-r1';

    public static function query(): string
    {
        return '?v=' . rawurlencode(self::RUNTIME);
    }

    public static function runtime(): string
    {
        return self::RUNTIME;
    }
}
