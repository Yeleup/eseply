<?php

namespace App\Reports\Builder\Concerns;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Reports\Builder\ReportDimension;
use App\Reports\Builder\ReportField;
use App\Reports\Builder\ReportFieldType;
use App\Reports\Builder\ReportMetric;
use App\Reports\Concerns\FormatsReportValues;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalog entries shared by every source whose row belongs to one client.
 *
 * The source query always exposes the client columns under the `clients` alias,
 * so the account number, the name and the address dimensions read the same columns
 * whatever the row is.
 */
trait DescribesClientRows
{
    use FormatsReportValues;

    public function defaultDimensionKey(): string
    {
        return ReportDimension::STREET;
    }

    protected function accountNumberField(): ReportField
    {
        return ReportField::column('account_number', 'Лицевой счёт', ReportFieldType::Text, 'clients.account_number', searchable: true);
    }

    protected function clientNameField(): ReportField
    {
        return ReportField::column('client_name', 'Абонент', ReportFieldType::Text, 'clients.name', searchable: true);
    }

    /**
     * The address is formatted exactly like in the other reports, from the region and
     * the street loaded for the whole page at once.
     *
     * @param  string|null  $clientRelation  Relation from the row to its client, `null` when the row is the client.
     */
    protected function addressField(?string $clientRelation): ReportField
    {
        $prefix = $clientRelation === null ? '' : $clientRelation.'.';

        return ReportField::resolved(
            'address',
            'Адрес',
            function (Model $record) use ($clientRelation): string {
                $client = $clientRelation === null ? $record : $record->getRelationValue($clientRelation);

                return $this->formatClientAddress($client instanceof Client ? $client : null);
            },
            [$prefix.'region', $prefix.'street'],
        );
    }

    /**
     * Every row belongs to the billing period of the report.
     */
    protected function billingPeriodField(): ReportField
    {
        return ReportField::resolved(
            'billing_period',
            'Расчётный месяц',
            fn (Model $record, ?BillingPeriod $billingPeriod): ?string => $billingPeriod?->label,
        );
    }

    /**
     * @return list<ReportDimension>
     */
    protected function addressDimensions(): array
    {
        return [
            ReportDimension::city(),
            ReportDimension::region(),
            ReportDimension::street(),
            ReportDimension::controller(),
        ];
    }

    /**
     * Distinct clients, number of rows, total amount and the average amount of a row.
     *
     * @return list<ReportMetric>
     */
    protected function standardMetrics(): array
    {
        return [
            ReportMetric::clients(),
            ReportMetric::count($this->countLabel()),
            ReportMetric::sum($this->sumLabel(), $this->sumColumn()),
            ReportMetric::average($this->averageLabel(), $this->sumColumn()),
        ];
    }
}
