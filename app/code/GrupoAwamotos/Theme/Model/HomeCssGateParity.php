<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;

/**
 * Fase 6 fatia 1 — paridade entre HOME_GATE (plugin) e pipeline Magento (layout/preload).
 *
 * O plugin {@see \GrupoAwamotos\Theme\Plugin\Response\OptimizeHeadStylesPlugin}
 * continua com regex de safety-net. Esta classe documenta o espelhamento em
 * cms_index_index.xml e o seed explícito da fila em awa-head-preload.phtml.
 */
final class HomeCssGateParity
{
    /**
     * Fragmentos ativos na fila idle da home (medição live 2026-08-07).
     * Seed explícito no preload cobre L2C + status-panel (B2B).
     * align-grid / refine / custom_default entram via plugin inject + HOME_GATE.
     *
     * @var list<string>
     */
    public const ACTIVE_GATE_FRAGMENTS = [
        // 51.58: align-grid moved to homeNeverGate (media=all; home css-gate stripped).
        'awa-commerce-impeccable-refine',
        'custom_default.css',
        'product/login-to-cart',
        'css/header/status-panel',
        'awa-b2b-status-panel',
    ];

    /**
     * Seeds emitidos por awa-head-preload.phtml (não incluir align-grid/refine —
     * o plugin injeta essas URLs e merge por string exata duplicava relativa vs absoluta).
     *
     * @var list<string>
     */
    public const PRELOAD_SEED_FRAGMENTS = [
        'product/login-to-cart',
        'css/header/status-panel',
        'awa-b2b-status-panel',
    ];

    /**
     * PageConfig &lt;remove src&gt; espelhados em cms_index_index.xml (Fase 6 fatia 1).
     * Inclui folhas mortas/safety-net + ativos ainda emitidos por Magento/phtml.
     *
     * @var list<string>
     */
    public const LAYOUT_REMOVE_SRCS = [
        'css/awa-home-hover-lock.min.css',
        'css/awa-home-hover-lock.css',
        'css/awa-impeccable-audit-2026-05-28.min.css',
        'css/awa-impeccable-audit-2026-05-28.css',
        'css/awa-home-flex-grid-flow.min.css',
        'css/awa-head-tail-bundle.min.css',
        'css/awa-defer-global-bundle.min.css',
        'css/awa-ui-simplify-terminal.min.css',
        'css/awa-home-standardize-terminal-wins-2026-06-09.min.css',
        'css/awa-footer-terminal-lock-v1.min.css',
        'css/awa-align-grid-terminal-2026-06-11.min.css',
        'css/awa-commerce-impeccable-refine.min.css',
        'GrupoAwamotos_B2B::css/product/login-to-cart.css',
        'GrupoAwamotos_B2B::css/header/status-panel.css',
        'css/awa-b2b-status-panel.min.css',
        'css/awa-b2b-status-panel.css',
    ];

    /**
     * @return list<string>
     */
    public static function missingLayoutRemoves(string $layoutXml): array
    {
        $missing = [];
        foreach (self::LAYOUT_REMOVE_SRCS as $src) {
            $needle = '<remove src="' . $src . '"/>';
            if (!str_contains($layoutXml, $needle)) {
                $missing[] = $src;
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $gateUrls
     * @return list<string>
     */
    public static function missingActiveGateFragments(array $gateUrls): array
    {
        $joined = implode("\n", $gateUrls);
        $missing = [];
        foreach (self::ACTIVE_GATE_FRAGMENTS as $fragment) {
            if (!str_contains($joined, $fragment)) {
                $missing[] = $fragment;
            }
        }

        return $missing;
    }
}
