<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Block\VerticalMenu;

use Magento\Framework\UrlInterface;

class SafeVerticalmenu extends \Rokanthemes\VerticalMenu\Block\Verticalmenu
{
    /**
     * Cache category models loaded for the current request.
     *
     * @var array<int, \Magento\Catalog\Model\Category>
     */
    private array $categoryModelCache = [];

    /**
     * Cache active children list by category id for the current request.
     *
     * @var array<int, array>
     */
    private array $activeChildrenCache = [];

    /**
     * Cache category frontend URLs by category id for the current request.
     *
     * @var array<int, string>
     */
    private array $categoryUrlCache = [];

    /**
     * Cache category image URLs by "attribute:categoryId" for the current request.
     *
     * @var array<string, string>
     */
    private array $categoryImageUrlCache = [];

    /**
     * Cache product counts per category id for the current request.
     *
     * @var array<int, int>
     */
    private array $categoryProductCountCache = [];

    /**
     * Cache CMS block content by identifier list for the current request.
     *
     * @var array<string, string>
     */
    private array $menuBlockContentCache = [];

    /**
     * @inheritDoc
     */
    public function getCategoryModel($id)
    {
        $categoryId = (int)$id;
        if ($categoryId > 0 && isset($this->categoryModelCache[$categoryId])) {
            return $this->categoryModelCache[$categoryId];
        }

        $category = parent::getCategoryModel($categoryId);

        if ($categoryId > 0 && $category && (int)$category->getId() > 0) {
            $this->categoryModelCache[$categoryId] = $category;
        }

        return $category;
    }

    /**
     * @inheritDoc
     */
    public function getActiveChildCategories($category)
    {
        $categoryId = (int)$category->getId();
        if ($categoryId > 0 && array_key_exists($categoryId, $this->activeChildrenCache)) {
            return $this->activeChildrenCache[$categoryId];
        }

        $children = parent::getActiveChildCategories($category);

        if ($categoryId > 0) {
            $this->activeChildrenCache[$categoryId] = $children;
        }

        return $children;
    }

    /**
     * Bulk-load menu category models to avoid per-item lazy loading.
     *
     * @param int[] $categoryIds
     */
    private function preloadCategoryModels(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $idsToLoad = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId <= 0 || isset($this->categoryModelCache[$categoryId])) {
                continue;
            }

            $idsToLoad[$categoryId] = $categoryId;
        }

        if ($idsToLoad === []) {
            return;
        }

        $collection = $this->_categoryFactory->create()->getCollection();
        $collection->setStoreId((int)$this->_storeManager->getStore()->getId());
        $collection->addAttributeToSelect([
            'name',
            'url_key',
            'image',
            'vc_menu_hide_item',
            'vc_menu_cat_label',
            'vc_menu_font_icon',
            'vc_menu_cat_columns',
            'vc_menu_float_type',
            'vc_menu_type',
            'vc_menu_static_width',
            'vc_menu_block_top_content',
            'vc_menu_block_left_content',
            'vc_menu_block_left_width',
            'vc_menu_block_right_content',
            'vc_menu_block_right_width',
            'vc_menu_block_bottom_content',
            'vc_menu_icon_img'
        ]);
        $collection->addAttributeToFilter('entity_id', ['in' => array_values($idsToLoad)]);

        foreach ($collection as $categoryModel) {
            $loadedId = (int)$categoryModel->getId();
            if ($loadedId > 0) {
                $this->categoryModelCache[$loadedId] = $categoryModel;
            }
        }
    }

    /**
     * Collect active category ids up to max menu depth for bulk preloading.
     *
     * @param array $categories
     * @return int[]
     */
    private function collectMenuCategoryIds(array $categories, int $maxLevel): array
    {
        $ids = [];

        foreach ($categories as $category) {
            if (!$category || !$category->getIsActive()) {
                continue;
            }

            $this->collectMenuCategoryIdsRecursive($category, 0, $maxLevel, $ids);
        }

        return array_keys($ids);
    }

    /**
     * @param mixed $category
     * @param array<int, bool> $ids
     */
    private function collectMenuCategoryIdsRecursive($category, int $level, int $maxLevel, array &$ids): void
    {
        $categoryId = (int)$category->getId();
        if ($categoryId <= 0 || isset($ids[$categoryId])) {
            return;
        }

        $ids[$categoryId] = true;

        if ($maxLevel > 0 && $level >= $maxLevel - 1) {
            return;
        }

        $children = $this->getActiveChildCategories($category);
        foreach ($children as $child) {
            $this->collectMenuCategoryIdsRecursive($child, $level + 1, $maxLevel, $ids);
        }
    }

    /**
     * Resolve and cache category URL.
     *
     * @param mixed $category
     */
    private function getCategoryUrlCached($category): string
    {
        $categoryId = (int)$category->getId();
        if ($categoryId > 0 && isset($this->categoryUrlCache[$categoryId])) {
            return $this->categoryUrlCache[$categoryId];
        }

        $url = (string)$this->_categoryHelper->getCategoryUrl($category);
        if ($categoryId > 0) {
            $this->categoryUrlCache[$categoryId] = $url;
        }

        return $url;
    }

    /**
     * Resolve and cache category image URL by attribute code.
     *
     * @param mixed $category
     */
    private function getCategoryImageUrlCached($category, string $attributeCode): string
    {
        $categoryId = (int)$category->getId();
        $cacheKey = $attributeCode . ':' . $categoryId;
        if ($categoryId > 0 && isset($this->categoryImageUrlCache[$cacheKey])) {
            return $this->categoryImageUrlCache[$cacheKey];
        }

        $image = (string)$category->getData($attributeCode);
        if ($image === '') {
            if ($categoryId > 0) {
                $this->categoryImageUrlCache[$cacheKey] = '';
            }

            return '';
        }

        try {
            $url = (string)$category->getImageUrl($attributeCode);
        } catch (\Exception $e) {
            $store = $this->_storeManager->getStore();
            $mediaUrl = '';
            if (is_object($store) && method_exists($store, 'getBaseUrl')) {
                $mediaUrl = (string)call_user_func([$store, 'getBaseUrl'], UrlInterface::URL_TYPE_MEDIA);
            }
            $url = $mediaUrl . 'catalog/category/' . ltrim($image, '/');
        }

        if ($categoryId > 0) {
            $this->categoryImageUrlCache[$cacheKey] = $url;
        }

        return $url;
    }

    /**
     * Resolve and cache category product count for the current request.
     *
     * @param mixed $category
     */
    private function getCategoryProductCountCached($category): int
    {
        $categoryId = (int)$category->getId();
        if ($categoryId > 0 && isset($this->categoryProductCountCache[$categoryId])) {
            return $this->categoryProductCountCache[$categoryId];
        }

        try {
            $productCount = (int)$category->getProductCount();
        } catch (\Exception $e) {
            $productCount = 0;
        }

        if ($categoryId > 0) {
            $this->categoryProductCountCache[$categoryId] = $productCount;
        }

        return $productCount;
    }

    private function getMenuBlockContentCached(string $blockIdentifier): string
    {
        if ($blockIdentifier === '') {
            return '';
        }

        if (array_key_exists($blockIdentifier, $this->menuBlockContentCache)) {
            return $this->menuBlockContentCache[$blockIdentifier];
        }

        $this->menuBlockContentCache[$blockIdentifier] = (string)$this->getBlockContent($blockIdentifier);

        return $this->menuBlockContentCache[$blockIdentifier];
    }

    /**
     * Render a CMS block by identifier using the current store context.
     */
    public function getCmsBlockHtmlByIdentifier(string $blockIdentifier): string
    {
        $blockIdentifier = trim($blockIdentifier);
        if ($blockIdentifier === '') {
            return '';
        }

        $cacheKey = 'cms:' . $blockIdentifier;
        if (array_key_exists($cacheKey, $this->menuBlockContentCache)) {
            return $this->menuBlockContentCache[$cacheKey];
        }

        $storeId = (int)$this->_storeManager->getStore()->getId();
        $block = $this->_blockFactory->create();
        $block->setStoreId($storeId)->load($blockIdentifier, 'identifier');

        if (!$block || !(bool)$block->getId() || !(bool)$block->getIsActive()) {
            $this->menuBlockContentCache[$cacheKey] = '';

            return '';
        }

        $blockContent = trim((string)$block->getContent());
        if ($blockContent === '') {
            $this->menuBlockContentCache[$cacheKey] = '';

            return '';
        }

        $content = trim((string)$this->_filterProvider->getBlockFilter()
            ->setStoreId($storeId)
            ->filter($blockContent));

        $this->menuBlockContentCache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Allow only safe tokens for CSS classes (single token).
     */
    private function sanitizeClassToken(?string $value): string
    {
        $value = (string)$value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_-]+/', '', $value) ?? '';

        return trim($value);
    }

    /**
     * Allow only safe tokens for CSS class lists (space separated).
     */
    private function sanitizeClassList(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $value) ?: [];
        $safe = [];

        foreach ($tokens as $token) {
            $token = $this->sanitizeClassToken($token);
            if ($token !== '') {
                $safe[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($safe)));
    }

    /**
     * Native submenu expand control (a11y: button, not div+role=button).
     */
    private function renderOpenChildrenToggle(string $ariaLabel, string $controlsId): string
    {
        return '<button type="button" class="open-children-toggle navigation__toggle"'
            . ' aria-label="' . $this->escapeHtmlAttr($ariaLabel) . '"'
            . ' aria-expanded="false"'
            . ' aria-haspopup="true"'
            . ' aria-controls="' . $this->escapeHtmlAttr($controlsId) . '"'
            . '></button>';
    }

    /**
     * Allow only safe css sizes (prevents style injection).
     * Examples allowed: 500px, 80%, 20rem, 10em, 50vw
     */
    private function sanitizeCssSize(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,4}(px|%|rem|em|vw)$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function clampGridColumns(int $value): int
    {
        return max(0, min(12, $value));
    }

    /**
     * Remove a single wrapping list container from CMS block output.
     */
    private function normalizeCustomBlockContent(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            return '';
        }

        if (preg_match('/^<ul\b[^>]*>(.*)<\/ul>$/is', $content, $matches) === 1) {
            $content = trim((string)($matches[1] ?? ''));
        }

        return $content;
    }

    /**
     * Determine whether a filtered CMS block still contains meaningful output.
     */
    private function hasRenderableCustomBlockContent(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        $textContent = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textContent = trim((string)preg_replace('/\s+/u', ' ', $textContent));

        if ($textContent !== '') {
            return true;
        }

        if (preg_match('/<a\b[^>]*href\s*=|<(?:img|picture|video|iframe|svg|canvas)\b|<source\b[^>]*(?:src|srcset)\s*=/i', $content) === 1) {
            return true;
        }

        return preg_match(
            '/class\s*=\s*("|\")[^"\']*(?:^|\s)(?:block|cms-block)(?:\s|$)[^"\']*\1/i',
            $content
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function getCustomBlockHtml($type = 'after')
    {
        $html = '';
        $blockIds = (string)($this->_verticalmenuConfig['custom_links']['staticblock_' . $type] ?? '');

        if ($blockIds === '') {
            return $html;
        }

        $ids = explode(',', preg_replace('/\s+/', '', $blockIds) ?? '');
        $storeId = (int)$this->_storeManager->getStore()->getId();

        foreach ($ids as $blockId) {
            if ($blockId === '') {
                continue;
            }

            $block = $this->_blockFactory->create();
            $block->setStoreId($storeId)->load($blockId);

            if (!$block || !(bool)$block->getId()) {
                continue;
            }

            $blockContent = trim((string)$block->getContent());
            if ($blockContent === '') {
                continue;
            }

            $content = (string)$this->_filterProvider->getBlockFilter()
                ->setStoreId($storeId)
                ->filter($blockContent);
            $content = $this->normalizeCustomBlockContent($content);

            if (!$this->hasRenderableCustomBlockContent($content)) {
                continue;
            }

            if (preg_match('/<li\b/i', $content) !== 1) {
                $content = '<li class="vertical-menu-custom-block">' . $content . '</li>';
            }

            $html .= $content;
        }

        return $html;
    }

    /**
     * Render submenu level 1 with the custom semantic classes used by the AWA menu.
     *
     * @param mixed $category
     * @param mixed $categoryModel
     * @param array $children
     */
    private function getLevelOneItemsHtml(
        $category,
        $categoryModel,
        array $children,
        int $maxLevel,
        int $columnWidth,
        string $menuType,
        int $columns
    ): string {
        $html = '';

        if ($maxLevel > 0 && $maxLevel - 1 < 1) {
            return $html;
        }

        $columnClass = '';
        if (($menuType === 'fullwidth' || $menuType === 'staticwidth') && $columns > 0) {
            $safeColumns = $this->sanitizeClassToken((string)$columns);
            $safeColumnWidth = $this->clampGridColumns($columnWidth);
            $columnClass = 'col-sm-' . $safeColumnWidth . ' mega-columns columns' . $safeColumns;
        }

        $listClass = 'subchildmenu navigation__inner-list navigation__inner-list--level1';
        if ($columnClass !== '') {
            $listClass .= ' ' . $columnClass;
        }

        $categoryName = $this->escapeHtml($category->getName());
        $categoryNameAttr = $this->escapeHtmlAttr($category->getName());
        $categoryUrl = $this->escapeUrl($this->getCategoryUrlCached($category));

        $html .= '<ul class="' . $this->escapeHtmlAttr(trim($listClass)) . '" data-level="1">';
        $html .= '<li class="navigation__inner-item navigation__inner-item--level1 subcategory-title col-1">';
        $html .= '<span>' . $categoryName . '</span>';
        $html .= '</li>';

        foreach ($children as $child) {
            $childModel = $this->getCategoryModel($child->getId());
            $hideItem = (bool)$childModel->getData('vc_menu_hide_item');

            if ($hideItem) {
                continue;
            }

            $subChildren = $this->getActiveChildCategories($child);
            $hasChildren = count($subChildren) > 0;

            $urlToken = $this->sanitizeClassToken((string)$child->getUrlKey());
            $classParts = [
                'ui-menu-item',
                'level1',
                'navigation',
                'navigation__inner-item',
                'navigation__inner-item--level1',
                'subcategory-second-level',
                'col-1',
                'parent-ul-cat-mega-menu'
            ];

            if ($urlToken !== '') {
                $classParts[] = $urlToken;
            }

            if ($hasChildren) {
                $classParts[] = 'parent';
                $classParts[] = 'navigation__inner-item--parent';
                $classParts[] = 'position-anchor';
            }

            $vcMenuCatLabel = (string)$childModel->getData('vc_menu_cat_label');
            $vcMenuFontIcon = (string)$childModel->getData('vc_menu_font_icon');
            $menuToken = 'menu-' . (int)$child->getId();
            $childName = $this->escapeHtml($child->getName());
            $childUrl = $this->escapeUrl($this->getCategoryUrlCached($child));

            $childSubmenuId = 'submenu-' . $menuToken;

            $html .= '<li class="' . $this->escapeHtmlAttr(implode(' ', array_values(array_unique($classParts)))) . '"'
                . ' data-level="1"'
                . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                . '>';

            if ($hasChildren) {
                $html .= $this->renderOpenChildrenToggle(
                    (string) __('Expandir subcategorias de ') . (string) $child->getName(),
                    $childSubmenuId
                );
            }

            $html .= '<a class="navigation__inner-link title-cat-mega-menu"'
                . ' href="' . $childUrl . '"'
                . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                . ' title="' . $this->escapeHtmlAttr($child->getName()) . '"'
                . ' aria-label="' . $this->escapeHtmlAttr($child->getName()) . '"'
                . '>';

            $iconClass = $this->sanitizeClassList($vcMenuFontIcon);
            if ($iconClass !== '') {
                $html .= '<em class="' . $this->escapeHtmlAttr('menu-thumb-icon ' . $iconClass) . '"></em>';
            }

            $html .= '<span>' . $childName;

            if ($vcMenuCatLabel !== '' && isset($this->_verticalmenuConfig['cat_labels'][$vcMenuCatLabel])) {
                $labelKey = $this->sanitizeClassToken($vcMenuCatLabel);
                $labelText = $this->escapeHtml((string)$this->_verticalmenuConfig['cat_labels'][$vcMenuCatLabel]);
                $html .= '<span class="cat-label cat-label-' . $this->escapeHtmlAttr($labelKey) . '" aria-hidden="true">' . $labelText . '</span>';
            }

            $html .= '</span>';
            $html .= '</a>';

            if ($hasChildren) {
                /* Keep the nested panel id aligned with the toggle's
                 * aria-controls (for example submenu-menu-89). */
                $html .= $this->getSubmenuItemsHtml(
                    $subChildren,
                    2,
                    $maxLevel,
                    $columnWidth,
                    $menuType,
                    null,
                    $menuToken
                );
            }

            $html .= '</li>';
        }

        $categoryImage = (string)$categoryModel->getData('image');
        if ($categoryImage !== '') {
            try {
                $categoryImageUrl = (string)$categoryModel->getImageUrl('image');
            } catch (\Exception $e) {
                $store = $this->_storeManager->getStore();
                $mediaUrl = '';
                if (is_object($store) && method_exists($store, 'getBaseUrl')) {
                    $mediaUrl = (string)call_user_func([$store, 'getBaseUrl'], UrlInterface::URL_TYPE_MEDIA);
                }
                $categoryImageUrl = $mediaUrl . 'catalog/category/' . ltrim($categoryImage, '/');
            }

            if ($categoryImageUrl !== '') {
                $html .= '<li class="navigation__inner-item navigation__inner-item--level1 imagem img-subcategory col-2">';
                $html .= '<a href="' . $categoryUrl . '" class="navigation__inner-link"'
                    . ' aria-label="' . $categoryNameAttr . '">';
                $html .= '<img loading="lazy" src="' . $this->escapeUrl($categoryImageUrl) . '" alt="' . $categoryNameAttr . '" width="400" height="400" />';
                $html .= '</a>';
                $html .= '</li>';
            }
        }

        $html .= '<li class="navigation__inner-item navigation__inner-item--all navigation__inner-item--level1 hb-strong-title">';
        $html .= '<a href="' . $categoryUrl . '" class="navigation__inner-link"'
            . ' aria-label="' . $this->escapeHtmlAttr((string) __('Ver todos: %1', $categoryModel->getName())) . '">';
        $html .= $this->escapeHtml(__('Ver todos'));
        $html .= '</a>';
        $html .= '</li>';

        $html .= '</ul>';

        return $html;
    }

    /**
     * @inheritDoc
     */
    public function getSubmenuItemsHtml($children, $level = 1, $max_level = 0, $column_width = 12, $menu_type = 'fullwidth', $columns = null, $parentMenuId = 'root')
    {
        $html = '';

        if (!$max_level || ($max_level && $max_level == 0) || ($max_level && $max_level > 0 && $max_level - 1 >= $level)) {
            $column_class = '';

            if ($level == 1 && $columns && ($menu_type == 'fullwidth' || $menu_type == 'staticwidth')) {
                $columnWidth = $this->clampGridColumns((int)$column_width);
                $columnsSafe = $this->sanitizeClassToken((string)$columns);

                $column_class = 'col-sm-' . $columnWidth . ' ';
                $column_class .= 'mega-columns columns' . $columnsSafe;
            }

            $listClasses = 'subchildmenu navigation__inner-list navigation__inner-list--level' . (int)$level;
            if (trim($column_class) !== '') {
                $listClasses .= ' ' . trim($column_class);
            }

            $submenuListId = ($parentMenuId !== 'root') ? 'submenu-menu-' . (int)$level . '-' . $this->escapeHtmlAttr($parentMenuId) : '';
            $idAttr = $submenuListId !== '' ? ' id="submenu-' . $this->escapeHtmlAttr($parentMenuId) . '"' : '';

            $html = '<ul' . $idAttr . ' class="' . $this->escapeHtmlAttr(trim($listClasses)) . '" data-level="' . (int)$level . '">';

            foreach ($children as $child) {
                $cat_model = $this->getCategoryModel($child->getId());

                $vc_menu_hide_item = $cat_model->getData('vc_menu_hide_item');

                if ($vc_menu_hide_item) {
                    continue;
                }

                $sub_children = $this->getActiveChildCategories($child);

                $vc_menu_cat_label = (string)$cat_model->getData('vc_menu_cat_label');
                $vc_menu_font_icon = (string)$cat_model->getData('vc_menu_font_icon');
                $childId = (int)$child->getId();
                $menuToken = 'menu-' . $childId;

                $item_class = 'level' . (int)$level . ' ';
                $item_class .= 'navigation__inner-item navigation__inner-item--level' . (int)$level . ' ';
                if ((int)$level === 2) {
                    $item_class .= 'subcategory-second-level col-1 ';
                }

                $hasChildren = count($sub_children) > 0;
                if ($hasChildren) {
                    $item_class .= 'parent navigation__inner-item--parent position-anchor ';
                }

                $linkClasses = ['navigation__inner-link'];
                if ($level == 1 && ($menu_type == 'fullwidth' || $menu_type == 'staticwidth')) {
                    $linkClasses[] = 'title-cat-mega-menu';
                    $item_class .= 'parent-ul-cat-mega-menu';
                    $item_class .= 'subcategory-second-level col-1 ';
                }

                /* --- Phase C: product count + category image as data attrs --- */
                $dataAttrs = '';
                if ($level === 1) {
                    $productCount = $this->getCategoryProductCountCached($cat_model);
                    $dataAttrs .= ' data-product-count="' . $productCount . '"';

                    $catImageUrl = $this->getCategoryImageUrlCached($cat_model, 'image');
                    if ($catImageUrl !== '') {
                        $dataAttrs .= ' data-cat-image="' . $this->escapeUrl($catImageUrl) . '"';
                    }
                }

                $submenuId = 'submenu-' . $menuToken;

                $html .= '<li class="ui-menu-item ' . $this->escapeHtmlAttr(trim($item_class)) . '"'
                    . $dataAttrs
                    . ' data-level="' . (int)$level . '"'
                    . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                    . '>';

                if ($hasChildren) {
                    $html .= $this->renderOpenChildrenToggle(
                        (string) __('Expandir subcategorias de ') . (string) $child->getName(),
                        $submenuId
                    );
                }

                $childUrl = $this->escapeUrl($this->getCategoryUrlCached($child));
                $childName = $this->escapeHtml($child->getName());
                $childNameAttr = $this->escapeHtmlAttr($child->getName());
                $linkClassAttr = ' class="' . $this->escapeHtmlAttr(implode(' ', $linkClasses)) . '"';

                $html .= '<a' . $linkClassAttr
                    . ' href="' . $childUrl . '"'
                    . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                    . ' title="' . $childNameAttr . '"'
                    . ' aria-label="' . $childNameAttr . '"'
                    . '>';

                $iconClass = $this->sanitizeClassList($vc_menu_font_icon);
                if ($iconClass !== '') {
                    $html .= '<em class="' . $this->escapeHtmlAttr('menu-thumb-icon ' . $iconClass) . '"></em>';
                }

                $html .= '<span class="navigation__label">' . $childName;

                if ($vc_menu_cat_label !== '' && isset($this->_verticalmenuConfig['cat_labels'][$vc_menu_cat_label])) {
                    $labelKey = $this->sanitizeClassToken($vc_menu_cat_label);
                    $labelText = $this->escapeHtml((string)$this->_verticalmenuConfig['cat_labels'][$vc_menu_cat_label]);
                    $html .= '<span class="cat-label cat-label-' . $this->escapeHtmlAttr($labelKey) . '" aria-hidden="true">' . $labelText . '</span>';
                }

                $html .= '</span></a>';

                if (count($sub_children) > 0) {
                    $html .= $this->getSubmenuItemsHtml($sub_children, $level + 1, $max_level, $column_width, $menu_type, null, $menuToken);
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * @inheritDoc
     */
    public function getVerticalMenuHtml()
    {
        $html = '';

        $categories = $this->getStoreCategories(true, false, true);
        if ($categories instanceof \Traversable) {
            $categories = iterator_to_array($categories);
        }
        if (!is_array($categories)) {
            $categories = [];
        }

        $this->_verticalmenuConfig = $this->_helper->getConfig('verticalmenu');
        $max_level = (int)($this->_verticalmenuConfig['general']['max_level'] ?? 0);

        $this->preloadCategoryModels($this->collectMenuCategoryIds($categories, $max_level));

        $html .= $this->getCustomBlockHtml('before');

        foreach ($categories as $category) {
            if (!$category->getIsActive()) {
                continue;
            }

            $cat_model = $this->getCategoryModel($category->getId());

            $vc_menu_hide_item = $cat_model->getData('vc_menu_hide_item');
            if ($vc_menu_hide_item) {
                continue;
            }

            $children = $this->getActiveChildCategories($category);

            $vc_menu_cat_label = (string)$cat_model->getData('vc_menu_cat_label');
            $vc_menu_font_icon = (string)$cat_model->getData('vc_menu_font_icon');
            $vc_menu_cat_columns = (int)$cat_model->getData('vc_menu_cat_columns');
            $vc_menu_float_type = (string)$cat_model->getData('vc_menu_float_type');

            if (!$vc_menu_cat_columns) {
                $vc_menu_cat_columns = 4;
            }

            $menu_type = (string)$cat_model->getData('vc_menu_type');
            if ($menu_type === '') {
                $menu_type = (string)($this->_verticalmenuConfig['general']['menu_type'] ?? 'fullwidth');
            }

            $custom_style = '';
            if ($menu_type === 'staticwidth') {
                $size = $this->sanitizeCssSize((string)$cat_model->getData('vc_menu_static_width'))
                    ?: '500px';
                $custom_style = ' style="width: ' . $this->escapeHtmlAttr($size) . ';"';
            }

            $categoryToken = $this->sanitizeClassToken((string)$category->getUrlKey());
            $item_class = 'level0 navigation__item navigation__item--level0 category category-tree position-anchor subcategory-first-level '
                . $this->sanitizeClassToken($menu_type) . ' ';
            if ($categoryToken !== '') {
                $item_class .= $categoryToken . ' ';
            }

            $menu_top_content = (string)$cat_model->getData('vc_menu_block_top_content');
            $menu_left_content = (string)$cat_model->getData('vc_menu_block_left_content');
            $menu_left_width = (int)$cat_model->getData('vc_menu_block_left_width');
            if ($menu_left_content === '' || !$menu_left_width) {
                $menu_left_width = 0;
            }

            $menu_right_content = (string)$cat_model->getData('vc_menu_block_right_content');
            $menu_right_width = (int)$cat_model->getData('vc_menu_block_right_width');
            if ($menu_right_content === '' || !$menu_right_width) {
                $menu_right_width = 0;
            }

            $menu_bottom_content = (string)$cat_model->getData('vc_menu_block_bottom_content');

            $floatType = '';
            if ($vc_menu_float_type !== '') {
                $floatType = 'fl-' . $this->sanitizeClassToken($vc_menu_float_type) . ' ';
            }

            $hasMegaContent = ($menu_type === 'fullwidth' || $menu_type === 'staticwidth')
                && ($menu_top_content !== '' || $menu_left_content !== '' || $menu_right_content !== '' || $menu_bottom_content !== '');

            $hasChildren = count($children) > 0 || $hasMegaContent;
            if ($hasChildren) {
                $item_class .= 'parent navigation__item--parent ';
            }
            $menuToken = 'menu-' . (int)$category->getId();

            $categoryUrl = $this->escapeUrl($this->getCategoryUrlCached($category));
            $categoryName = $this->escapeHtml($category->getName());
            $categoryNameAttr = $this->escapeHtmlAttr($category->getName());

            $submenuId = 'submenu-' . $menuToken;

            $html .= '<li class="ui-menu-item ' . $this->escapeHtmlAttr(trim($item_class . $floatType)) . '"'
                . ' data-level="0"'
                . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                . '>';

            if ($hasChildren) {
                $html .= $this->renderOpenChildrenToggle(
                    (string) __('Expandir subcategorias de ') . (string) $category->getName(),
                    $submenuId
                );
            }

            $html .= '<a href="' . $categoryUrl . '" class="level-top navigation__link"'
                . ' data-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                . ' title="' . $categoryNameAttr . '"'
                . ' aria-label="' . $categoryNameAttr . '"'
                . '>';

            $vc_menu_icon_img = $this->_helper->getVerticalIconimageUrl($cat_model);
            if ($vc_menu_icon_img) {
                $iconImgUrl = $this->getCategoryImageUrlCached($cat_model, 'vc_menu_icon_img');
                if ($iconImgUrl !== '') {
                    $html .= '<img class="menu-thumb-icon" src="' . $this->escapeUrl($iconImgUrl) . '" alt="" role="presentation" width="20" height="20" loading="lazy"/>';
                }
            } else {
                $iconClass = $this->sanitizeClassList($vc_menu_font_icon);
                if ($iconClass !== '') {
                    $html .= '<em class="' . $this->escapeHtmlAttr('menu-thumb-icon ' . $iconClass) . '"></em>';
                }
            }

            $html .= '<span class="navigation__label">' . $categoryName . '</span>';

            if ($vc_menu_cat_label !== '' && isset($this->_verticalmenuConfig['cat_labels'][$vc_menu_cat_label])) {
                $labelKey = $this->sanitizeClassToken($vc_menu_cat_label);
                $labelText = $this->escapeHtml((string)$this->_verticalmenuConfig['cat_labels'][$vc_menu_cat_label]);
                $html .= '<span class="cat-label cat-label-' . $this->escapeHtmlAttr($labelKey) . '" aria-hidden="true">' . $labelText . '</span>';
            }

            $html .= '</a>';

            if ($hasChildren) {
                $html .= '<div id="' . $this->escapeHtmlAttr($submenuId) . '"'
                    . ' class="level0 submenu navigation__submenu navigation__inner-list navigation__inner-list--level1"'
                    . $custom_style
                    . ' data-level="1"'
                    . ' data-parent-menu="' . $this->escapeHtmlAttr($menuToken) . '"'
                    . ' role="region"'
                    . ' aria-label="' . $categoryNameAttr . '"'
                    . '>';

                if (($menu_type === 'fullwidth' || $menu_type === 'staticwidth') && $menu_top_content !== '') {
                    $html .= '<div class="menu-top-block">' . $this->getMenuBlockContentCached($menu_top_content) . '</div>';
                }

                if (count($children) > 0 || (($menu_type === 'fullwidth' || $menu_type === 'staticwidth') && ($menu_left_content !== '' || $menu_right_content !== ''))) {
                    $html .= '<div class="row">';

                    $menu_left_width = $this->clampGridColumns($menu_left_width);
                    $menu_right_width = $this->clampGridColumns($menu_right_width);
                    $centerWidth = $this->clampGridColumns(12 - $menu_left_width - $menu_right_width);

                    if (($menu_type === 'fullwidth' || $menu_type === 'staticwidth') && $menu_left_content !== '' && $menu_left_width > 0) {
                        $html .= '<div class="menu-left-block col-sm-' . $menu_left_width . '">' . $this->getMenuBlockContentCached($menu_left_content) . '</div>';
                    }

                    $html .= $this->getLevelOneItemsHtml(
                        $category,
                        $cat_model,
                        $children,
                        (int)$max_level,
                        $centerWidth,
                        $menu_type,
                        (int)$vc_menu_cat_columns
                    );

                    if (($menu_type === 'fullwidth' || $menu_type === 'staticwidth') && $menu_right_content !== '' && $menu_right_width > 0) {
                        $html .= '<div class="menu-right-block col-sm-' . $menu_right_width . '">' . $this->getMenuBlockContentCached($menu_right_content) . '</div>';
                    }

                    $html .= '</div>';
                }

                if (($menu_type === 'fullwidth' || $menu_type === 'staticwidth') && $menu_bottom_content !== '') {
                    $html .= '<div class="menu-bottom-block">' . $this->getMenuBlockContentCached($menu_bottom_content) . '</div>';
                }

                $html .= '</div>';
            }

            $html .= '</li>';
        }

        $html .= $this->getCustomBlockHtml('after');

        return $html;
    }

    /**
     * Block-level HTML cache key.
     *
     * The vertical menu content is identical for all visitors within the same store view,
     * so we key only on store ID / code. This allows Magento to store the rendered HTML
     * in the block_html cache (Redis) and skip re-rendering on every non-FPC request.
     *
     * @return array<int, string>
     */
    public function getCacheKeyInfo(): array
    {
        $templateFile = (string) $this->getTemplateFile();

        return [
            'AWA_VERTICAL_MENU',
            (string)$this->_storeManager->getStore()->getId(),
            (string)$this->_storeManager->getStore()->getCode(),
            (string)$this->getNameInLayout(),
            (string)$this->getTemplate(),
            $templateFile,
            $this->getBlockDependencyCacheVersion($templateFile),
        ];
    }

    /**
     * Cache bust when template, block PHP, or theme i18n change.
     */
    private function getBlockDependencyCacheVersion(string $templateFile): string
    {
        $fingerprints = ['harden-26'];
        $paths = [
            $templateFile,
            __FILE__,
            BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/i18n/pt_BR.csv',
        ];

        foreach ($paths as $path) {
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            $fingerprints[] = basename($path) . ':' . ($mtime !== false ? (string) $mtime : '0');
        }

        return implode('|', $fingerprints);
    }

    /**
     * Cache tags for automatic invalidation when categories change.
     *
     * @return string[]
     */
    public function getCacheTags(): array
    {
        return [
            \Magento\Catalog\Model\Category::CACHE_TAG,
            \Magento\Framework\App\Cache\Type\Block::CACHE_TAG,
        ];
    }

    /**
     * Block HTML cache lifetime in seconds (2 hours).
     *
     * The parent Rokanthemes constructor does not accept an $data array, so
     * the layout XML <argument name="cache_lifetime"> never reaches the block
     * data bag — override getCacheLifetime() directly to guarantee the value.
     *
     * @return int
     */
    protected function getCacheLifetime(): int
    {
        return 7200;
    }

    /**
     * Render block HTML with manual Redis cache.
     *
     * Overrides AbstractBlock::toHtml() to guarantee block-level caching.
     * The Magento framework lockQuery mechanism may not trigger for this block
     * because the Rokanthemes constructor does not pass $data[] to the parent.
     * We bypass that path and use $this->_cache directly with our cache key.
     *
     * @return string
     */
    public function toHtml(): string
    {
        if (!$this->_cacheState->isEnabled(\Magento\Framework\App\Cache\Type\Block::TYPE_IDENTIFIER)) {
            return parent::toHtml();
        }

        $cacheKey = $this->getCacheKey();
        $cached = $this->_cache->load($cacheKey);

        if ($cached !== false) {
            return (string)$cached;
        }

        $html = parent::toHtml();

        if ($html !== '') {
            $this->_cache->save(
                $html,
                $cacheKey,
                array_unique($this->getCacheTags()),
                $this->getCacheLifetime()
            );
        }

        return $html;
    }
}
