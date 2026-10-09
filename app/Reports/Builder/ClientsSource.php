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
use App\Reports\TurnoverBalanceValues;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;

/**
 * One row is one client of the organization, active or not, with its debt at the end
 * of the billing period of the report.
 *
 * The debt comes from `TurnoverBalanceValues`, the engine of the turnover balance sheet,
 * the payment desk, the dashboard and «Отчёт по долгам», so the numbers never disagree:
 *
 * - a closed month reads the stored accrual of the client for that month; a client
 *   without one (inactive at the closing or created later) has no balance in that
 *   month, and its debt is a dash rather than a made up zero;
 * - any other month is still being collected, so the balance is computed today from
 *   the previous accrual and the receipts, payments and adjustments of the month, for
 *   inactive clients too, exactly as the payment desk shows it.
 *
 * The debt is the positive part of the closing balance, like «Долг» of the payment desk
 * and the dashboard: an overpayment is not a debt and counts as zero.
 *
 * The values are selected into a derived table named `clients`, so the formulas of the
 * fields can read them, and the client filters keep working on it.
 */
final class ClientsSource implements ReportSource
{
    use DescribesClientRows;

    /**
     * Alias of the flag telling whether the client has a balance in the billing period.
     */
    public const string HAS_BALANCE = 'has_balance';

    /**
     * Labels of the client statuses, in the order the summary shows them.
     *
     * @var array<string, string>
     */
    private const array STATUS_LABELS = [
        'active' => 'Активный',
        'inactive' => 'Неактивный',
    ];

    public function key(): string
    {
        return 'clients';
    }

    public function label(): string
    {
        return 'Абоненты';
    }

    public function hint(): string
    {
        return 'Строка — абонент организации.';
    }

    public function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $values = Client::query()
            ->select('clients.*')
            ->leftJoin('utility_services as client_services', 'client_services.id', '=', 'clients.utility_service_id')
            ->addSelect('client_services.name as utility_service_name')
            ->visibleToOrganizationMember($user, $organization);

        if (! $billingPeriod instanceof BillingPeriod) {
            foreach (TurnoverBalanceValues::aliases() as $alias) {
                $values->selectRaw("0 as {$alias}");
            }

            $values
                ->selectRaw('0 as '.self::HAS_BALANCE)
                ->whereRaw('1 = 0');
        } elseif (TurnoverBalanceValues::isClosed($billingPeriod)) {
            $values
                ->leftJoin('accruals', function (JoinClause $join) use ($billingPeriod): void {
                    TurnoverBalanceValues::joinAccrual($join, $billingPeriod);
                })
                ->addSelect(TurnoverBalanceValues::closedPeriodColumns())
                ->selectRaw('case when accruals.id is null then 0 else 1 end as '.self::HAS_BALANCE);
        } else {
            $values
                ->addSelect(Arr::except(
                    TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod),
                    [TurnoverBalanceValues::VOLUME],
                ))
                ->selectRaw('1 as '.self::HAS_BALANCE);
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
        $debt = self::debtExpression();

        return [
            $this->accountNumberField(),
            $this->clientNameField(),
            $this->addressField(null),
            ReportField::column(
                'client_type',
                'Тип абонента',
                ReportFieldType::Text,
                'clients.client_type',
                formatUsing: fn (mixed $clientType): string => $this->clientTypeLabel((string) $clientType),
            ),
            ReportField::column(
                'status',
                'Статус',
                ReportFieldType::Text,
                'clients.status',
                formatUsing: fn (mixed $status): string => $this->clientStatusLabel((string) $status),
            ),
            ReportField::column('utility_service', 'Услуги', ReportFieldType::Text, 'clients.utility_service_name'),
            ReportField::column('residents_count', 'Проживающих', ReportFieldType::Int, 'clients.residents_count'),
            $this->controllerField(null),
            ReportField::column('debt', 'Долг', ReportFieldType::Money, $debt),
            ReportField::computed(
                'debt_per_resident',
                'Долг на проживающего',
                ReportFieldType::Money,
                "{$debt} / nullif(clients.residents_count, 0)",
            ),
        ];
    }

    public function defaultFieldKeys(): array
    {
        return ['account_number', 'client_name', 'address', 'client_type', 'residents_count', 'debt'];
    }

    public function dimensions(): array
    {
        return [
            ...$this->addressDimensions(),
            ReportDimension::column('client_type', 'По типам абонентов', 'Тип абонента', 'client_type', self::clientTypeLabels()),
            ReportDimension::column('status', 'По статусам', 'Статус', 'status', self::STATUS_LABELS),
        ];
    }

    /**
     * A row is a client, so the number of rows is «Абонентов» and there is no separate
     * row count. The average debt is the debt of the group divided by its clients.
     */
    public function metrics(): array
    {
        return [
            ReportMetric::clients(),
            ReportMetric::sum('Долг', 'debt'),
            ReportMetric::average('Средний долг', 'debt'),
        ];
    }

    public function summaryColumns(): array
    {
        return [
            'client_id' => 'clients.id',
            'region_id' => 'clients.region_id',
            'street_id' => 'clients.street_id',
            'client_type' => 'clients.client_type',
            'status' => 'clients.status',
            'debt' => self::debtExpression(),
        ];
    }

    public function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization, null),
            DateRangeFilter::make('created_at', 'Дата создания', 'clients.created_at'),
            SelectFilter::make('client_status')
                ->label('Статус абонента')
                ->placeholder('Все статусы')
                ->options(self::STATUS_LABELS)
                ->query(function (Builder $query, array $data): Builder {
                    $status = $data['value'] ?? null;

                    return is_string($status) && array_key_exists($status, self::STATUS_LABELS)
                        ? $query->where('clients.status', $status)
                        : $query;
                }),
        ];
    }

    /**
     * The debt of today, like the payment desk and «Отчёт по долгам»: the current open
     * or failed month, otherwise the last month of the organization.
     */
    public function defaultBillingPeriodFor(Organization $organization): ?BillingPeriod
    {
        return BillingPeriod::currentEditableFor($organization)
            ?? BillingPeriod::query()
                ->forOrganization($organization)
                ->orderByDesc('starts_on')
                ->first();
    }

    /**
     * Positive part of the closing balance of the billing period, `null` when the client
     * has no balance in it.
     */
    private static function debtExpression(): string
    {
        $closingDebit = TurnoverBalanceValues::metricExpressions('clients.')['closing_debit'];

        return '(case when clients.'.self::HAS_BALANCE." = 1 then {$closingDebit} end)";
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
