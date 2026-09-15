<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Admin;

use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\Reporting;

class ReportingSearchProbePlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly ResourceConnection $resource
    ) {
    }

    public function beforeSearch(
        Reporting $subject,
        SearchCriteriaInterface $searchCriteria
    ): array {
        $requestName = (string) $searchCriteria->getRequestName();
        if ($requestName !== 'sales_order_grid_data_source') {
            return [$searchCriteria];
        }

        $isB2bKeywordSearch = false;
        $newGroups = [];
        foreach ($searchCriteria->getFilterGroups() ?: [] as $group) {
            $keptFilters = [];
            foreach ($group->getFilters() ?: [] as $filter) {
                if ($filter->getField() === 'fulltext'
                    && mb_strtolower(trim((string) $filter->getValue())) === 'b2b'
                ) {
                    $isB2bKeywordSearch = true;
                    continue;
                }
                $keptFilters[] = $filter;
            }

            if ($keptFilters !== []) {
                $newGroups[] = $this->filterGroupBuilder->setFilters($keptFilters)->create();
            }
        }

        $eligibleGroupIds = B2bGroupIds::resolve($this->resource);
        if ($isB2bKeywordSearch) {
            $newGroups[] = $this->filterGroupBuilder->setFilters([
                $this->filterBuilder
                    ->setField('customer_group')
                    ->setConditionType('in')
                    ->setValue(implode(',', $eligibleGroupIds))
                    ->create()
            ])->create();
        }

        $searchCriteria->setFilterGroups($newGroups);

        return [$searchCriteria];
    }
}
