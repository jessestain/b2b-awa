<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\App\FrontController;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as ResponseHttp;

/**
 * Canonicaliza rotas legadas públicas com redirecionamento permanente.
 */
class LegacyRouteRedirectPlugin
{
    /**
     * @var array<string, string>
     */
    private const REDIRECT_MAP = [
        '/ofertas.html' => '/super-ofertas.html',
        '/contato' => '/contact',
        '/contact-us' => '/contact',
    ];

    public function __construct(
        private readonly ResponseHttp $response
    ) {
    }

    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ): mixed {
        $path = $this->normalizePath((string) $request->getPathInfo());
        $method = strtoupper((string) $request->getMethod());

        if (!isset(self::REDIRECT_MAP[$path]) || !in_array($method, ['GET', 'HEAD'], true)) {
            return $proceed($request);
        }

        $target = self::REDIRECT_MAP[$path];
        if ($target === $path) {
            return $proceed($request);
        }

        $this->response->setRedirect($target, 301);

        return $this->response;
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');
        if ($normalized !== '/' && str_ends_with($normalized, '/')) {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }
}
