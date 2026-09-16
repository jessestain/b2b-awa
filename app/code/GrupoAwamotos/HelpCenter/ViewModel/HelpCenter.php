<?php

declare(strict_types=1);

namespace GrupoAwamotos\HelpCenter\ViewModel;

use GrupoAwamotos\HelpCenter\Api\CategoryRepositoryInterface;
use GrupoAwamotos\HelpCenter\Api\Data\CategoryInterface;
use GrupoAwamotos\HelpCenter\Api\Data\TopicInterface;
use GrupoAwamotos\HelpCenter\Api\TopicRepositoryInterface;
use GrupoAwamotos\HelpCenter\Model\ResourceModel\Topic\CollectionFactory as TopicCollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class HelpCenter implements ArgumentInterface
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly TopicRepositoryInterface $topicRepository,
        private readonly TopicCollectionFactory $topicCollectionFactory,
        private readonly CustomerSession $customerSession,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * Categorias visíveis no storefront (união das audiências do visitante).
     *
     * @return CategoryInterface[]
     */
    public function getCategories(): array
    {
        $byId = [];
        foreach ($this->getAudiences() as $audience) {
            foreach ($this->categoryRepository->getByAudience($audience) as $category) {
                $id = (int) $category->getCategoryId();
                if ($id > 0) {
                    $byId[$id] = $category;
                }
            }
        }
        uasort(
            $byId,
            static fn (CategoryInterface $a, CategoryInterface $b): int => $a->getSortOrder() <=> $b->getSortOrder()
        );

        return array_values($byId);
    }

    /**
     * Chave de ícone allowlist (nunca SVG do admin).
     */
    public function getCategoryIconKey(CategoryInterface $category): string
    {
        $name = mb_strtolower($category->getName());
        if (str_contains($name, 'devol') || str_contains($name, 'troca') || str_contains($name, 'garantia')) {
            return 'returns';
        }
        if (str_contains($name, 'cadastro') || str_contains($name, 'conta') || str_contains($name, 'b2b') || str_contains($name, 'cnpj')) {
            return 'account';
        }
        if (str_contains($name, 'entrega') || str_contains($name, 'frete')) {
            return 'shipping';
        }
        if (str_contains($name, 'pagamento')) {
            return 'payment';
        }
        if (str_contains($name, 'pedido')) {
            return 'orders';
        }
        if (str_contains($name, 'produto') || str_contains($name, 'peca') || str_contains($name, 'peça')) {
            return 'catalog';
        }

        return 'help';
    }

    /**
     * Texto enviado ao assistente ao clicar “Perguntar à assistente”.
     */
    public function getAskQuery(CategoryInterface $category): string
    {
        $topic = trim((string) ($category->getDescription() ?? ''));
        if ($topic === '') {
            $topic = $category->getName();
        }

        return $this->wrapHelpQuery($topic);
    }

    /**
     * Prefixo estável para o orchestrator não tratar como busca de catálogo.
     */
    public function wrapHelpQuery(string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            return (string) __('Central de Ajuda — preciso de orientação da loja.');
        }
        if (stripos($question, 'Central de Ajuda') !== false) {
            return $question;
        }

        return (string) __('Central de Ajuda — %1', $question);
    }

    /**
     * @return TopicInterface[]
     */
    public function getTopicsByCategory(int $categoryId): array
    {
        $collection = $this->topicCollectionFactory->create();
        $collection->addActiveFilter();
        $collection->addAudienceFilter($this->getAudiences());
        $collection->addCategoryFilter($categoryId);
        $collection->addSortOrder();
        $items = array_values($collection->getItems());
        return $items;
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    public function isB2B(): bool
    {
        return $this->customerSession->isLoggedIn()
            && $this->customerSession->getCustomer()->getData('b2b_approval_status') === 'approved';
    }

    public function getSearchUrl(): string
    {
        return $this->urlBuilder->getUrl('ajuda/ajax/search');
    }

    /**
     * @return string[]
     */
    private function getAudiences(): array
    {
        $base = [CategoryInterface::AUDIENCE_ALL];
        if ($this->customerSession->isLoggedIn()) {
            $base[] = CategoryInterface::AUDIENCE_CUSTOMER;
            if ($this->isB2B()) {
                $base[] = CategoryInterface::AUDIENCE_B2B;
            }
        }
        return $base;
    }
}
