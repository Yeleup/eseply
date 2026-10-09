<?php

namespace App\Reports\Builder;

use App\Models\Organization;
use App\OrganizationMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The summary of a built report: its groups and the «Итого» row in one SQL statement.
 *
 * The rows are the already filtered source query reduced to its summary columns.
 * The groups are a `GROUP BY` over them; the «Итого» row is the same rows joined the
 * same way, aggregated without grouping, and appended with `UNION ALL`. One metric
 * expression computes both, so sums add up across the groups while averages and
 * percentages are recomputed from the totals of their own row.
 *
 * In the summary by controllers a row belongs to every controller whose zone matches
 * the client, so the sums of «Итого» repeat the sum of the visible groups, while
 * «Абонентов» of «Итого» counts each client once.
 */
final class ReportSummaryQuery
{
    public const string TOTAL_LABEL = 'Итого';

    /**
     * Group records followed by the «Итого» record, or nothing when there is no group.
     *
     * @param  Builder<Model>  $filteredQuery  Source query with the user filters applied.
     * @return array<string, array<string, mixed>>
     */
    public function records(ReportBuild $build, Builder $filteredQuery, Organization $organization): array
    {
        $dimension = $build->dimension;

        if (! $dimension instanceof ReportDimension) {
            return [];
        }

        $rows = $this->rows($build->source, $filteredQuery);

        $groups = DB::query()->fromSub($rows, 'report_rows');
        $this->joinDimension($groups, $dimension, $organization);
        $this->selectGroup($groups, $dimension);
        $groups->selectRaw('0 as is_total');
        $this->selectMetrics($groups, $build->metrics);

        $total = DB::query()->fromSub($rows, 'report_rows');
        $this->joinDimension($total, $dimension, $organization);
        $total->selectRaw('null as group_key, null as group_name, null as group_parent, null as group_sort, 1 as is_total');
        $this->selectMetrics($total, $build->metrics);

        $result = $groups
            ->unionAll($total)
            ->orderBy('is_total')
            ->orderBy('group_sort')
            ->orderBy('group_name')
            ->orderBy('group_key')
            ->get();

        if ($result->count() < 2) {
            return [];
        }

        $records = [];

        foreach ($result as $row) {
            $isTotal = (bool) $row->is_total;

            $record = [
                'group_label' => $isTotal ? self::TOTAL_LABEL : $this->groupLabel($dimension, $row),
                'is_total' => $isTotal,
            ];

            foreach ($build->metrics as $metric) {
                $record[$metric->key] = $this->metricValue($metric, $row->{$metric->key} ?? null);
            }

            $records[$isTotal ? 'total' : 'group:'.($row->group_key ?? 'none')] = $record;
        }

        return $records;
    }

    /**
     * The source query reduced to the columns the summary reads. Eager loads and
     * the order of the detail table do not reach the database.
     *
     * @param  Builder<Model>  $filteredQuery
     */
    private function rows(ReportSource $source, Builder $filteredQuery): QueryBuilder
    {
        $columns = [];

        foreach ($source->summaryColumns() as $alias => $expression) {
            $columns[] = DB::raw("({$expression}) as {$alias}");
        }

        return (clone $filteredQuery)
            ->reorder()
            ->toBase()
            ->select($columns);
    }

    private function joinDimension(QueryBuilder $query, ReportDimension $dimension, Organization $organization): void
    {
        match ($dimension->key) {
            ReportDimension::CITY => $query
                ->leftJoin('regions as report_regions', 'report_regions.id', '=', 'report_rows.region_id')
                ->leftJoin('cities as report_cities', 'report_cities.id', '=', 'report_regions.city_id'),
            ReportDimension::REGION => $query
                ->leftJoin('regions as report_regions', 'report_regions.id', '=', 'report_rows.region_id'),
            ReportDimension::STREET => $query
                ->leftJoin('streets as report_streets', 'report_streets.id', '=', 'report_rows.street_id')
                ->leftJoin('regions as report_regions', 'report_regions.id', '=', 'report_streets.region_id'),
            ReportDimension::CONTROLLER => $this->joinControllers($query, $organization),
            default => null,
        };
    }

    /**
     * A row joins every controller of the organization whose region or street matches
     * its client. A controller matching by both still joins the row once.
     */
    private function joinControllers(QueryBuilder $query, Organization $organization): void
    {
        $query
            ->join('organization_user as report_members', function (JoinClause $join) use ($organization): void {
                $join
                    ->where('report_members.organization_id', '=', $organization->getKey())
                    ->where('report_members.role', '=', OrganizationMemberRole::Controller->value);
            })
            ->join('users as report_controllers', 'report_controllers.id', '=', 'report_members.user_id')
            ->where(function (QueryBuilder $query) use ($organization): void {
                $query
                    ->whereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_regions')
                            ->where('organization_user_regions.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_regions.user_id', 'report_controllers.id')
                            ->whereColumn('organization_user_regions.region_id', 'report_rows.region_id');
                    })
                    ->orWhereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_streets')
                            ->where('organization_user_streets.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_streets.user_id', 'report_controllers.id')
                            ->whereColumn('organization_user_streets.street_id', 'report_rows.street_id');
                    });
            });
    }

    /**
     * Every dimension selects the same four columns, so the groups and the «Итого»
     * row can be one `UNION ALL`.
     */
    private function selectGroup(QueryBuilder $query, ReportDimension $dimension): void
    {
        match ($dimension->key) {
            ReportDimension::CITY => $query
                ->selectRaw('report_cities.id as group_key, report_cities.name as group_name, null as group_parent, null as group_sort')
                ->groupBy('report_cities.id', 'report_cities.name'),
            ReportDimension::REGION => $query
                ->selectRaw('report_regions.id as group_key, report_regions.name as group_name, null as group_parent, null as group_sort')
                ->groupBy('report_regions.id', 'report_regions.name'),
            ReportDimension::STREET => $query
                ->selectRaw('report_streets.id as group_key, report_streets.name as group_name, report_regions.name as group_parent, report_regions.name as group_sort')
                ->groupBy('report_streets.id', 'report_streets.name', 'report_regions.name'),
            ReportDimension::CONTROLLER => $query
                ->selectRaw('report_controllers.id as group_key, report_controllers.name as group_name, null as group_parent, null as group_sort')
                ->groupBy('report_controllers.id', 'report_controllers.name'),
            default => $this->selectColumnGroup($query, $dimension),
        };
    }

    /**
     * A source dimension is shown in the order of its labels, the catalog order.
     */
    private function selectColumnGroup(QueryBuilder $query, ReportDimension $dimension): void
    {
        $column = 'report_rows.'.$dimension->column;
        $cases = [];
        $bindings = [];

        foreach (array_keys($dimension->labels) as $position => $value) {
            $cases[] = "when {$column} = ? then {$position}";
            $bindings[] = (string) $value;
        }

        $sort = $cases === []
            ? '0'
            : 'case '.implode(' ', $cases).' else '.count($cases).' end';

        $query
            ->selectRaw("{$column} as group_key, null as group_name, null as group_parent, min({$sort}) as group_sort", $bindings)
            ->groupBy($column);
    }

    /**
     * @param  list<ReportMetric>  $metrics
     */
    private function selectMetrics(QueryBuilder $query, array $metrics): void
    {
        foreach ($metrics as $metric) {
            $query->selectRaw("{$metric->expression} as {$metric->key}");
        }
    }

    private function groupLabel(ReportDimension $dimension, object $row): string
    {
        if ($dimension->column !== null) {
            return $dimension->labelFor($row->group_key);
        }

        $name = filled($row->group_name) ? (string) $row->group_name : $dimension->emptyLabel;

        if ($dimension->key === ReportDimension::STREET && filled($row->group_parent)) {
            return $row->group_parent.' / '.$name;
        }

        return $name;
    }

    private function metricValue(ReportMetric $metric, mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return $metric->type === ReportFieldType::Int ? (int) $value : (float) $value;
    }
}
