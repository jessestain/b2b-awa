<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\PageCache;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\PageCache\Model\App\Response\HttpPlugin;

/**
 * Evita setNoCacheHeaders() do HttpPlugin core quando X-Magento-Vary ainda não existe.
 *
 * O HttpPlugin compara getVaryString() com o cookie. Sem cookie, o core marca no-store
 * mesmo com layout público — guest e B2B logado ficam UNCACHEABLE no Varnish.
 * sendVary() grava o cookie; o no-store só permanece se o cookie existir e divergir
 * (troca de login / lista ERP).
 */
class HttpPluginFpcGuardPlugin
{
    public function __construct(
        private readonly HttpContext $httpContext,
        private readonly HttpRequest $request
    ) {
    }

    /**
     * @param callable(HttpResponse): void $proceed
     */
    public function aroundBeforeSendResponse(
        HttpPlugin $subject,
        callable $proceed,
        HttpResponse $response
    ): void {
        if ($response instanceof NotCacheableInterface
            || $response->headersSent()
            || $response->getMetadata('NotCacheable')
        ) {
            $proceed($response);
            return;
        }

        $varyCookie = $this->request->get(HttpResponse::COOKIE_VARY_STRING);

        // Magento sends/deletes a host-only X-Magento-Vary. An older domain-wide
        // copy can survive logout and keep selecting authenticated Varnish pages.
        // Expire only that legacy scope; never change the current host-only value.
        if (!$this->isMissingVaryCookie($varyCookie)) {
            $host = strtolower((string) $this->request->getHttpHost());
            if (preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)
                && str_contains($host, '.')
                && filter_var($host, FILTER_VALIDATE_IP) === false
            ) {
                $response->setHeader(
                    'Set-Cookie',
                    HttpResponse::COOKIE_VARY_STRING . '=; Max-Age=0; '
                    . 'Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/; Domain=' . $host
                    . '; Secure; HttpOnly; SameSite=Lax',
                    false
                );
            }
        }

        // Missing X-Magento-Vary: send the cookie and keep Magento public TTL.
        // Core HttpPlugin would setNoCacheHeaders() here (guest and B2B), which
        // forces PHP on every hit until the cookie exists. Varnish still refuses
        // to store this first object under the empty-vary hash; the next request
        // hashes the new cookie and can HIT.
        if ($this->isMissingVaryCookie($varyCookie)) {
            $response->sendVary();
            return;
        }

        $proceed($response);
    }

    /**
     * @param string|bool|null $varyCookie
     */
    private function isMissingVaryCookie($varyCookie): bool
    {
        return $varyCookie === null || $varyCookie === false || $varyCookie === '';
    }
}
