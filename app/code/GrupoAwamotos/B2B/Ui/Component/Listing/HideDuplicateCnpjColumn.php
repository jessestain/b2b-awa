<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\Component\Listing;

/**
 * Force the duplicate CNPJ column hidden in customer grid config trees.
 */
final class HideDuplicateCnpjColumn
{
    public const COLUMN = 'b2b_cnpj';

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function inBookmarkConfig(array $config): array
    {
        if (isset($config['current']['columns'][self::COLUMN]) && is_array($config['current']['columns'][self::COLUMN])) {
            $config['current']['columns'][self::COLUMN]['visible'] = false;
        }

        if (!isset($config['views']) || !is_array($config['views'])) {
            return $config;
        }

        return self::hideInViews($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function hideInViews(array $config): array
    {
        foreach ($config['views'] as $id => $view) {
            if (is_array($view)) {
                $config['views'][$id] = self::hideInView($view);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>
     */
    private static function hideInView(array $view): array
    {
        if (isset($view['data']['columns'][self::COLUMN]) && is_array($view['data']['columns'][self::COLUMN])) {
            $view['data']['columns'][self::COLUMN]['visible'] = false;
        }
        if (isset($view['columns'][self::COLUMN]) && is_array($view['columns'][self::COLUMN])) {
            $view['columns'][self::COLUMN]['visible'] = false;
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function inColumnConfig(array $config): array
    {
        $config['visible'] = false;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function isCnpjVisible(array $config): ?bool
    {
        if (isset($config['current']['columns'][self::COLUMN]['visible'])) {
            return (bool) $config['current']['columns'][self::COLUMN]['visible'];
        }
        if (isset($config['visible'])) {
            return (bool) $config['visible'];
        }

        return null;
    }
}
