<?php

declare(strict_types=1);

namespace GrupoAwamotos\CspFix\Plugin\Response;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Psr\Log\LoggerInterface;

class AdminRequireBaseUrlPlugin
{
    private const MARKER = 'data-awa-admin-require-base="1"';
    private static bool $isPatching = false;

    public function __construct(
        private readonly HttpRequest $request,
        private readonly AssetRepository $assetRepository,
        private readonly BackendUrlInterface $backendUrl,
        private readonly FormKey $formKey,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeSendResponse(HttpInterface $subject): void
    {
        $this->patchHtml($subject);
    }

    public function afterSetBody(HttpInterface $subject, mixed $result, mixed $body): mixed
    {
        $this->patchHtml($subject);

        return $result;
    }

    public function afterAppendBody(HttpInterface $subject, mixed $result, mixed $value): mixed
    {
        $this->patchHtml($subject);

        return $result;
    }

    private function patchHtml(HttpInterface $subject): void
    {
        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = (string) $subject->getBody();
        if (self::$isPatching || $html === '' || str_contains($html, self::MARKER)) {
            return;
        }

        if (!str_starts_with((string) $this->request->getFullActionName(), 'adminhtml_')
            && !str_contains($html, 'page-layout-admin-')
        ) {
            return;
        }

        $staticBaseUrl = $this->getStaticBaseUrl($html);
        if ($staticBaseUrl === '') {
            return;
        }

        $snippet = $this->buildSnippet($staticBaseUrl);
        $updated = preg_replace(
            '#(<script\b[^>]*\bsrc=["\'][^"\']*/_cache/merged/[^"\']+\.js["\'][^>]*>)#i',
            $snippet . '$1',
            $html,
            1,
            $count
        );

        if (!is_string($updated) || $count === 0) {
            $updated = preg_replace('#</head>#i', $snippet . '</head>', $html, 1, $count);
        }

        if (is_string($updated) && $count > 0) {
            self::$isPatching = true;
            try {
                $subject->setBody($updated);
            } finally {
                self::$isPatching = false;
            }
        }
    }

    private function getStaticBaseUrl(string $html): string
    {
        try {
            $jqueryUrl = (string) $this->assetRepository->getUrl('jquery.js');
            $baseUrl = preg_replace('#/jquery(?:\.min)?\.js(?:\?.*)?$#', '/', $jqueryUrl);
            if (is_string($baseUrl) && str_contains($baseUrl, '/adminhtml/')) {
                return $baseUrl;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[AdminRequireBaseUrlPlugin::getStaticBaseUrl] Asset repository indisponível, '
                . 'usando fallback: ' . $e->getMessage()
            );
        }

        if (!preg_match('#((?:https?:)?//[^"\']+|)/static/(version\d+)/#', $html, $versionMatch)) {
            return '';
        }

        try {
            $contextPath = (string) $this->assetRepository->getStaticViewFileContext()->getPath();
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[AdminRequireBaseUrlPlugin::getStaticBaseUrl] Falha ao obter context path: ' . $e->getMessage()
            );
            $contextPath = '';
        }

        if ($contextPath === '' || !str_starts_with($contextPath, 'adminhtml/')) {
            $contextPath = 'adminhtml/Magento/backend/pt_BR';
        }

        $hostPrefix = (string) ($versionMatch[1] ?? '');
        if ($hostPrefix === '') {
            $hostPrefix = rtrim($this->request->getScheme() . '://' . $this->request->getHttpHost(), '/');
        }

        return $hostPrefix . '/static/' . $versionMatch[2] . '/' . trim($contextPath, '/') . '/';
    }

    private function buildSnippet(string $staticBaseUrl): string
    {
        $staticBaseUrl = rtrim($staticBaseUrl, '/') . '/';
        $payload = [
            'baseUrl' => $staticBaseUrl,
            'backendBaseUrl' => (string) $this->backendUrl->getUrl('*'),
            'formKey' => $this->formKey->getFormKey(),
        ];

        return '<script ' . self::MARKER . '>(function(c){'
            . 'window.BASE_URL=window.BASE_URL||c.backendBaseUrl;'
            . 'window.FORM_KEY=window.FORM_KEY||c.formKey;'
            . 'if(typeof window.require==="function"&&typeof window.require.config==="function"){'
            . 'window.require.config({baseUrl:c.baseUrl});'
            . '}else{window.require=window.require||{};window.require.baseUrl=c.baseUrl;}'
            . '})(' . json_encode($payload, JSON_UNESCAPED_SLASHES) . ');</script>';
    }
}
