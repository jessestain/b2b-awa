<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Plugin;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use Magento\Backend\Controller\Adminhtml\Denied\Index;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * Redireciona perfis comerciais fora de admin/denied para o cockpit AWA Comercial.
 */
class DeniedPageDebugPlugin
{
    public function __construct(
        private readonly AdminSession $adminSession,
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly AuthorizationInterface $authorization,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @param callable(): mixed $proceed
     * @return mixed
     */
    public function aroundExecute(Index $subject, callable $proceed)
    {
        if (!$this->adminSession->isLoggedIn()) {
            return $proceed();
        }

        // Apenas cockpit-only. Admin com Magento_Backend::all também tem
        // commercial_dashboard; redirecioná-lo esconde admin/denied real
        // (secret key inválida, ACL de criar pedido, etc.).
        $isFullAdmin = $this->authorization->isAllowed('Magento_Backend::all');
        $isCockpitOnly = $this->portfolioScope->isCockpitOnlyUser();
        $willRedirect = !$isFullAdmin && $isCockpitOnly;
        // #region agent log
        file_put_contents('/home/jessessh/htdocs/srv1113343.hstgr.cloud/.cursor/debug-302306.log', json_encode(['sessionId' => '302306', 'runId' => 'verify', 'hypothesisId' => 'H1', 'location' => 'DeniedPageDebugPlugin.php:aroundExecute', 'message' => 'admin/denied decision', 'data' => ['isFullAdmin' => $isFullAdmin, 'isCockpitOnly' => $isCockpitOnly, 'willRedirect' => $willRedirect], 'timestamp' => (int) round(microtime(true) * 1000)]) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
        if ($willRedirect) {
            return $this->redirectFactory->create()
                ->setPath('awa_commercial/commercialdashboard/index');
        }

        return $proceed();
    }
}
