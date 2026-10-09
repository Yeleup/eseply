<?php

namespace App\Reports\Builder;

use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Filament\Support\DateRangeFilter;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
use App\Reports\Builder\Concerns\DescribesClientRows;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row is one payment of a client in the billing period of the report.
 */
final class PaymentsSource implements ReportSource
{
    use DescribesClientRows;

    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Оплаты';
    }

    public function hint(): string
    {
        return 'Строка — одна оплата абонента.';
    }

    /**
     * The client is joined rather than only checked, so the client columns sort, search
     * and group the payments without a query per row.
     */
    public function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Payment::query()
            ->select('payments.*')
            ->join('clients', 'clients.id', '=', 'payments.client_id')
            ->leftJoin('users as payment_receivers', 'payment_receivers.id', '=', 'payments.received_by_user_id')
            ->where('payments.organization_id', $organization->getKey())
            ->whereHas(
                'client',
                fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organization),
            );

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('payments.billing_period_id', $billingPeriod->getKey());
    }

    public function orderRows(Builder $query): Builder
    {
        return $query
            ->orderBy('payments.paid_at')
            ->orderBy('payments.id');
    }

    public function fields(): array
    {
        return [
            $this->accountNumberField(),
            $this->clientNameField(),
            $this->addressField('client'),
            $this->billingPeriodField(),
            ReportField::column('paid_at', 'Дата оплаты', ReportFieldType::Date, 'payments.paid_at'),
            ReportField::column(
                'method',
                'Способ оплаты',
                ReportFieldType::Text,
                'payments.method',
                formatUsing: fn (mixed $method): string => PaymentMethod::labelFor((string) $method) ?? (string) $method,
            ),
            ReportField::column('amount', 'Сумма', ReportFieldType::Money, 'payments.amount'),
            ReportField::column('received_by', 'Принял', ReportFieldType::Text, 'payment_receivers.name'),
        ];
    }

    public function defaultFieldKeys(): array
    {
        return ['account_number', 'client_name', 'address', 'paid_at', 'method', 'amount'];
    }

    public function dimensions(): array
    {
        return [
            ...$this->addressDimensions(),
            ReportDimension::column('method', 'По способам оплаты', 'Способ оплаты', 'method', self::methodLabels()),
        ];
    }

    public function metrics(): array
    {
        return [
            ReportMetric::clients(),
            ReportMetric::count('Оплат'),
            ReportMetric::sum('Сумма оплат', 'amount'),
            ReportMetric::average('Средняя оплата', 'amount'),
        ];
    }

    public function summaryColumns(): array
    {
        return [
            'client_id' => 'clients.id',
            'region_id' => 'clients.region_id',
            'street_id' => 'clients.street_id',
            'method' => 'payments.method',
            'amount' => 'payments.amount',
        ];
    }

    public function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization),
            DateRangeFilter::make('paid_at', 'Дата оплаты', 'payments.paid_at'),
            SelectFilter::make('method')
                ->label('Способ оплаты')
                ->placeholder('Все способы')
                ->options(self::methodLabels())
                ->query(function (Builder $query, array $data): Builder {
                    $method = is_string($data['value'] ?? null) ? PaymentMethod::tryFrom($data['value']) : null;

                    return $method instanceof PaymentMethod
                        ? $query->where('payments.method', $method->value)
                        : $query;
                }),
        ];
    }

    /**
     * Payments are taken while a month is open, so the current one is shown first.
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
     * @return array<string, string>
     */
    private static function methodLabels(): array
    {
        $labels = [];

        foreach (PaymentMethod::cases() as $method) {
            $labels[$method->value] = (string) $method->getLabel();
        }

        return $labels;
    }
}
