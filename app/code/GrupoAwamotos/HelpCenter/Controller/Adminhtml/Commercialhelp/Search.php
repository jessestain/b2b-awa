<?php

declare(strict_types=1);

namespace GrupoAwamotos\HelpCenter\Controller\Adminhtml\Commercialhelp;

use GrupoAwamotos\HelpCenter\Api\Data\CategoryInterface;
use GrupoAwamotos\HelpCenter\Api\TopicRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Search extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GrupoAwamotos_B2B::commercial_cockpit_only';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly TopicRepositoryInterface $topicRepository,
        private readonly AdminSession $adminSession,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $query  = trim((string) $this->getRequest()->getParam('q', ''));

        if (mb_strlen($query) < 2) {
            return $result->setData(['topics' => [], 'query' => $query]);
        }

        $topics = $this->topicRepository->search($query, $this->resolveAudiences(), 8);
        $items  = [];

        foreach ($topics as $topic) {
            $topicId = (int) $topic->getTopicId();
            $items[] = [
                'id'      => $topicId,
                'title'   => (string) $topic->getTitle(),
                'summary' => (string) ($topic->getSummary() ?? ''),
                'url'     => $this->getUrl(
                    'awa_commercial/commercialhelp/index',
                    ['_fragment' => 'hc-topic-' . $topicId]
                ),
            ];
        }

        return $result->setData(['topics' => $items, 'query' => $query]);
    }

    /**
     * @return string[]
     */
    private function resolveAudiences(): array
    {
        $audiences = [
            CategoryInterface::AUDIENCE_ALL,
            CategoryInterface::AUDIENCE_SELLER,
        ];

        if ($this->isSupervisor()) {
            $audiences[] = CategoryInterface::AUDIENCE_SUPERVISOR;
        }

        if ($this->_authorization->isAllowed('GrupoAwamotos_HelpCenter::helpcenter_manage')) {
            $audiences[] = CategoryInterface::AUDIENCE_ADMIN;
        }

        return array_values(array_unique($audiences));
    }

    private function isSupervisor(): bool
    {
        $user = $this->adminSession->getUser();
        if (!$user) {
            return false;
        }

        return (bool) $user->getData('is_commercial_supervisor');
    }
}
