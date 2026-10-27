<?php
declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config;

class PaginationRobotsPlugin
{
    private const GRID_PER_PAGE = 'catalog/frontend/grid_per_page';
    private const LIST_PER_PAGE = 'catalog/frontend/list_per_page';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly Registry $registry,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function afterGetRobots(Config $subject, $result)
    {
        $page = (int) $this->request->getParam('p', 1);
        if ($page <= 1) {
            return $result;
        }

        $category = $this->registry->registry('current_category');
        if (!$category || !method_exists($category, 'getProductCollection')) {
            return $result;
        }

        try {
            $size = (int) $category->getProductCollection()->getSize();
        } catch (\Throwable $e) {
            return $result;
        }

        $limit = (int) $this->request->getParam('product_list_limit', 0);
        if ($limit <= 0) {
            $mode = (string) $this->request->getParam('product_list_mode', 'grid');
            $limit = (int) $this->scopeConfig->getValue($mode === 'list' ? self::LIST_PER_PAGE : self::GRID_PER_PAGE);
        }
        if ($limit <= 0) {
            $limit = 12;
        }

        $lastPage = (int) ceil($size / $limit);
        if ($lastPage < 1) {
            $lastPage = 1;
        }

        if ($page > $lastPage) {
            return 'NOINDEX,FOLLOW';
        }

        return $result;
    }
}
