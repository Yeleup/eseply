<?php

namespace App\Kaspi;

use App\BillingPeriodStatus;
use App\Models\Accrual;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Receipt;
use App\Reports\TurnoverBalanceValues;
use App\Support\Money;

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

        $balanceCents = $billingPeriod instanceof BillingPeriod
            ? self::openPeriodBalanceCents($organization, $billingPeriod, $client)
            : Money::cents(self::lastClosedBalance($client));

        return Money::format(max($balanceCents, 0));
    }

    /**
     * The closing balance as the turnover sheet counts it, added exactly.
     */
    private static function openPeriodBalanceCents(Organization $organization, BillingPeriod $billingPeriod, Client $client): int
    {
        $row = Client::query()
            ->whereKey($client->getKey())
            ->select('clients.id')
            ->addSelect(TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod))
            ->first();

        if (! $row instanceof Client) {
            return 0;
        }

        return Money::cents(Receipt::closingBalance(
            Money::sum(
                $row->getAttribute(TurnoverBalanceValues::OPENING_BALANCE),
                $row->getAttribute(TurnoverBalanceValues::OPENING_ADJUSTMENT),
            ),
            $row->getAttribute(TurnoverBalanceValues::ACCRUED_AMOUNT),
            $row->getAttribute(TurnoverBalanceValues::PAID_AMOUNT),
            $row->getAttribute(TurnoverBalanceValues::ADJUSTMENT_AMOUNT),
        ));
    }

    /**
     * Only when no month is open or closing: the balance the last accrual left.
     */
    private static function lastClosedBalance(Client $client): ?string
    {
        $closingBalance = Accrual::query()
            ->where('accruals.client_id', $client->getKey())
            ->join('billing_periods', 'billing_periods.id', '=', 'accruals.billing_period_id')
            ->orderByDesc('billing_periods.starts_on')
            ->value('accruals.closing_balance');

        return $closingBalance === null ? null : (string) $closingBalance;
    }
}
