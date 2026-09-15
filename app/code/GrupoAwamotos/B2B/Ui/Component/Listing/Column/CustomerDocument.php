<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Admin customer grid: show taxvat, falling back to b2b_cnpj when empty.
 */
class CustomerDocument extends Column
{
    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }

        $columnName = (string) $this->getData('name');
        $emptyTaxvat = 0;
        $fallbackUsed = 0;
        $resolvedNonEmpty = 0;

        foreach ($dataSource['data']['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $taxvatEmpty = trim((string) ($item['taxvat'] ?? '')) === '';
            if ($taxvatEmpty) {
                $emptyTaxvat++;
            }
            $item[$columnName] = self::resolve($item);
            if ($taxvatEmpty && $item[$columnName] !== '') {
                $fallbackUsed++;
            }
            if ($item[$columnName] !== '') {
                $resolvedNonEmpty++;
            }
        }
        unset($item);

        // #region agent log
        $cfg = $this->getData('config');
        @file_put_contents('/home/jessessh/htdocs/srv1113343.hstgr.cloud/.cursor/debug-6a6568.log', json_encode([
            'sessionId' => '6a6568',
            'runId' => 'grid-reload',
            'hypothesisId' => 'A',
            'location' => 'CustomerDocument.php:prepareDataSource',
            'message' => 'customer grid document column prepare',
            'data' => [
                'itemCount' => count($dataSource['data']['items']),
                'emptyTaxvat' => $emptyTaxvat,
                'fallbackUsed' => $fallbackUsed,
                'resolvedNonEmpty' => $resolvedNonEmpty,
                'columnVisible' => $cfg['visible'] ?? null,
                'columnName' => $columnName,
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ]) . "\n", FILE_APPEND);
        // #endregion

        return $dataSource;
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function resolve(array $item): string
    {
        $taxvat = trim((string) ($item['taxvat'] ?? ''));
        if ($taxvat !== '') {
            return $taxvat;
        }

        return trim((string) ($item['b2b_cnpj'] ?? ''));
    }
}
