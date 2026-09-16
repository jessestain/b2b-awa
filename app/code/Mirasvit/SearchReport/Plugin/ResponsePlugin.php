<?php
/**
 * Mirasvit
 *
 * This source file is subject to the Mirasvit Software License, which is available at https://mirasvit.com/license/.
 * Do not edit or add to this file if you wish to upgrade the to newer versions in the future.
 * If you wish to customize this module for your needs.
 * Please refer to http://www.magentocommerce.com for more information.
 *
 * @category  Mirasvit
 * @package   mirasvit/module-search-ultimate
 * @version   2.2.70
 * @copyright Copyright (C) 2024 Mirasvit (https://mirasvit.com/)
 */



namespace Mirasvit\SearchReport\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Mirasvit\SearchReport\Service\LogService;
use Mirasvit\Search\Service\BotDetectorService;

class ResponsePlugin
{
    const COOKIE_NAME = 'searchReport-log';

    private $request;

    private $logService;

    private $cookieManager;

    private $cookieMetadataFactory;

    private $registry;

    private $botDetectorService;

    public function __construct(
        RequestInterface       $request,
        LogService             $logService,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory  $cookieMetadataFactory,
        Registry               $registry,
        BotDetectorService     $botDetectorService
    ) {
        $this->request               = $request;
        $this->logService            = $logService;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->registry              = $registry;
        $this->botDetectorService    = $botDetectorService;
    }

    public function beforeSendResponse(ResponseInterface $response): void
    {
        $query = $this->request->getParam('q');

        if ($query) {
            if (!$this->botDetectorService->isBotQuery($query)) {
                $misspell = $this->request->getParam('o');
                $misspell = is_array($misspell) ? implode(' ', $misspell) : (string)$misspell;
                $fallback = $this->request->getParam('f');
                $fallback = is_array($fallback) ? implode(' ', $fallback) : (string)$fallback;

                $results = $this->registry->registry(SearchPlugin::REGISTRY_KEY);
                $source  = $this->request->getFullActionName();

                if ($results === null) {
                    return;
                }

                $log = $this->logService->logQuery($query, $results, $source, $misspell, $fallback);

                if ($log) {
                    try {
                        $this->setLogCookie($log->getId());
                    } catch (\Exception $e) {
                    }
                }
            }
        } elseif (in_array($this->request->getModuleName(), ['catalog', 'catalogsearch'])
            || in_array($this->request->getFullActionName(), ['cms_index_index', 'cms_page_view'])) {
            $logId = (int)$this->cookieManager->getCookie(self::COOKIE_NAME);

            // Only log and reset cookie when there is an actual search log to track (logId > 0).
            // Setting the cookie unconditionally (even as 0) on every CMS/catalog page prevents
            // the Full Page Cache from caching these pages, causing severe performance degradation.
            if ($logId > 0) {
                $this->logService->logClick($logId);

                try {
                    $this->setLogCookie(0);
                } catch (\Exception $e) {
                }
            }
        }
    }

    private function setLogCookie(int $logId): void
    {
        // AWA 2026-09-02: do not Set-Cookie on cacheable GET/HEAD.
        // Magento FPC + Varnish mark catalogsearch UNCACHEABLE if searchReport-log is set.
        $method = strtoupper((string) $this->request->getMethod());
        if ($method === 'GET' || $method === 'HEAD') {
            return;
        }

        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata([
            'path' => '/',
        ]);

        /*
        * If enabled - allows subdomains
        */
        // $metadata->setDomain($_SERVER['HTTP_HOST']);
        $metadata->setSecure(isset($_SERVER['HTTPS']));
        $metadata->setHttpOnly(true);

        $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $logId, $metadata);
    }
}
