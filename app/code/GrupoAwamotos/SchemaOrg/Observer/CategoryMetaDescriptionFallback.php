<?php

declare(strict_types=1);

namespace GrupoAwamotos\SchemaOrg\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * CategoryMetaDescriptionFallback
 *
 * Gera uma meta description específica por categoria quando a categoria
 * não possui meta_description definida no admin.
 *
 * Fluxo:
 *  1. Este observer seta a descrição fallback via PageConfig.
 *  2. Em seguida, Magento\Catalog\Block\Category\View::_prepareLayout() seta
 *     a descrição APENAS se a categoria tiver meta_description própria.
 *  3. Logo, a descrição admin-definida sempre tem prioridade.
 *
 * Evento: catalog_controller_category_init_after
 *
 * @see \Magento\Catalog\Block\Category\View::_prepareLayout()
 */
class CategoryMetaDescriptionFallback implements ObserverInterface
{
    private const MIN_DESCRIPTION_LENGTH = 60;

    public function __construct(
        private readonly PageConfig $pageConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Category $category */
        $category = $observer->getEvent()->getCategory();
        if (!$category || !$category->getId()) {
            return;
        }

        $existingDescription = trim((string) $category->getMetaDescription());

        // Se a categoria já tem uma descrição própria e suficientemente longa, não sobrescrever
        if ($existingDescription !== '' && mb_strlen($existingDescription) >= self::MIN_DESCRIPTION_LENGTH) {
            return;
        }

        $categoryName = trim((string) $category->getName());
        if ($categoryName === '') {
            return;
        }

        // Construir fallback: inclui nome da categoria + categoria pai para contexto
        $parentName = '';
        try {
            $parent = $category->getParentCategory();
            if ($parent && $parent->getId() && (int) $parent->getLevel() > 1) {
                $parentName = (string) $parent->getName();
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                '[CategoryMetaDescriptionFallback::execute] Categoria pai não encontrada: ' . $e->getMessage()
            );
        }

        if ($parentName !== '' && $parentName !== $categoryName) {
            $context = sprintf('%s para %s', $categoryName, $parentName);
        } else {
            $context = $categoryName;
        }

        $fallback = sprintf(
            'Compre %s na AWA Motos. Peças e acessórios para motocicletas com entrega para todo Brasil. '
            . 'Preços especiais para clientes B2B cadastrados.',
            $context
        );

        $this->pageConfig->setDescription($fallback);
    }
}
