<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\App;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Fallback de area code para CLI e pub/static.php quando getAreaCode() é chamado sem área definida.
 *
 * Este plugin atua no bootstrap precoce do Magento, antes do DI Container estar completamente
 * inicializado — por isso não usa injeção de dependências para acessar dados de request,
 * e lê $_SERVER diretamente (uso legítimo em contexto de bootstrap).
 */
class StateAreaCodeCliFallbackPlugin
{
    /**
     * Executa fallback de área quando getAreaCode falha em contexto sem área definida.
     *
     * @param State $subject
     * @param callable $proceed
     * @return string
     * @throws LocalizedException
     */
    public function aroundGetAreaCode(State $subject, callable $proceed): string
    {
        try {
            return $proceed();
        } catch (LocalizedException $e) {
            if (!$this->shouldApplyFallback()) {
                throw $e;
            }

            // Fluxos sem área definida (CLI/static.php) não devem derrubar execução.
            $subject->setAreaCode(Area::AREA_GLOBAL);

            return Area::AREA_GLOBAL;
        }
    }

    /**
     * Define quando o fallback deve ser aplicado.
     *
     * Acessa $_SERVER diretamente pois este plugin é instanciado no bootstrap precoce,
     * antes do objeto Request estar disponível no DI Container.
     *
     * @return bool
     */
    private function shouldApplyFallback(): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        // Uso direto de $_SERVER é intencional aqui: ver docblock da classe (bootstrap
        // precoce, sem Request disponível no DI Container).
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $scriptName     = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $scriptFilename = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        // phpcs:ignore Magento2.Security.Superglobal.SuperglobalUsageWarning
        $requestUri     = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (str_ends_with($scriptName, '/pub/static.php') || str_ends_with($scriptName, '/static.php')) {
            return true;
        }

        if (str_ends_with($scriptFilename, '/pub/static.php') || str_ends_with($scriptFilename, '/static.php')) {
            return true;
        }

        // Páginas de erro do Magento (pub/errors/*.php) não possuem área definida.
        if (str_contains($scriptFilename, '/pub/errors/') || str_contains($scriptName, '/pub/errors/')) {
            return true;
        }

        return str_starts_with($requestUri, '/static/');
    }
}
