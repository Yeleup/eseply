<?php

namespace App\Reports\Concerns;

use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;

trait AppliesReportFilters
{
    /**
     * Filters are applied exactly the way Filament applies them to the screen table:
     * base-query callbacks first, then every filter inside one nested group.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<BaseFilter>  $reportFilters
     * @param  array<string, array<string, mixed>>  $filters  Applied table filter state, keyed by filter name.
     * @return Builder<TModel>
     */
    protected function applyReportFilters(Builder $query, array $reportFilters, array $filters): Builder
    {
        foreach ($reportFilters as $filter) {
            $filter->applyToBaseQuery($query, $filters[$filter->getName()] ?? []);
        }

        return $query->where(function (Builder $query) use ($reportFilters, $filters): void {
            foreach ($reportFilters as $filter) {
                $filter->apply($query, $filters[$filter->getName()] ?? []);
            }
        });
    }
}
