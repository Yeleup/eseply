<?php

namespace App\Kaspi;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Payment;
use App\PaymentMethod;
use App\Reports\TurnoverBalanceValues;
use App\Support\BillingPeriodOperationLock;
use Illuminate\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Posting of one accepted Kaspi `pay`: one database transaction, idempotent.
 *
 * 1. Lock the operation row and the subscriber row, before any other read,
 *    so a payment at the desk for the same subscriber waits and the receipt
 *    never loses a sum.
 * 2. Check the billing month under the lock the start of closing takes, and
 *    read its status again with a locking read.
 * 3. Create the `Payment` from the saved operation, never from a repeated
 *    request, in the current editable month.
 * 4. The receipt is recalculated by the payment itself.
 * 5. Mark the operation posted with its ready answer.
 *
 * Every lock wait shares the deadline. Posting an operation already posted
 * does nothing.
 */
final class PostKaspiOperation
{
    /**
     * Largest amount a money column (`DECIMAL(14,2)`) holds, in tiyn.
     */
    private const int MONEY_LIMIT_CENTS = 99_999_999_999_999;

    /**
     * @param  bool  $answeringKaspi  The answer goes to Kaspi right after the commit, so it becomes the last code sent.
     *
     * @throws CannotPostKaspiOperation when the month or the locks do not let the payment in now.
     * @throws KaspiSumOutOfRange when the payment does not fit the subscriber's totals; the operation is marked as an error.
     */
    public function handle(KaspiOperation|int $operation, KaspiDeadline $deadline, bool $answeringKaspi = false): KaspiOperation
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;
        $stored = KaspiOperation::query()->find($operationId)
            ?? throw new RuntimeException("Операция Kaspi {$operationId} не найдена.");

        if ($stored->state === KaspiOperationState::Posted) {
            return $stored;
        }

        $organizationId = (int) $stored->organization_id;
        $monthLock = null;

        // The month is looked up before the transaction: inside it, the month row
        // is read only with a locking read, before any plain read takes a snapshot.
        // MariaDB's snapshot isolation would refuse a locking read of a row that
        // closing changed after that snapshot.
        $billingPeriod = BillingPeriod::currentEditableFor($organizationId)
            ?? throw $this->noEditableMonth($organizationId);

        try {
            return DB::transaction(function () use ($operationId, $billingPeriod, $deadline, $answeringKaspi, &$monthLock): KaspiOperation {
                $deadline->limitRowLockWait();

                $operation = KaspiOperation::query()->whereKey($operationId)->lockForUpdate()->first();

                if (! $operation instanceof KaspiOperation) {
                    throw new RuntimeException("Операция Kaspi {$operationId} не найдена.");
                }

                if ($operation->state === KaspiOperationState::Posted) {
                    return $operation;
                }

                if ($operation->state !== KaspiOperationState::Pending || ! $operation->isPay()) {
                    throw new RuntimeException("Операцию Kaspi {$operationId} провести нельзя: она не принятая оплата.");
                }

                $deadline->limitRowLockWait();

                Client::query()->whereKey($operation->client_id)->lockForUpdate()->firstOrFail();

                $monthLock = BillingPeriodOperationLock::waitFor((int) $operation->organization_id, $billingPeriod, $deadline->remaining())
                    ?? throw CannotPostKaspiOperation::monthLockWaitExceeded();

                // What is left after the wait for the month lock bounds the rest.
                $deadline->limitRowLockWait();

                // The status is read again under the lock, with a locking read: a plain
                // read would see the snapshot taken before closing may have started.
                if (! BillingPeriod::query()->whereKey($billingPeriod->getKey())->sharedLock()->firstOrFail()->isEditable()) {
                    throw CannotPostKaspiOperation::monthClosing();
                }

                $this->ensureFitsTotals($operation, $billingPeriod);

                $payment = Payment::query()->create([
                    'organization_id' => $operation->organization_id,
                    'client_id' => $operation->client_id,
                    'billing_period_id' => $billingPeriod->getKey(),
                    'method' => PaymentMethod::Kaspi->value,
                    'external_provider' => Payment::KASPI_PROVIDER,
                    'external_payment_id' => $operation->txn_id,
                    'received_by_user_id' => null,
                    'amount' => $operation->sum,
                    'paid_at' => $operation->txn_date?->toDateString(),
                ]);

                $operation->forceFill([
                    'state' => KaspiOperationState::Posted,
                    'state_reason' => null,
                    'payment_id' => $payment->getKey(),
                    'posted_at' => now(),
                    'response' => KaspiResponse::payPosted($operation->txn_id, (string) $payment->getKey(), (string) $operation->sum),
                    ...($answeringKaspi ? ['last_result' => 0] : []),
                ])->save();

                return $operation;
            }, attempts: 1);
        } catch (QueryException $exception) {
            if (KaspiDeadline::isLockTimeout($exception)) {
                throw CannotPostKaspiOperation::lockWaitExceeded();
            }

            if (KaspiDeadline::isSnapshotConflict($exception)) {
                $closing = $this->noEditableMonth($organizationId);

                throw $closing->getMessage() === CannotPostKaspiOperation::monthClosing()->getMessage()
                    ? $closing
                    : CannotPostKaspiOperation::changedMeanwhile();
            }

            throw $exception;
        } catch (KaspiSumOutOfRange $exception) {
            $this->markOutOfRange($operationId, $exception, $deadline, $answeringKaspi);

            throw $exception;
        } finally {
            // Released only after the commit: closing must see the payment once it
            // may start. A failure to release must not turn a committed payment
            // into an error: the lock expires on its own.
            $this->releaseQuietly($monthLock);
        }
    }

    /**
     * Keep the reason an operation is still in processing, for the journal.
     *
     * Bookkeeping only: it waits for the row a second at most, never past the
     * deadline, and a failure leaves the previous reason.
     */
    public function rememberReason(KaspiOperation|int $operation, string $reason, KaspiDeadline $deadline): void
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;

        try {
            $deadline->limitBookkeepingWait();

            KaspiOperation::query()
                ->whereKey($operationId)
                ->where('state', KaspiOperationState::Pending->value)
                ->update([
                    'state_reason' => $reason,
                    'updated_at' => now(),
                ]);
        } catch (QueryException $exception) {
            Log::info('Kaspi: причина «в обработке» не записана', [
                'kaspi_operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The sum of the month's payments and the balance after this payment must
     * fit the money columns of the receipt and of the accrual; otherwise the
     * receipt would roll the posting back again and again, or closing would
     * fail on the accrual.
     */
    private function ensureFitsTotals(KaspiOperation $operation, BillingPeriod $billingPeriod): void
    {
        $sum = self::cents($operation->sum);

        // A locking read: the latest committed payments, not the snapshot.
        $paid = self::cents(Payment::query()
            ->where('organization_id', $operation->organization_id)
            ->where('client_id', $operation->client_id)
            ->where('billing_period_id', $billingPeriod->getKey())
            ->sharedLock()
            ->sum('amount'));

        if ($paid + $sum > self::MONEY_LIMIT_CENTS) {
            throw new KaspiSumOutOfRange;
        }

        $row = Client::query()
            ->whereKey($operation->client_id)
            ->select('clients.id')
            ->addSelect(TurnoverBalanceValues::openPeriodSubQueries($billingPeriod->organization, $billingPeriod))
            ->first();

        $closing = $row instanceof Client
            ? self::cents($row->getAttribute(TurnoverBalanceValues::OPENING_BALANCE))
                + self::cents($row->getAttribute(TurnoverBalanceValues::OPENING_ADJUSTMENT))
                + self::cents($row->getAttribute(TurnoverBalanceValues::ACCRUED_AMOUNT))
                - $paid
                + self::cents($row->getAttribute(TurnoverBalanceValues::ADJUSTMENT_AMOUNT))
            : -$paid;

        if ($closing - $sum < -self::MONEY_LIMIT_CENTS) {
            throw new KaspiSumOutOfRange;
        }
    }

    /**
     * An operation that never fits is an error, not «в обработке»: nothing
     * picks it up again, the journal shows why, and Kaspi gets 5.
     */
    private function markOutOfRange(int $operationId, KaspiSumOutOfRange $exception, KaspiDeadline $deadline, bool $answeringKaspi): void
    {
        try {
            $deadline->limitBookkeepingWait();

            $operation = KaspiOperation::query()->find($operationId);

            if (! $operation instanceof KaspiOperation || $operation->state !== KaspiOperationState::Pending) {
                return;
            }

            $operation->forceFill([
                'state' => KaspiOperationState::Error,
                'state_reason' => $exception->getMessage(),
                'response' => KaspiResponse::error($operation->txn_id),
                ...($answeringKaspi ? ['last_result' => 5] : []),
            ])->save();
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    private function releaseQuietly(?Lock $lock): void
    {
        if (! $lock instanceof Lock) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable $exception) {
            Log::warning('Kaspi: блокировка расчётного месяца не освобождена, истечёт сама', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private static function cents(mixed $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    private function noEditableMonth(int $organizationId): CannotPostKaspiOperation
    {
        $isClosing = BillingPeriod::query()
            ->forOrganization($organizationId)
            ->where('status', BillingPeriodStatus::Processing->value)
            ->exists();

        return $isClosing ? CannotPostKaspiOperation::monthClosing() : CannotPostKaspiOperation::noOpenMonth();
    }
}
