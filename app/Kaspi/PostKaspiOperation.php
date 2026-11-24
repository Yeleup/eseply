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
use RuntimeException;

/**
 * Posting of one accepted Kaspi `pay`: one database transaction, idempotent.
 *
 * 1. Lock the operation row and the subscriber row, before any other read,
 *    so a payment at the desk for the same subscriber waits and the receipt
 *    never loses a sum.
 * 2. Check the billing month under the lock the start of closing takes.
 * 3. Create the `Payment` from the saved operation, never from a repeated
 *    request, in the current editable month.
 * 4. The receipt is recalculated by the payment itself.
 * 5. Mark the operation posted with its ready answer.
 *
 * Posting an operation already posted does nothing.
 */
final class PostKaspiOperation
{
    /**
     * @param  bool  $answeringKaspi  The answer goes to Kaspi right after the commit, so it becomes the last code sent.
     *
     * @throws CannotPostKaspiOperation when the month or the locks do not let the payment in now.
     */
    public function handle(KaspiOperation|int $operation, KaspiDeadline $deadline, bool $answeringKaspi = false): KaspiOperation
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;
        $monthLock = null;

        try {
            return DB::transaction(function () use ($operationId, $deadline, $answeringKaspi, &$monthLock): KaspiOperation {
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

                $billingPeriod = BillingPeriod::currentEditableFor((int) $operation->organization_id)
                    ?? throw $this->noEditableMonth((int) $operation->organization_id);

                $monthLock = BillingPeriodOperationLock::waitFor((int) $operation->organization_id, $billingPeriod, $deadline->remaining())
                    ?? throw CannotPostKaspiOperation::lockWaitExceeded();

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
                    'response' => KaspiResponse::payPosted($operation->txn_id, (string) $payment->getKey(), (string) $operation->sum),
                    ...($answeringKaspi ? ['last_result' => 0] : []),
                ])->save();

                return $operation;
            }, attempts: 1);
        } catch (QueryException $exception) {
            if (KaspiDeadline::isLockTimeout($exception)) {
                throw CannotPostKaspiOperation::lockWaitExceeded();
            }

            throw $exception;
        } finally {
            // Released only after the commit: closing must see the payment once it may start.
            $monthLock instanceof Lock && $monthLock->release();
        }
    }

    /**
     * Keep the reason an operation is still in processing, for the journal.
     *
     * Best effort: the operation is saved already, so a failure here only
     * leaves the previous reason.
     */
    public function rememberReason(KaspiOperation|int $operation, string $reason, ?int $lastResult = null): void
    {
        $operationId = $operation instanceof KaspiOperation ? (int) $operation->getKey() : $operation;

        try {
            KaspiOperation::query()
                ->whereKey($operationId)
                ->where('state', KaspiOperationState::Pending->value)
                ->update([
                    'state_reason' => $reason,
                    ...($lastResult !== null ? ['last_result' => $lastResult] : []),
                    'updated_at' => now(),
                ]);
        } catch (QueryException $exception) {
            report($exception);
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
