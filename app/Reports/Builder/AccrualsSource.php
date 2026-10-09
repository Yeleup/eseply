<?php

namespace App\Reports\Builder;

use App\ClientType;
use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\DateRangeFilter;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Builder\Concerns\DescribesClientRows;
use App\Reports\TurnoverBalanceSheetReport;
use App\Reports\TurnoverBalanceValues;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * One row is the accrual of one client for the billing period of the report.
 *
 * The rows and their amounts come from `TurnoverBalanceValues`, the engine of the
 * turnover balance sheet, so the builder never disagrees with that report: a closed
 * month reads the stored accruals, any other month is still being collected and
 * reads its receipts, payments and balance adjustments.
 *
 * The values are selected into a derived table named `clients`, so the formulas
 * of the fields can read them, and the client filters keep working on it.
 */
final class AccrualsSource implements ReportSource
{
    use DescribesClientRows;

    /**
     * Alias of the date the accrual was made on: the closing date of a closed month,
     * otherwise the date of the receipt of the month.
     */
    public const string ACCRUED_AT = 'accrued_at';

    public function key(): string
    {
        return 'accruals';
    }

    public function label(): string
    {
        return 'Начисления';
    }

    public function hint(): string
    {
        return 'Строка — начисление абонента за месяц.';
    }

    public function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $values = Client::query()
            ->select('clients.*')
            ->visibleToOrganizationMember($user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            foreach (TurnoverBalanceValues::aliases() as $alias) {
                $values->selectRaw("0 as {$alias}");
            }

            $values
                ->selectRaw('null as '.self::ACCRUED_AT)
                ->whereRaw('1 = 0');
        } elseif (TurnoverBalanceValues::isClosed($billingPeriod)) {
            $values
                ->join('accruals', function (JoinClause $join) use ($billingPeriod): void {
                    TurnoverBalanceValues::joinAccrual($join, $billingPeriod);
                })
                ->addSelect(TurnoverBalanceValues::closedPeriodColumns())
                ->addSelect('accruals.closed_at as '.self::ACCRUED_AT);
        } else {
            $values
                ->where('clients.status', 'active')
                ->addSelect(TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod))
                ->addSelect([
                    self::ACCRUED_AT => DB::table('receipts')
                        ->select('receipts.issued_at')
                        ->whereColumn('receipts.client_id', 'clients.id')
                        ->where('receipts.billing_period_id', $billingPeriod->getKey())
                        ->limit(1),
                ]);
        }

        return Client::query()
            ->fromSub($values, 'clients')
            ->select('clients.*');
    }

    public function orderRows(Builder $query): Builder
    {
        return $query
            ->orderBy('clients.account_number')
            ->orderBy('clients.id');
    }

    public function fields(): array
    {
        $opening = TurnoverBalanceValues::openingBalanceExpression('clients.');
        $closing = TurnoverBalanceValues::closingBalanceExpression('clients.');
        $accrued = self::accruedExpression();
        $paid = self::paidExpression();

        return [
            $this->accountNumberField(),
            $this->clientNameField(),
            $this->addressField(null),
            $this->billingPeriodField(),
            ReportField::column('opening_balance', 'Сальдо на начало', ReportFieldType::Money, $opening),
            ReportField::column('accrued_amount', 'Начислено', ReportFieldType::Money, $accrued),
            ReportField::column('paid_amount', 'Оплачено', ReportFieldType::Money, $paid),
            ReportField::column('closing_balance', 'Долг на конец', ReportFieldType::Money, $closing),
            ReportField::computed('collection_percent', 'Собираемость, %', ReportFieldType::Percent, "{$paid} / nullif({$accrued}, 0) * 100"),
            ReportField::computed('debt_growth', 'Прирост долга', ReportFieldType::Money, "{$closing} - {$opening}"),
        ];
    }

    public function defaultFieldKeys(): array
    {
        return ['account_number', 'client_name', 'address', 'opening_balance', 'accrued_amount', 'paid_amount', 'closing_balance'];
    }

    public function dimensions(): array
    {
        return [
            ...$this->addressDimensions(),
            ReportDimension::column('client_type', 'По типам абонентов', 'Тип абонента', 'client_type', self::clientTypeLabels()),
        ];
    }

    /**
     * The collection of a group is its payments divided by its accruals, never
     * an average of the percentages of its clients.
     */
    public function metrics(): array
    {
        return [
            ReportMetric::clients(),
            ReportMetric::count('Начислений'),
            ReportMetric::sum('Начислено', 'accrued_amount'),
            ReportMetric::average('Среднее начисление', 'accrued_amount'),
            ReportMetric::percentage('collection_percent', 'Собираемость, %', 'paid_amount', 'accrued_amount'),
        ];
    }

    public function summaryColumns(): array
    {
        return [
            'client_id' => 'clients.id',
            'region_id' => 'clients.region_id',
            'street_id' => 'clients.street_id',
            'client_type' => 'clients.client_type',
            'accrued_amount' => self::accruedExpression(),
            'paid_amount' => self::paidExpression(),
        ];
    }

    public function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization, null),
            DateRangeFilter::make(self::ACCRUED_AT, 'Дата начисления', 'clients.'.self::ACCRUED_AT),
            SelectFilter::make('client_type')
                ->label('Тип абонента')
                ->placeholder('Все типы')
                ->options(self::clientTypeLabels())
                ->query(function (Builder $query, array $data): Builder {
                    $clientType = is_string($data['value'] ?? null) ? ClientType::tryFrom($data['value']) : null;

                    return $clientType instanceof ClientType
                        ? $query->where('clients.client_type', $clientType->value)
                        : $query;
                }),
        ];
    }

    /**
     * The same month the turnover balance sheet opens with.
     */
    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod
    {
        return app(TurnoverBalanceSheetReport::class)->defaultBillingPeriodFor($organization);
    }

    private static function accruedExpression(): string
    {
        return TurnoverBalanceValues::metricExpressions('clients.')['accrued_amount'];
    }

    private static function paidExpression(): string
    {
        return TurnoverBalanceValues::metricExpressions('clients.')['paid_amount'];
    }

    /**
     * @return array<string, string>
     */
    private static function clientTypeLabels(): array
    {
        $labels = [];

        foreach (ClientType::cases() as $clientType) {
            $labels[$clientType->value] = (string) $clientType->getLabel();
        }

        return $labels;
    }
}
