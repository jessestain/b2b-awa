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

    /** Teto do total: o Google mostra ~155-160 chars (SEO-013). */
    private const MAX_DESCRIPTION_LENGTH = 158;

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

        // SEO-013 (04/10/2026): a cauda fixa tinha ~123 chars, entao qualquer contexto acima de
        // 37 estourava os ~160 que o Google mostra (medido: 161 e 173 chars em PDPs reais, com
        // a ultima frase sendo cortada). Encurtada para ~107 e com TETO de seguranca no total.
        $tail = ' na AWA Motos. Peças e acessórios para motos com entrega para todo o Brasil. '
            . 'Preço especial para clientes B2B.';

        $budget = self::MAX_DESCRIPTION_LENGTH - mb_strlen('Compre ' . $tail, 'UTF-8');
        if (mb_strlen($context, 'UTF-8') > $budget) {
            $context = rtrim(mb_substr($context, 0, $budget, 'UTF-8'));
            // evita cortar no meio de uma palavra
            $corte = mb_strrpos($context, ' ', 0, 'UTF-8');
            if ($corte !== false && $corte > 10) {
                $context = rtrim(mb_substr($context, 0, $corte, 'UTF-8'));
            }
        }

        $fallback = sprintf('Compre %s%s', $context, $tail);

        $this->pageConfig->setDescription($fallback);
    }
}
