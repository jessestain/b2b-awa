<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Shell;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class CacheWarmupLauncher
{
    private const LOCK_FILENAME = 'cache_warmer_launcher.lock';
    private const CURSOR_DEV_FLAG = 'tmp/cursor-dev-active';
    private const LOCK_TTL_SECONDS = 180;
    private const CACHE_WARMER_RELATIVE_PATH = 'scripts/cache_warmer.sh';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly Shell $shell,
        private readonly LoggerInterface $logger
    ) {
    }

    public function runIfEligible(string $reason, ?bool $maintenanceIsOn = null): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $rootPath = rtrim($this->directoryList->getRoot(), '/');
        $varPath = rtrim($this->directoryList->getPath(DirectoryList::VAR_DIR), '/');
        $scriptPath = $rootPath . '/' . self::CACHE_WARMER_RELATIVE_PATH;

        if (!is_file($scriptPath) || !is_executable($scriptPath)) {
            $this->logger->warning('[Theme] Cache warmer script indisponível; warm-up ignorado.', [
                'reason' => $reason,
                'script_path' => $scriptPath,
            ]);
            return;
        }

        $maintenanceEnabled = $maintenanceIsOn ?? is_file($varPath . '/.maintenance.flag');
        if ($maintenanceEnabled) {
            $this->logger->info('[Theme] Warm-up ignorado porque maintenance mode está ativo.', [
                'reason' => $reason,
            ]);
            return;
        }

        if (is_file($varPath . '/' . self::CURSOR_DEV_FLAG)) {
            $this->logger->info('[Theme] Warm-up ignorado — sessão Cursor IDE ativa.', [
                'reason' => $reason,
            ]);
            return;
        }

        if ($this->isRecentlyTriggered($varPath, $reason)) {
            return;
        }

        try {
            $this->logger->info('[Theme] Iniciando cache warm-up automático.', [
                'reason' => $reason,
                'script_path' => $scriptPath,
            ]);
            $this->shell->execute('bash %s 3 >> %s 2>&1', [
                $scriptPath,
                $varPath . '/log/cache_warmer.log',
            ]);
        } catch (LocalizedException $exception) {
            $this->logger->error('[Theme] Falha ao executar cache warmer: ' . $exception->getMessage(), [
                'reason' => $reason,
            ]);
        }
    }

    private function isRecentlyTriggered(string $varPath, string $reason): bool
    {
        $lockPath = $varPath . '/' . self::LOCK_FILENAME;
        $now = time();
        $lastTrigger = is_file($lockPath) ? (int) filemtime($lockPath) : 0;

        if ($lastTrigger > 0 && ($now - $lastTrigger) < self::LOCK_TTL_SECONDS) {
            $this->logger->info('[Theme] Warm-up automático ignorado por throttle.', [
                'reason' => $reason,
                'seconds_since_last_trigger' => $now - $lastTrigger,
            ]);
            return true;
        }

        @touch($lockPath);

        return false;
    }
}
