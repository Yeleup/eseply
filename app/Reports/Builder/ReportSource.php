<?php

namespace App\Reports\Builder;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A data source of the report builder: what one row is, which fields, dimensions
 * and metrics it offers and which filters narrow it.
 *
 * Everything the operator chooses on the page is looked up in this catalog by key,
 * so no table, column or SQL from the request ever reaches a query.
 */
interface ReportSource
{
    public function key(): string;

    public function label(): string;

    public function hint(): string;

    /**
     * Rows of the source for the billing period, already limited to the organization
     * and to the clients the user may see. Every field expression is valid on it,
     * and the user filters are applied on top of it, so they can only narrow it.
     *
     * @return Builder<Model>
     */
    public function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder;

    /**
     * Order of the rows while the operator has not sorted the table.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function orderRows(Builder $query): Builder;

    /**
     * Fields in catalog order, which is also the column order of the report.
     *
     * @return list<ReportField>
     */
    public function fields(): array;

    /**
     * @return list<string>
     */
    public function defaultFieldKeys(): array;

    /**
     * @return list<ReportDimension>
     */
    public function dimensions(): array;

    public function defaultDimensionKey(): string;

    /**
     * @return list<ReportMetric>
     */
    public function metrics(): array;

    /**
     * Columns of one summary row, keyed by alias, as SQL expressions of the source query.
     *
     * They always include `client_id`, `region_id` and `street_id`, plus every column
     * the metrics and the source dimension read.
     *
     * @return array<string, string>
     */
    public function summaryColumns(): array;

    /**
     * Filters of the source. The screen table, the summary and the XLSX export share
     * this definition, so all of them apply identical rules.
     *
     * @return list<BaseFilter>
     */
    public function filters(Organization $organization): array;

    /**
     * Summary row column holding the amount of a row.
     */
    public function sumColumn(): string;

    /**
     * Caption of the number of rows, such as «Оплат».
     */
    public function countLabel(): string;

    /**
     * Caption of the total amount, such as «Сумма оплат».
     */
    public function sumLabel(): string;

    /**
     * Caption of the average amount, such as «Средняя оплата».
     */
    public function averageLabel(): string;

    /**
     * Billing period used while the operator has not chosen one.
     */
    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod;
}
