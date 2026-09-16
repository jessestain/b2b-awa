<?php

declare(strict_types=1);

namespace GrupoAwamotos\HelpCenter\Ui\DataProvider\Category;

use GrupoAwamotos\HelpCenter\Model\CategoryRepository;
use GrupoAwamotos\HelpCenter\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class FormDataProvider extends AbstractDataProvider
{
    /** @var array<int|string, array<string, mixed>> */
    private array $loadedData = [];

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly CollectionFactory $collectionFactory,
        private readonly CategoryRepository $repository,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $this->collectionFactory->create();
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== []) {
            return $this->loadedData;
        }
        $id = (int) $this->request->getParam('category_id');
        if ($id) {
            try {
                $category = $this->repository->getById($id);
                $this->loadedData[$id] = $category->getData();
            // Esperado ao abrir o formulário de "nova categoria": o ID ainda não existe.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            }
        }
        return $this->loadedData;
    }
}
