<?php

declare(strict_types=1);

namespace GrupoAwamotos\PreprocessedFallback\Plugin\View\Result;

use GrupoAwamotos\PreprocessedFallback\Model\PreprocessedTemplateHealer;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State;
use Magento\Framework\View\Result\Page;
use Throwable;

final class PageFallbackPlugin
{
    private PreprocessedTemplateHealer $templateHealer;

    private State $appState;

    public function __construct(
        PreprocessedTemplateHealer $templateHealer,
        State $appState
    ) {
        $this->templateHealer = $templateHealer;
        $this->appState = $appState;
    }

    /**
     * @throws Throwable
     */
    public function aroundRenderResult(
        Page $subject,
        callable $proceed,
        ResponseInterface $httpResponse
    ): Page {
        if ($this->appState->getMode() !== State::MODE_PRODUCTION) {
            return $proceed($httpResponse);
        }

        try {
            return $proceed($httpResponse);
        } catch (Throwable $exception) {
            $missingPath = $this->templateHealer->extractMissingPathFromMessage($exception->getMessage());
            if ($missingPath === null || !$this->templateHealer->isPreprocessedPath($missingPath)) {
                throw $exception;
            }

            $this->templateHealer->ensurePreprocessedExists($missingPath);
            $sourcePath = $this->templateHealer->resolveSourcePath($missingPath);
            if (!is_file($missingPath)
                && ($sourcePath === null || !is_file($sourcePath))
            ) {
                throw $exception;
            }

            return $proceed($httpResponse);
        }
    }
}
