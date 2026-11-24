<?php

namespace App\Kaspi;

use App\Models\BillingPeriod;
use App\Models\KaspiOperation;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts the Kaspi payments still in processing: after a closing of the month
 * ends in any branch, and every minute from the scheduler in case a job was lost.
 *
 * Operations are read in batches by id. A locked subscriber holds only its
 * own operation; a reason of the whole month — closing, no open month, the
 * month lock taken — stops the organization until the next round.
 */
final class PostPendingKaspiOperations
{
    public function __construct(private readonly PostKaspiOperation $posting) {}

    /**
     * Operations in processing of one organization.
     *
     * @param  int|null  $receivedBeforeSeconds  Only operations received at least this long ago.
     * @param  int|null  $limit  At most this many operations in one call: one round of the scheduler for one organization.
     * @return int Number of posted operations.
     */
    public function forOrganization(int $organizationId, ?int $receivedBeforeSeconds = null, ?int $limit = null): int
    {
        $batchSize = max(1, (int) config('kaspi.posting.batch_size', 100));
        $receivedBefore = $receivedBeforeSeconds === null ? null : now()->subSeconds($receivedBeforeSeconds);
        $posted = 0;
        $seen = 0;
        $lastId = 0;

        while ($limit === null || $seen < $limit) {
            $operationIds = KaspiOperation::query()
                ->where('organization_id', $organizationId)
                ->where('state', KaspiOperationState::Pending->value)
                ->where('id', '>', $lastId)
                ->when($receivedBefore !== null, fn ($query) => $query->where('received_at', '<=', $receivedBefore))
                ->orderBy('id')
                ->limit($limit === null ? $batchSize : min($batchSize, $limit - $seen))
                ->pluck('id');

            if ($operationIds->isEmpty()) {
                break;
            }

            foreach ($operationIds as $operationId) {
                $lastId = (int) $operationId;
                $seen++;

                $outcome = $this->postOne((int) $operationId);

                if ($outcome === true) {
                    $posted++;
                } elseif ($outcome === 'month') {
                    return $posted;
                }
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
        $perOrganization = max(1, (int) config('kaspi.posting.pickup_per_organization', 200));

        $organizationIds = KaspiOperation::query()
            ->where('state', KaspiOperationState::Pending->value)
            ->where('received_at', '<=', now()->subSeconds($pickupAfter))
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id');

        $posted = 0;

        foreach ($organizationIds as $organizationId) {
            $this->openNextMonthAfterClosed((int) $organizationId);

            $posted += $this->forOrganization((int) $organizationId, $pickupAfter, $perOrganization);
        }

        return $posted;
    }

    /**
     * @return true|'month'|'row' Posted; held by the whole month; held by its own row or an error.
     */
    private function postOne(int $operationId): bool|string
    {
        $deadline = KaspiDeadline::in((float) config('kaspi.posting.job_lock_wait_seconds', 30));

        try {
            $this->posting->handle($operationId, $deadline);

            return true;
        } catch (CannotPostKaspiOperation $exception) {
            $this->posting->rememberReason($operationId, $exception->getMessage(), $deadline);

            return $exception->isMonthWide() ? 'month' : 'row';
        } catch (KaspiSumOutOfRange) {
            return 'row';
        } catch (Throwable $exception) {
            report($exception);

            return 'row';
        } finally {
            $deadline->restore();
        }
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
