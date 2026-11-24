<?php

namespace App\Kaspi;

use App\Models\BillingPeriod;
use App\Models\KaspiOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts the Kaspi payments still in processing: after a closing of the month
 * ends in any branch, and every minute from the scheduler in case a job was lost.
 */
final class PostPendingKaspiOperations
{
    public function __construct(private readonly PostKaspiOperation $posting) {}

    /**
     * Every operation in processing of one organization.
     *
     * @return int Number of posted operations.
     */
    public function forOrganization(int $organizationId, ?int $receivedBeforeSeconds = null): int
    {
        $posted = 0;

        $operationIds = KaspiOperation::query()
            ->where('organization_id', $organizationId)
            ->where('state', KaspiOperationState::Pending->value)
            ->when($receivedBeforeSeconds !== null, fn (Builder $query): Builder => $query
                ->where('received_at', '<=', now()->subSeconds($receivedBeforeSeconds)))
            ->orderBy('id')
            ->pluck('id');

        foreach ($operationIds as $operationId) {
            $deadline = KaspiDeadline::in((float) config('kaspi.posting.job_lock_wait_seconds', 30));

            try {
                $this->posting->handle((int) $operationId, $deadline);
                $posted++;
            } catch (CannotPostKaspiOperation $exception) {
                $this->posting->rememberReason((int) $operationId, $exception->getMessage());

                // The month is closing, missing or locked for the whole organization:
                // the next operations would wait for the same thing, so they wait for
                // the next round instead.
                break;
            } catch (Throwable $exception) {
                report($exception);
            } finally {
                $deadline->restore();
            }
        }

        return $posted;
    }

    /**
     * The scheduler's pickup: organizations with operations in processing
     * older than the pickup age. An organization whose latest month is closed
     * with nothing after it gets the next month first, as at deployment.
     *
     * @return int Number of posted operations.
     */
    public function pickUp(): int
    {
        $pickupAfter = (int) config('kaspi.posting.pickup_after', 60);

        $organizationIds = KaspiOperation::query()
            ->where('state', KaspiOperationState::Pending->value)
            ->where('received_at', '<=', now()->subSeconds($pickupAfter))
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id');

        $posted = 0;

        foreach ($organizationIds as $organizationId) {
            $this->openNextMonthAfterClosed((int) $organizationId);

            $posted += $this->forOrganization((int) $organizationId, $pickupAfter);
        }

        return $posted;
    }

    private function openNextMonthAfterClosed(int $organizationId): void
    {
        if (! BillingPeriod::latestIsClosedFor($organizationId)) {
            return;
        }

        try {
            BillingPeriod::openNextFor($organizationId);
        } catch (ValidationException $exception) {
            // Another process opened or changed the month meanwhile.
            Log::info('Kaspi: следующий расчётный месяц не открыт', [
                'organization_id' => $organizationId,
                'reason' => $exception->validator->errors()->first(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
