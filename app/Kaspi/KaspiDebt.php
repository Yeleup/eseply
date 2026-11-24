<?php

namespace App\Kaspi;

use App\BillingPeriodStatus;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Reports\TurnoverBalanceValues;

/**
 * The debt Kaspi shows the payer: the full balance by the same engine as the
 * desk and the turnover sheet — previous balance, accrual, payments and
 * adjustments — for the current editable month, or for the month being closed
 * while it closes. No debt or an overpayment is `0.00`.
 */
final class KaspiDebt
{
    public static function for(Organization $organization, Client $client): string
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization)
            ?? BillingPeriod::query()
                ->forOrganization($organization)
                ->where('status', BillingPeriodStatus::Processing->value)
                ->orderByDesc('starts_on')
                ->first();

        $balance = $billingPeriod instanceof BillingPeriod
            ? self::openPeriodBalance($organization, $billingPeriod, $client)
            : self::lastClosedBalance($client);

        return number_format(max($balance, 0.0), 2, '.', '');
    }

    private static function openPeriodBalance(Organization $organization, BillingPeriod $billingPeriod, Client $client): float
    {
        $row = Client::query()
            ->whereKey($client->getKey())
            ->select('clients.id')
            ->addSelect(TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod))
            ->first();

        if (! $row instanceof Client) {
            return 0.0;
        }

        $metrics = TurnoverBalanceValues::metricsOf($row);

        return (float) $metrics['closing_debit'] - (float) $metrics['closing_credit'];
    }

    /**
     * Only when no month is open or closing: the balance the last accrual left.
     */
    private static function lastClosedBalance(Client $client): float
    {
        return (float) (Accrual::query()
            ->where('accruals.client_id', $client->getKey())
            ->join('billing_periods', 'billing_periods.id', '=', 'accruals.billing_period_id')
            ->orderByDesc('billing_periods.starts_on')
            ->value('accruals.closing_balance') ?? 0);
    }
}
