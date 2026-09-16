<?php

declare(strict_types=1);

namespace GrupoAwamotos\PreprocessedFallback\Model;

use Psr\Log\LoggerInterface;

final class PreprocessedTemplateHealer
{
    private const PREPROCESSED_SEGMENT = '/var/view_preprocessed/pub/static/';

    private LoggerInterface $logger;

    private string $basePath;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->basePath = rtrim((string) realpath(BP), DIRECTORY_SEPARATOR);
    }

    public function isPreprocessedPath(string $path): bool
    {
        return str_contains($path, self::PREPROCESSED_SEGMENT);
    }

    public function extractMissingPathFromMessage(string $message): ?string
    {
        if (preg_match(
            '/include\(([^)]+)\): Failed to open stream: No such file or directory/',
            $message,
            $matches
        )) {
            $candidate = trim((string) $matches[1]);
            return $candidate === '' ? null : str_replace('\\', '', $candidate);
        }

        if (preg_match("/Failed opening required '([^']+)'/", $message, $matches)) {
            $candidate = trim((string) $matches[1]);
            return $candidate === '' ? null : str_replace('\\', '', $candidate);
        }

        // Production: Template::fetchView loga e engole — path vem nesta mensagem.
        if (preg_match("/Invalid template file: '([^']+)'/", $message, $matches)) {
            $candidate = trim((string) $matches[1]);
            return $candidate === '' ? null : str_replace('\\', '', $candidate);
        }

        return null;
    }

    public function resolveSourcePath(string $missingPreprocessedPath): ?string
    {
        $needlePosition = strpos($missingPreprocessedPath, self::PREPROCESSED_SEGMENT);
        if ($needlePosition === false) {
            return null;
        }

        $relative = substr($missingPreprocessedPath, $needlePosition + strlen(self::PREPROCESSED_SEGMENT));
        if ($relative === '') {
            return null;
        }

        return $this->basePath . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
    }

    public function ensurePreprocessedExists(string $missingPreprocessedPath): bool
    {
        if (is_file($missingPreprocessedPath)) {
            return true;
        }

        $sourcePath = $this->resolveSourcePath($missingPreprocessedPath);
        if ($sourcePath === null || !is_file($sourcePath)) {
            $this->logger->error(
                '[PreprocessedFallback] Não foi possível resolver template fonte para arquivo preprocessed ausente.',
                [
                    'missing_preprocessed_path' => $missingPreprocessedPath,
                    'source_path' => $sourcePath,
                ]
            );

            return false;
        }

        $targetDirectory = dirname($missingPreprocessedPath);
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            $this->logger->error(
                '[PreprocessedFallback] Falha ao criar diretório do template preprocessed.',
                [
                    'missing_preprocessed_path' => $missingPreprocessedPath,
                    'target_directory' => $targetDirectory,
                ]
            );

            return false;
        }

        if (!@copy($sourcePath, $missingPreprocessedPath)) {
            $this->logger->error(
                '[PreprocessedFallback] Falha ao copiar template fonte para preprocessed.',
                [
                    'missing_preprocessed_path' => $missingPreprocessedPath,
                    'source_path' => $sourcePath,
                ]
            );

            return false;
        }

        @chmod($missingPreprocessedPath, 0664);
        clearstatcache(true, $missingPreprocessedPath);

        $this->logger->warning(
            '[PreprocessedFallback] Template preprocessed ausente foi autocurado com sucesso.',
            [
                'missing_preprocessed_path' => $missingPreprocessedPath,
                'source_path' => $sourcePath,
            ]
        );

        return true;
    }

    public function resolveRenderablePath(string $fileName): string
    {
        if (!$this->isPreprocessedPath($fileName)) {
            return $fileName;
        }

        if (is_file($fileName)) {
            return $fileName;
        }

        $sourcePath = $this->resolveSourcePath($fileName);

        // Autocura para as próximas requests. Esta request usa o fonte para
        // não incluir um dest que o SCD pode apagar no mesmo instante.
        $this->ensurePreprocessedExists($fileName);

        if ($sourcePath !== null && is_file($sourcePath)) {
            $this->logger->warning(
                '[PreprocessedFallback] Renderizando template fonte (preprocessed ausente).',
                [
                    'missing_preprocessed_path' => $fileName,
                    'source_path' => $sourcePath,
                ]
            );

            return $sourcePath;
        }

        return $fileName;
    }
}
