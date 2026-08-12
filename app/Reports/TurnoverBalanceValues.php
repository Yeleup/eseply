<?php

namespace App\Reports;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Opening balance, accrual, payment and adjustment of one client for one billing period.
 *
 * A closed billing period is fixed, so the four values come from the stored accrual.
 * Any other billing period is still being collected, so they come from the closing
 * balance of the previous accrual, the receipt, the payments and the balance
 * adjustments of the period.
 *
 * Both consumers select the same four aliases from the `clients` table, so the
 * detail report and the summary service always describe the same rows.
 */
final class TurnoverBalanceValues
{
    public const string OPENING_BALANCE = 'opening_balance_value';

    public const string ACCRUED_AMOUNT = 'accrued_amount_value';

    public const string PAID_AMOUNT = 'paid_amount_value';

    public const string ADJUSTMENT_AMOUNT = 'adjustment_amount_value';

    public static function isClosed(?BillingPeriod $billingPeriod): bool
    {
        return $billingPeriod?->status === BillingPeriodStatus::Closed;
    }

    /**
     * Join condition selecting the accrual of the closed billing period.
     */
    public static function joinAccrual(JoinClause $join, BillingPeriod $billingPeriod): void
    {
        $join
            ->on('accruals.client_id', '=', 'clients.id')
            ->where('accruals.billing_period_id', '=', $billingPeriod->getKey());
    }

    /**
     * @return list<string>
     */
    public static function closedPeriodColumns(): array
    {
        return [
            'accruals.opening_balance as '.self::OPENING_BALANCE,
            'accruals.amount as '.self::ACCRUED_AMOUNT,
            'accruals.paid_amount as '.self::PAID_AMOUNT,
            'accruals.adjustment_amount as '.self::ADJUSTMENT_AMOUNT,
        ];
    }

    /**
     * @return array<string, QueryBuilder>
     */
    public static function openPeriodSubQueries(Organization $organization, BillingPeriod $billingPeriod): array
    {
        return [
            self::OPENING_BALANCE => self::previousClosingBalanceSubQuery($organization, $billingPeriod),
            self::ACCRUED_AMOUNT => self::receiptAmountSubQuery($billingPeriod),
            self::PAID_AMOUNT => self::paidAmountSubQuery($billingPeriod),
            self::ADJUSTMENT_AMOUNT => self::adjustmentAmountSubQuery($billingPeriod),
        ];
    }

    /**
     * SQL expressions of every reported value, keyed by metric.
     *
     * The expressions read the four aliases, so they only work on a query that
     * selects them, such as a derived table built from the base rows query.
     *
     * @param  string  $prefix  Qualifier of the four aliases, for example `report_rows.`.
     * @return array<string, string>
     */
    public static function metricExpressions(string $prefix = ''): array
    {
        $opening = 'coalesce('.$prefix.self::OPENING_BALANCE.', 0)';
        $accrued = 'coalesce('.$prefix.self::ACCRUED_AMOUNT.', 0)';
        $paid = 'coalesce('.$prefix.self::PAID_AMOUNT.', 0)';
        $adjustment = 'coalesce('.$prefix.self::ADJUSTMENT_AMOUNT.', 0)';
        $closing = "({$opening} + {$accrued} - {$paid} + {$adjustment})";

        return [
            'opening_debit' => "greatest({$opening}, 0)",
            'opening_credit' => "greatest(-{$opening}, 0)",
            'turnover_debit' => "({$accrued} + greatest({$adjustment}, 0))",
            'turnover_credit' => "({$paid} + greatest(-{$adjustment}, 0))",
            'closing_debit' => "greatest({$closing}, 0)",
            'closing_credit' => "greatest(-{$closing}, 0)",
            'accrued_amount' => $accrued,
            'adjustment_amount' => $adjustment,
            'paid_amount' => $paid,
        ];
    }

    /**
     * Reported values of one row, keyed the same way as the SQL expressions.
     *
     * @return array<string, float>
     */
    public static function metrics(float $opening, float $accrued, float $paid, float $adjustment): array
    {
        $closing = $opening + $accrued - $paid + $adjustment;

        return [
            'opening_debit' => max($opening, 0),
            'opening_credit' => max(-$opening, 0),
            'turnover_debit' => $accrued + max($adjustment, 0),
            'turnover_credit' => $paid + max(-$adjustment, 0),
            'closing_debit' => max($closing, 0),
            'closing_credit' => max(-$closing, 0),
            'accrued_amount' => $accrued,
            'adjustment_amount' => $adjustment,
            'paid_amount' => $paid,
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function metricsOf(Model $record): array
    {
        return self::metrics(
            (float) ($record->getAttribute(self::OPENING_BALANCE) ?? 0),
            (float) ($record->getAttribute(self::ACCRUED_AMOUNT) ?? 0),
            (float) ($record->getAttribute(self::PAID_AMOUNT) ?? 0),
            (float) ($record->getAttribute(self::ADJUSTMENT_AMOUNT) ?? 0),
        );
    }

    private static function previousClosingBalanceSubQuery(
        Organization $organization,
        BillingPeriod $billingPeriod,
    ): QueryBuilder {
        return DB::table('accruals')
            ->join('billing_periods', 'billing_periods.id', '=', 'accruals.billing_period_id')
            ->select('accruals.closing_balance')
            ->whereColumn('accruals.client_id', 'clients.id')
            ->where('billing_periods.organization_id', $organization->getKey())
            ->where('billing_periods.starts_on', '<', BillingPeriod::periodStart($billingPeriod->starts_on)->toDateString())
            ->orderByDesc('billing_periods.starts_on')
            ->limit(1);
    }

    private static function receiptAmountSubQuery(BillingPeriod $billingPeriod): QueryBuilder
    {
        return DB::table('receipts')
            ->select('receipts.amount')
            ->whereColumn('receipts.client_id', 'clients.id')
            ->where('receipts.billing_period_id', $billingPeriod->getKey())
            ->limit(1);
    }

    private static function paidAmountSubQuery(BillingPeriod $billingPeriod): QueryBuilder
    {
        return DB::table('payments')
            ->selectRaw('coalesce(sum(payments.amount), 0)')
            ->whereColumn('payments.client_id', 'clients.id')
            ->where('payments.billing_period_id', $billingPeriod->getKey());
    }

    private static function adjustmentAmountSubQuery(BillingPeriod $billingPeriod): QueryBuilder
    {
        return DB::table('balance_adjustments')
            ->selectRaw('coalesce(sum(balance_adjustments.amount), 0)')
            ->whereColumn('balance_adjustments.client_id', 'clients.id')
            ->where('balance_adjustments.billing_period_id', $billingPeriod->getKey());
    }
}
