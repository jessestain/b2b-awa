<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\LayoutFactory;

/**
 * Lazy HTML fragments for B2B dashboard (below-the-fold panels).
 * Route: b2b/account/dashboardFragment?section=commerce
 */
class DashboardFragment implements HttpGetActionInterface
{
    private const SECTIONS = [
        'context' => [
            'handle' => 'b2b_account_dashboard_fragment_context',
            'block' => 'b2b.dashboard.fragment',
        ],
        'commerce' => [
            'handle' => 'b2b_account_dashboard_fragment_commerce',
            'block' => 'b2b.dashboard.fragment',
        ],
        'orders' => [
            'handle' => 'b2b_account_dashboard_fragment_orders',
            'block' => 'b2b.dashboard.fragment',
        ],
        'quotes' => [
            'handle' => 'b2b_account_dashboard_fragment_quotes',
            'block' => 'b2b.dashboard.quotes.fragment',
        ],
        'intelligence' => [
            'handle' => 'b2b_account_dashboard_fragment_intelligence',
            'block' => 'b2b.dashboard.intelligence.fragment',
        ],
        'recommendations' => [
            'handle' => 'b2b_account_dashboard_fragment_recommendations',
            'block' => 'rexisml.b2b.recommendations.fragment',
        ],
    ];

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly LayoutFactory $layoutFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $raw = $this->rawFactory->create();
        $section = (string) $this->request->getParam('section', '');
        $requestedWith = (string) $this->request->getHeader('X-Requested-With');
        $isLoggedIn = $this->customerSession->isLoggedIn();

        if ($requestedWith !== 'XMLHttpRequest') {
            return $raw->setHttpResponseCode(403)->setContents('');
        }

        if (!$isLoggedIn) {
            return $raw->setHttpResponseCode(401)->setContents('');
        }

        if (!isset(self::SECTIONS[$section])) {
            return $raw->setHttpResponseCode(400)->setContents('');
        }

        $config = self::SECTIONS[$section];
        $layout = $this->layoutFactory->create();
        $layout->getUpdate()->addHandle($config['handle']);
        $layout->getUpdate()->load();
        $layout->generateXml();
        $layout->generateElements();

        $block = $layout->getBlock($config['block']);
        $html = $block ? trim($block->toHtml()) : '';

        return $raw
            ->setHeader('Content-Type', 'text/html; charset=UTF-8', true)
            ->setContents($html);
    }
}
