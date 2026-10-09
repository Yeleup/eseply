<?php

namespace App\Reports\Builder\Concerns;

use App\Models\BillingPeriod;
use App\Models\Client;
use App\Reports\Builder\ReportDimension;
use App\Reports\Builder\ReportField;
use App\Reports\Builder\ReportFieldType;
use App\Reports\Concerns\FormatsReportValues;
use App\Support\ClientControllers;
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
     * Controllers whose zone covers the client of the row, by the rule of the clients list
     * (`ClientControllers`): names in alphabetical order separated by commas, each once,
     * and a dash when the client is outside every zone.
     *
     * The zones of every controller of the organization are read once for the whole
     * table or export, so the column adds two queries whatever the number of rows.
     *
     * @param  string|null  $clientRelation  Relation from the row to its client, `null` when the row is the client.
     */
    protected function controllerField(?string $clientRelation): ReportField
    {
        $clientControllers = null;

        return ReportField::resolved(
            'controller',
            'Контроллер',
            function (Model $record) use ($clientRelation, &$clientControllers): ?string {
                $client = $clientRelation === null ? $record : $record->getRelationValue($clientRelation);

                if (! $client instanceof Client) {
                    return null;
                }

                $clientControllers ??= ClientControllers::forOrganization((int) $client->organization_id);
                $names = $clientControllers->namesFor($client);

                return $names === [] ? null : implode(', ', $names);
            },
            $clientRelation === null ? [] : [$clientRelation],
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
}
