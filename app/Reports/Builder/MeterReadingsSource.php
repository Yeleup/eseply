<?php

namespace App\Reports\Builder;

use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\DateRangeFilter;
use App\Models\BillingPeriod;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Builder\Concerns\DescribesClientRows;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row is one meter reading taken in the billing period of the report.
 *
 * The rows are those of «Отчёт по потреблениям»: the readings of the billing period
 * whose current value is filled. A visit that took no reading has no current value
 * and no consumption, so it is not a row.
 */
final class MeterReadingsSource implements ReportSource
{
    use DescribesClientRows;

    /**
     * Labels of the meter statuses the own filter accepts.
     *
     * @var array<string, string>
     */
    private const array METER_STATUS_LABELS = [
        'active' => 'Активные',
        'removed' => 'Снятые',
    ];

    public function key(): string
    {
        return 'readings';
    }

    public function label(): string
    {
        return 'Показания счётчиков';
    }

    public function hint(): string
    {
        return 'Строка — показание по счётчику.';
    }

    /**
     * The client, the meter and the billing period are joined rather than only checked,
     * so their columns sort, search, filter and group the readings without a query per row.
     */
    public function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = MeterReading::query()
            ->select('meter_readings.*')
            ->join('clients', 'clients.id', '=', 'meter_readings.client_id')
            ->join('meters', 'meters.id', '=', 'meter_readings.meter_id')
            ->join('billing_periods', 'billing_periods.id', '=', 'meter_readings.billing_period_id')
            ->where('meter_readings.organization_id', $organization->getKey())
            ->whereHas(
                'client',
                fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organization),
            );

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('meter_readings.billing_period_id', $billingPeriod->getKey())
            ->taken();
    }

    public function orderRows(Builder $query): Builder
    {
        return $query
            ->orderBy('clients.account_number')
            ->orderBy('meters.number')
            ->orderBy('meter_readings.id');
    }

    public function fields(): array
    {
        return [
            $this->accountNumberField(),
            $this->clientNameField(),
            $this->addressField('client'),
            ReportField::column('meter_number', 'Счётчик', ReportFieldType::Text, 'meters.number', searchable: true),
            ReportField::column('read_at', 'Дата снятия', ReportFieldType::Date, 'meter_readings.read_at'),
            ReportField::column('previous_reading', 'Предыдущее', ReportFieldType::Int, 'meter_readings.previous_reading'),
            ReportField::column('current_reading', 'Текущее', ReportFieldType::Int, 'meter_readings.current_reading'),
            ReportField::column('consumption', 'Потребление', ReportFieldType::Int, 'meter_readings.consumption'),
            $this->controllerField('client'),
            ReportField::computed(
                'daily_consumption',
                'Среднесуточное, м³',
                ReportFieldType::Float,
                'meter_readings.consumption / nullif(dayofmonth(last_day(billing_periods.starts_on)), 0)',
            ),
        ];
    }

    public function defaultFieldKeys(): array
    {
        return ['account_number', 'client_name', 'address', 'meter_number', 'read_at', 'current_reading', 'consumption'];
    }

    public function dimensions(): array
    {
        return $this->addressDimensions();
    }

    /**
     * The consumption is a whole number like everywhere else, its average is a fraction
     * of the group sum by the number of readings of the group.
     */
    public function metrics(): array
    {
        return [
            ReportMetric::clients(),
            ReportMetric::count('Показаний'),
            ReportMetric::sum('Потребление', 'consumption', ReportFieldType::Int),
            ReportMetric::average('Среднее потребление', 'consumption', ReportFieldType::Float),
        ];
    }

    public function summaryColumns(): array
    {
        return [
            'client_id' => 'clients.id',
            'region_id' => 'clients.region_id',
            'street_id' => 'clients.street_id',
            'consumption' => 'meter_readings.consumption',
        ];
    }

    public function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization),
            DateRangeFilter::make('read_at', 'Дата снятия', 'meter_readings.read_at'),
            SelectFilter::make('meter_status')
                ->label('Статус счётчика')
                ->placeholder('Все счётчики')
                ->options(self::METER_STATUS_LABELS)
                ->query(function (Builder $query, array $data): Builder {
                    $status = $data['value'] ?? null;

                    return is_string($status) && array_key_exists($status, self::METER_STATUS_LABELS)
                        ? $query->where('meters.status', $status)
                        : $query;
                }),
        ];
    }

    /**
     * Readings are entered while a month is open, so the current one is shown first,
     * like in «Отчёт по потреблениям».
     */
    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod
    {
        return BillingPeriod::currentEditableFor($organization)
            ?? BillingPeriod::query()
                ->forOrganization($organization)
                ->orderByDesc('starts_on')
                ->first();
    }
}
