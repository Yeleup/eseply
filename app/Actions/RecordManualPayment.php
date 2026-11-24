<?php

namespace App\Actions;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Payment;
use App\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The locks under which a payment is entered by hand — at the desk, in the
 * subscriber card or in «Оплаты» — always cash, in the current editable month.
 *
 * The subscriber row is locked first, before any other read, as the Kaspi
 * posting does: two payments of one subscriber at the same moment then
 * recalculate the receipt one after the other and no sum is lost.
 *
 * Then the current month is found with a locking read. The start of closing
 * locks that row to move the month to `processing`, so the two never cross: a
 * payment that took the row first is committed before closing reads the
 * payments, and a payment that comes second sees the month closing and is
 * refused, never written into a month already being calculated.
 */
final class RecordManualPayment
{
    /**
     * Must run inside the transaction that saves the payment.
     *
     * Both reads are locking reads. The month is found with one as well, so a
     * plain read never takes the snapshot of the transaction before it: under
     * MariaDB's snapshot isolation a locking read of a month that closing
     * changed after that snapshot would fail instead of showing the change.
     */
    public function lockClientAndMonth(Organization $organization, int $clientId): BillingPeriod
    {
        try {
            Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($clientId)
                ->lockForUpdate()
                ->firstOrFail();

            $billingPeriod = BillingPeriod::query()
                ->forOrganization($organization)
                ->whereIn('status', [BillingPeriodStatus::Open->value, BillingPeriodStatus::Failed->value])
                ->orderByDesc('starts_on')
                ->sharedLock()
                ->first();
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1020) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'billing_period_id' => 'Расчётный месяц изменился, пока оплата сохранялась. Обновите страницу и повторите.',
            ]);
        }

        if ($billingPeriod instanceof BillingPeriod) {
            return $billingPeriod;
        }

        $isClosing = BillingPeriod::query()
            ->forOrganization($organization)
            ->where('status', BillingPeriodStatus::Processing->value)
            ->exists();

        throw ValidationException::withMessages([
            'billing_period_id' => $isClosing
                ? 'Расчётный месяц закрывается: оплату можно будет внести после закрытия, в следующий месяц.'
                : 'Нет открытого расчётного месяца. Откройте месяц в разделе «Расчётные месяцы».',
        ]);
    }

    /**
     * The payment desk's own save.
     *
     * @param  array{amount: mixed, paid_at?: mixed, note?: mixed}  $data
     */
    public function handle(Organization $organization, int $clientId, array $data, ?int $receivedByUserId): Payment
    {
        return DB::transaction(function () use ($organization, $clientId, $data, $receivedByUserId): Payment {
            $billingPeriod = $this->lockClientAndMonth($organization, $clientId);

            return Payment::query()->create([
                'organization_id' => $organization->getKey(),
                'client_id' => $clientId,
                'billing_period_id' => $billingPeriod->getKey(),
                'method' => PaymentMethod::Cash->value,
                'received_by_user_id' => $receivedByUserId,
                'amount' => $data['amount'],
                'paid_at' => $data['paid_at'] ?? today(),
                'note' => $data['note'] ?? null,
            ]);
        }, attempts: 3);
    }

    /**
     * Data of a payment entered by hand, whatever the browser sent: cash,
     * without the marks of an integration.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function manualData(array $data, int $organizationId, ?int $receivedByUserId): array
    {
        unset($data['external_provider'], $data['external_payment_id']);

        return [
            ...$data,
            'organization_id' => $organizationId,
            // Kaspi comes only through the integration.
            'method' => PaymentMethod::Cash->value,
            'received_by_user_id' => $receivedByUserId,
        ];
    }
}
