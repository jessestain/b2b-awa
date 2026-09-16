<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model\Search;

use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Drops SKU/audit noise from the public popular-search cloud.
 */
final class PopularSearchTermQuality implements ArgumentInterface
{
    public function isDisplayable(string $queryText, ?int $numResults = null): bool
    {
        $label = trim($queryText);
        if ($label === '' || mb_strlen($label) < 2) {
            return false;
        }

        if (preg_match('/^zzz/i', $label) === 1) {
            return false;
        }

        if ($numResults !== null && $numResults <= 0) {
            return false;
        }

        if (preg_match('/^\d+$/u', $label) === 1) {
            return false;
        }

        if (preg_match('/^\d{2,6}(?:\s+[A-Za-z]{1,3})?$/u', $label) === 1) {
            return false;
        }

        return true;
    }
}
