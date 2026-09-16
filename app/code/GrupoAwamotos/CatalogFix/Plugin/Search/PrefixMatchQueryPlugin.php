<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\Search;

use Awa\CatalogFix\Model\Search\SkuQueryClassifier;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface as RequestQueryInterface;

/**
 * SRCH-001: Add match_phrase_prefix clause alongside the default match query.
 *
 * Magento only sends a `match` query to OpenSearch. Single-word partial terms
 * like "retr", "retrovis", "protetor" return 0 results because the match query
 * requires an exact token match. Adding a `match_phrase_prefix` with lower boost
 * allows prefix matching without degrading relevance scoring for exact matches.
 *
 * Only applies to single-word queries without spaces.
 */
class PrefixMatchQueryPlugin
{
    private const MAX_EXPANSIONS = 50;
    private const PREFIX_BOOST_FACTOR = 0.5;

    public function __construct(
        private readonly ?SkuQueryClassifier $skuQueryClassifier = null
    ) {
    }

    private function classifier(): SkuQueryClassifier
    {
        return $this->skuQueryClassifier
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SkuQueryClassifier::class);
    }

    /**
     * After the default match query is built, inject a match_phrase_prefix clause
     * into the bool->should array for single-word search terms.
     *
     * @param MatchQuery $subject
     * @param array $result
     * @param array $selectQuery
     * @param RequestQueryInterface $requestQuery
     * @param string $conditionType
     * @return array
     */
    public function afterBuild(
        MatchQuery $subject,
        array $result,
        array $selectQuery,
        RequestQueryInterface $requestQuery,
        string $conditionType
    ): array {
        if (!$requestQuery instanceof \Magento\Framework\Search\Request\Query\MatchQuery) {
            return $result;
        }
        $queryValue = trim($requestQuery->getValue());

        // Existing catalog SKUs must outrank prefix/substring hits (e.g. 3022 vs LS3022).
        // Text tokens such as "bagageiro" must not receive a fake SKU term boost.
        if ($queryValue !== '' && $this->classifier()->isExistingExactSkuQuery($queryValue)) {
            if (!isset($result['bool']['should']) || !is_array($result['bool']['should'])) {
                $result['bool']['should'] = [];
            }
            $result['bool']['should'][] = [
                'term' => [
                    'sku' => [
                        'value' => $queryValue,
                        'boost' => 50.0,
                    ],
                ],
            ];
        }

        // Only apply prefix matching for single-word queries (no spaces) shorter than 12 chars.
        if (str_contains($queryValue, ' ') || strlen($queryValue) >= 12) {
            return $result;
        }

        // Only inject into "should" conditions
        if (!isset($result['bool']['should'])) {
            return $result;
        }

        $requestQueryBoost = $requestQuery->getBoost() ?: 1;
        $added = false;

        foreach ($requestQuery->getMatches() as $match) {
            if ($added) {
                break;
            }
            $field = '_search';
            $result['bool']['should'][] = [
                'match_phrase_prefix' => [
                    $field => [
                        'query'          => $queryValue,
                        'boost'          => ($requestQueryBoost + ($match['boost'] ?? 1)) * self::PREFIX_BOOST_FACTOR,
                        'max_expansions' => self::MAX_EXPANSIONS,
                    ],
                ],
            ];
            $added = true;
        }

        return $result;
    }
}
