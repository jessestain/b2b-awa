<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Árvore de categorias para a navegação lateral do Catálogo de Compra Rápida.
 *
 * - Somente categorias ativas e presentes no menu (is_active=1, include_in_menu=1).
 * - Até 2 níveis abaixo da raiz da loja (topo + subcategorias recolhíveis).
 * - Sem contagens por categoria na fase 1 (decisão de performance; ver PENDENCIAS).
 */
class CategoryTreeProvider
{
    private const MAX_DEPTH = 2;

    /**
     * Inicializa o provedor com a coleção de categorias e a store atual.
     *
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Monta a árvore de categorias ativas usada na navegação lateral.
     *
     * @return array<int,array{id:int,name:string,children:array<int,array{id:int,name:string}>}>
     */
    public function getTree(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $rootId = (int) $store->getRootCategoryId();

            $collection = $this->categoryCollectionFactory->create();
            $collection->setStore($store)
                ->addAttributeToSelect(['name', 'is_active', 'include_in_menu', 'parent_id', 'level', 'position'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('include_in_menu', 1)
                ->setOrder('position', 'ASC');

            $byParent = [];
            foreach ($collection as $category) {
                $byParent[(int) $category->getParentId()][] = [
                    'id' => (int) $category->getId(),
                    'name' => (string) $category->getName(),
                    'children' => [],
                ];
            }

            $tree = [];
            $this->appendChildren($tree, $byParent, $rootId, 0);

            return $tree;
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Category tree failed.', [
                'exception' => $exception->getMessage(),
            ]);
            // Navegação vazia é degradação aceitável: a busca continua funcionando.
            return [];
        }
    }

    /**
     * Anexa recursivamente os filhos de uma categoria (até MAX_DEPTH níveis).
     *
     * @param array<int,array{id:int,name:string,children:array}> $target
     * @param array<int,array<int,array{id:int,name:string,children:array}>> $byParent
     * @param int $parentId
     * @param int $depth
     */
    private function appendChildren(array &$target, array $byParent, int $parentId, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH || !isset($byParent[$parentId])) {
            return;
        }

        foreach ($byParent[$parentId] as $node) {
            if ($depth + 1 < self::MAX_DEPTH) {
                $children = [];
                $this->appendChildren($children, $byParent, $node['id'], $depth + 1);
                $node['children'] = $children;
            }

            $target[] = $node;
        }
    }
}
