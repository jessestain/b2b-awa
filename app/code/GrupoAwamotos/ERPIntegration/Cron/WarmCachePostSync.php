<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Warms FPC for critical pages after ERP stock sync invalidates cache.
 * Runs at :05 and :35 (5 min after each stock sync at :00/:30).
 */
class WarmCachePostSync
{
    private const BASE_URL = 'https://awamotos.com';
    private const UA = 'AwaMotos-CacheWarmer/3.0';
    private const TIMEOUT = 15;
    private const CONCURRENCY = 4;

    private static array $staticPaths = [
        '/',
        '/retrovisores.html',
        '/bagageiros.html',
        '/bauletos.html',
        '/guidoes.html',
        '/piscas.html',
        '/lentes.html',
        '/carcacas.html',
        '/estribos.html',
        '/retrovisores/linha-original.html',
        '/retrovisores/cromados.html',
        '/bauletos/bauletos-34-l.html',
        '/checkout/cart',
        '/catalogsearch/result/?q=freio',
        '/catalogsearch/result/?q=bagageiro',
        '/catalogsearch/result/?q=retrovisor',
        '/catalogsearch/result/?q=titan+160',
        '/catalogsearch/result/?q=cg+160',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $logFile = BP . '/var/log/cache_warmer.log';
        $start = microtime(true);

        $urls = $this->buildUrlList();
        $total = count($urls);

        file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . '] Cache warmer iniciado (concurrency=' . self::CONCURRENCY . ')' . PHP_EOL,
            FILE_APPEND
        );

        [$ok, $fail] = $this->warmUrls($urls, $logFile);

        $elapsed = round(microtime(true) - $start, 1);
        file_put_contents(
            $logFile,
            '[' . date('Y-m-d H:i:s') . "] Warmer concluido: {$ok}/{$total} OK, {$fail} falhas ({$elapsed}s)" . PHP_EOL,
            FILE_APPEND
        );

        $this->logger->info("[CacheWarmer] {$ok}/{$total} OK in {$elapsed}s");
    }

    private function buildUrlList(): array
    {
        $urls = array_map(
            fn($path) => self::BASE_URL . $path,
            self::$staticPaths
        );

        try {
            $connection = $this->resourceConnection->getConnection();

            $categoryUrls = $connection->fetchCol(
                "SELECT CONCAT('" . self::BASE_URL . "/', ur.request_path)
                 FROM url_rewrite ur
                 JOIN catalog_category_entity cce ON cce.entity_id = ur.entity_id AND cce.level >= 2
                 JOIN catalog_category_entity_int ccei
                     ON ccei.entity_id = ur.entity_id
                    AND ccei.attribute_id = (
                            SELECT attribute_id FROM eav_attribute
                            WHERE attribute_code = 'is_active' AND entity_type_id = 3
                        )
                    AND ccei.store_id = 1
                    AND ccei.value = 1
                 WHERE ur.entity_type = 'category'
                   AND ur.store_id = 1
                   AND ur.redirect_type = 0
                   AND ur.request_path NOT LIKE '%-erp-%'
                   AND ur.request_path NOT LIKE '%/%/%'
                 ORDER BY ur.request_path
                 LIMIT 20"
            );

            $productUrls = $connection->fetchCol(
                "SELECT CONCAT('" . self::BASE_URL . "/', ur.request_path)
                 FROM url_rewrite ur
                 JOIN catalog_product_entity_int cpes
                     ON cpes.entity_id = ur.entity_id
                    AND cpes.attribute_id = (
                            SELECT attribute_id FROM eav_attribute
                            WHERE attribute_code = 'status' AND entity_type_id = 4
                        )
                    AND cpes.store_id = 0
                    AND cpes.value = 1
                 JOIN catalog_product_entity_int cpev
                     ON cpev.entity_id = ur.entity_id
                    AND cpev.attribute_id = (
                            SELECT attribute_id FROM eav_attribute
                            WHERE attribute_code = 'visibility' AND entity_type_id = 4
                        )
                    AND cpev.store_id = 0
                    AND cpev.value IN (2, 3, 4)
                 WHERE ur.entity_type = 'product'
                   AND ur.store_id = 1
                   AND ur.redirect_type = 0
                   AND ur.request_path NOT LIKE '%-erp-%'
                 ORDER BY RAND()
                 LIMIT 30"
            );

            $urls = array_merge($urls, $categoryUrls ?: [], $productUrls ?: []);
        } catch (\Throwable $e) {
            $this->logger->warning('[CacheWarmer] DB URL fetch failed: ' . $e->getMessage());
        }

        return array_unique($urls);
    }

    /** @return array{int, int} [ok, fail] */
    private function warmUrls(array $urls, string $logFile): array
    {
        $ok = 0;
        $fail = 0;
        $chunks = array_chunk($urls, self::CONCURRENCY);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => self::TIMEOUT,
                    CURLOPT_USERAGENT      => self::UA,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HEADER         => false,
                    CURLOPT_NOBODY         => false,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$url] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $url => $ch) {
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code === 200) {
                    $ok++;
                    file_put_contents($logFile, "[OK] {$url}" . PHP_EOL, FILE_APPEND);
                } else {
                    $fail++;
                    file_put_contents($logFile, "[FAIL {$code}] {$url}" . PHP_EOL, FILE_APPEND);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return [$ok, $fail];
    }
}
