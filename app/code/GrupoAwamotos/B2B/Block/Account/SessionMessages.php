<?php

/**
 * Messages block for uncacheable B2B auth pages (login, forgot password, claim, register).
 *
 * Root cause: Magento\Theme\Controller\Result\MessagePlugin::afterRenderResult() runs on
 * every controller result — including the redirect that follows a failed login/register
 * submission — and moves any pending Message\ManagerInterface messages into the public
 * 'mage-messages' cookie, clearing them from the session in the process. By the time the
 * browser lands on the next GET request (this auth page), the session message manager is
 * already empty; only the cookie holds the real content. Magento's own theme relies on the
 * 'Magento_Theme/js/view/messages' knockout component to read that cookie client-side, but
 * this auth shell intentionally strips most JS/knockout wiring for performance, so nothing
 * ever displayed the cookie contents server-side.
 *
 * This block reads both the (rarely populated, but still checked for safety) session
 * messages and the 'mage-messages' cookie, renders them with the existing Messages/HTML
 * pipeline, and clears the cookie so a page refresh does not keep re-showing the same
 * message.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Account;

use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Element\Messages;
use Magento\Framework\View\Element\Template\Context;
use Magento\Theme\Controller\Result\MessagePlugin;

class SessionMessages extends Messages
{
    private CookieManagerInterface $cookieManager;
    private CookieMetadataFactory $cookieMetadataFactory;
    private Json $serializer;
    private bool $messagesLoaded = false;

    public function __construct(
        Context $context,
        \Magento\Framework\Message\Factory $messageFactory,
        \Magento\Framework\Message\CollectionFactory $collectionFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\View\Element\Message\InterpretationStrategyInterface $interpretationStrategy,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        Json $serializer,
        array $data = []
    ) {
        parent::__construct($context, $messageFactory, $collectionFactory, $messageManager, $interpretationStrategy, $data);
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->serializer = $serializer;
    }

    /**
     * @inheritDoc
     */
    public function getGroupedHtml()
    {
        $this->loadMessagesOnce();
        return parent::getGroupedHtml();
    }

    /**
     * Populate the message collection exactly once per request from both sources.
     */
    private function loadMessagesOnce(): void
    {
        if ($this->messagesLoaded) {
            return;
        }
        $this->messagesLoaded = true;

        $this->setMessages($this->messageManager->getMessages(true));
        $this->loadCookieMessages();
    }

    /**
     * Read pending messages from the 'mage-messages' cookie (set by MessagePlugin on the
     * previous redirect) and merge them into this block's collection.
     */
    private function loadCookieMessages(): void
    {
        $raw = $this->cookieManager->getCookie(MessagePlugin::MESSAGES_COOKIES_NAME);
        if (!$raw) {
            return;
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $exception) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $text = is_array($item) ? (string) ($item['text'] ?? '') : '';
                if ($text === '') {
                    continue;
                }
                $type = is_array($item) ? (string) ($item['type'] ?? MessageInterface::TYPE_NOTICE) : MessageInterface::TYPE_NOTICE;
                $this->addMessage($this->messageFactory->create($type, $text));
            }
        }

        $this->clearMessagesCookie();
    }

    /**
     * Mark the cookie as consumed so a page refresh does not keep re-showing it.
     */
    private function clearMessagesCookie(): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata();
            $metadata->setPath('/');
            $this->cookieManager->deleteCookie(MessagePlugin::MESSAGES_COOKIES_NAME, $metadata);
        } catch (\Exception $exception) {
            $this->_logger->debug(
                '[SessionMessages::clearMessagesCookie] Cookie ausente ou headers já enviados: '
                . $exception->getMessage()
            );
        }
    }
}
