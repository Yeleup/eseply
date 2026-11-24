<?php

namespace App\Kaspi;

use App\BillingPeriodStatus;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\KaspiOperation;
use App\Models\Payment;
use App\PaymentMethod;
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
 * No statement waits for a lock; a try that meets one is rolled back and
 * repeated while the deadline allows (KaspiDeadline). Posting an operation
 * already posted does nothing.
 */
final class PostKaspiOperation
{
    /**
     * @param  bool  $answeringKaspi  The answer goes to Kaspi right after the commit, so it becomes the last code sent.
     *
     * @throws CannotPostKaspiOperation when the month or the locks do not let the payment in within the deadline.
     */
    public function handle(KaspiOperation|int $operation, KaspiDeadline $deadline, bool $answeringKaspi = false): KaspiOperation
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;

        return $deadline->retryWithoutWaiting(
            fn (): KaspiOperation => $this->attempt($operationId, $deadline, $answeringKaspi),
        );
    }

    /**
     * One try of the posting. No statement waits for a lock: a held lock fails
     * the try and the deadline decides whether there is another one.
     */
    private function attempt(int $operationId, KaspiDeadline $deadline, bool $answeringKaspi): KaspiOperation
    {
        $stored = KaspiOperation::query()->find($operationId)
            ?? throw new RuntimeException("Операция Kaspi {$operationId} не найдена.");

        if ($stored->state === KaspiOperationState::Posted) {
            return $stored;
        }

        $organizationId = (int) $stored->organization_id;

        // The month is looked up before the transaction: inside it, the month row
        // is read only with a locking read, before any plain read takes a snapshot.
        // MariaDB's snapshot isolation would refuse a locking read of a row that
        // closing changed after that snapshot; then the next try starts afresh.
        $billingPeriod = BillingPeriod::currentEditableFor($organizationId)
            ?? throw $this->noEditableMonth($organizationId);

        $monthLock = null;

        try {
            return DB::transaction(function () use ($operationId, $billingPeriod, $deadline, $answeringKaspi, &$monthLock): KaspiOperation {
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

                Client::query()->whereKey($operation->client_id)->lockForUpdate()->firstOrFail();

                $monthLock = BillingPeriodOperationLock::waitFor((int) $operation->organization_id, $billingPeriod, $deadline)
                    ?? throw CannotPostKaspiOperation::monthLockWaitExceeded();

                // The status is read again under the lock, with a locking read: a plain
                // read would see the snapshot taken before closing may have started.
                if (! BillingPeriod::query()->whereKey($billingPeriod->getKey())->sharedLock()->firstOrFail()->isEditable()) {
                    throw CannotPostKaspiOperation::monthClosing();
                }

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
                    'posting_attempted_at' => now(),
                    'response' => KaspiResponse::payPosted($operation->txn_id, (string) $payment->getKey(), (string) $operation->sum),
                    ...($answeringKaspi ? ['last_result' => 0] : []),
                ])->save();

                return $operation;
            }, attempts: 1);
        } finally {
            // Released only after the commit: closing must see the payment once it
            // may start. A failure to release must not turn a committed payment
            // into an error: the lock expires on its own.
            $this->releaseQuietly($monthLock);
        }
    }

    /**
     * Keep why an operation is still in processing and when it was tried, for
     * the journal and for the turn of the pickup. A posting the deadline did
     * not let start keeps its time of the last try: it was not tried.
     *
     * Bookkeeping only: it does not wait for the row, and a failure leaves the
     * previous values.
     */
    public function rememberAttempt(KaspiOperation|int $operation, string $reason, KaspiDeadline $deadline, bool $attempted = true): void
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;

        try {
            $deadline->withoutWaiting();

            KaspiOperation::query()
                ->whereKey($operationId)
                ->where('state', KaspiOperationState::Pending->value)
                ->update([
                    'state_reason' => $reason,
                    ...($attempted ? ['posting_attempted_at' => now()] : []),
                    'updated_at' => now(),
                ]);
        } catch (QueryException $exception) {
            Log::info('Kaspi: причина «в обработке» не записана', [
                'kaspi_operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);
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

    private function noEditableMonth(int $organizationId): CannotPostKaspiOperation
    {
        $isClosing = BillingPeriod::query()
            ->forOrganization($organizationId)
            ->where('status', BillingPeriodStatus::Processing->value)
            ->exists();

        return $isClosing ? CannotPostKaspiOperation::monthClosing() : CannotPostKaspiOperation::noOpenMonth();
    }
}
