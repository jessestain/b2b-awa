<?php

declare(strict_types=1);

namespace GrupoAwamotos\PreprocessedFallback\Plugin\View\TemplateEngine;

use GrupoAwamotos\PreprocessedFallback\Model\PreprocessedTemplateHealer;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\TemplateEngine\Php;
use Throwable;

final class PhpFallbackPlugin
{
    private PreprocessedTemplateHealer $templateHealer;

    public function __construct(
        PreprocessedTemplateHealer $templateHealer
    ) {
        $this->templateHealer = $templateHealer;
    }

    /**
     * @param array<string, mixed> $dictionary
     * @return string
     * @throws Throwable
     */
    public function aroundRender(
        Php $subject,
        callable $proceed,
        BlockInterface $block,
        $fileName,
        array $dictionary = []
    ): string {
        if (!is_string($fileName)) {
            return $proceed($block, $fileName, $dictionary);
        }

        $resolvedFileName = $this->templateHealer->resolveRenderablePath($fileName);

        try {
            return $proceed($block, $resolvedFileName, $dictionary);
        } catch (Throwable $exception) {
            $missingPath = $this->templateHealer->extractMissingPathFromMessage(
                $exception->getMessage()
            );
            if ($missingPath === null || !$this->templateHealer->isPreprocessedPath($missingPath)) {
                throw $exception;
            }

            $this->templateHealer->ensurePreprocessedExists($missingPath);
            $sourcePath = $this->templateHealer->resolveSourcePath($missingPath);
            if ($sourcePath === null || !is_file($sourcePath) || $sourcePath === $resolvedFileName) {
                throw $exception;
            }

            return $proceed($block, $sourcePath, $dictionary);
        }
    }
}
