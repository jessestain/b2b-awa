<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Plugin;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\Platform\Model\Config\PlatformConfig;
use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Config;

/**
 * Oculta menus técnicos do admin para usuários restritos ao cockpit.
 */
class MenuRestrictionPlugin
{
    private const COMMERCIAL_ROOT_ID = 'GrupoAwamotos_B2B::commercial';
    private const PLATFORM_ROOT_ID = 'GrupoAwamotos_B2B::platform';

    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly PlatformConfig $platformConfig
    ) {
    }

    public function afterGetMenu(Config $subject, Menu $menu): Menu
    {
        if ($this->portfolioScope->canViewAllPortfolios()) {
            $this->applySupervisorMenuLabels($menu);
        }

        if (!$this->portfolioScope->isCockpitOnlyUser()) {
            return $menu;
        }

        // Vendedoras cockpit-only: menu AWA Comercial (itens com ACL comercial).
        // Menu unificado B2B usa pais platform_* que vendedoras não possuem — sidebar fica vazia.
        $keepRootId = self::PLATFORM_ROOT_ID;

        foreach ($menu as $item) {
            if ($item->getId() !== $keepRootId) {
                $menu->remove($item->getId());
            }
        }

        return $menu;
    }

    private function applySupervisorMenuLabels(Menu $menu): void
    {
        $commercial = $menu->get(self::COMMERCIAL_ROOT_ID);
        if ($commercial === null) {
            return;
        }

        $labels = [
            'GrupoAwamotos_B2B::commercial_dashboard' => (string) __('Painel da Equipe'),
            'GrupoAwamotos_B2B::commercial_portfolio' => (string) __('Carteira da Equipe'),
            'GrupoAwamotos_B2B::commercial_tasks' => (string) __('Tarefas da Equipe'),
            'GrupoAwamotos_B2B::commercial_pending' => (string) __('Clientes da Equipe'),
        ];

        foreach ($commercial as $child) {
            $id = (string) $child->getId();
            if (isset($labels[$id])) {
                $child->setTitle($labels[$id]);
            }
        }
    }
}
