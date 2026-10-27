<?php

declare(strict_types=1);

namespace GrupoAwamotos\HelpCenter\Controller\Adminhtml\Commercialhelp;

use GrupoAwamotos\HelpCenter\Api\Data\CategoryInterface;
use GrupoAwamotos\HelpCenter\Api\TopicRepositoryInterface;
use GrupoAwamotos\HelpCenter\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use GrupoAwamotos\HelpCenter\Model\ResourceModel\Topic\CollectionFactory as TopicCollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::commercial_cockpit_only';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly TopicCollectionFactory $topicCollectionFactory,
        private readonly TopicRepositoryInterface $topicRepository,
        private readonly SerializerInterface $serializer,
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Central de Ajuda — Cockpit'));
        return $page;
    }
}
