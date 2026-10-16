<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\App\FrontController;

use GrupoAwamotos\Theme\Observer\OptimizeHtmlResponseObserver;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as ResponseHttp;
use Magento\PageCache\Model\App\FrontController\BuiltinPlugin;

/**
 * Garante otimização de HTML na resposta (dispatch + respostas servidas do FPC).
 */
class OptimizeHtmlResponsePlugin
{
    public function __construct(
        private readonly OptimizeHtmlResponseObserver $observer,
    ) {
    }

    /**
     * @param FrontController $subject
     * @param mixed $result
     */
    public function afterDispatch(FrontController $subject, mixed $result, RequestInterface $request): mixed
    {
        return $this->optimizeResponse($result);
    }

    /**
     * @param BuiltinPlugin $subject
     * @param mixed $result
     */
    public function afterAroundDispatch(
        BuiltinPlugin $subject,
        mixed $result,
        FrontController $frontController,
        RequestInterface $request
    ): mixed {
        return $this->optimizeResponse($result);
    }

    private function optimizeResponse(mixed $result): mixed
    {
        if (!$result instanceof ResponseHttp) {
            return $result;
        }

        $contentType = $result->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return $result;
        }

        if ((string) $result->getBody() === '') {
            return $result;
        }

        $event = new \Magento\Framework\Event(['response' => $result]);
        $eventObserver = new \Magento\Framework\Event\Observer();
        $eventObserver->setEvent($event);
        $this->observer->execute($eventObserver);

        return $result;
    }
}
