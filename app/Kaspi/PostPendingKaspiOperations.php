<?php

namespace App\Kaspi;

use App\Models\BillingPeriod;
use App\Models\KaspiOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Posts the Kaspi payments still in processing: after a closing of the month
 * ends in any branch, and every minute from the scheduler in case a job was lost.
 *
 * Fair and bounded:
 * - operations are taken in turn of their last try — never tried first, then
 *   the one tried longest ago — so operations that keep failing go to the end
 *   of the line and never hide the ones behind them;
 * - organizations are taken in a circle by their id, starting after the one
 *   the previous round of the scheduler worked on last, so an organization
 *   a round did not reach is the first in the next one;
 * - every operation, every organization and the whole round have their own
 *   time budget: a held row costs its operation a short wait, never the round;
 * - a locked subscriber holds only its own operation; a reason of the whole
 *   month — closing, no open month, the month lock taken — stops the
 *   organization until the next round.
 */
final class PostPendingKaspiOperations
{
    /**
     * The organization the scheduler's pickup worked on last.
     */
    public const string LAST_ORGANIZATION_KEY = 'kaspi:pickup:last-organization';

    public function __construct(private readonly PostKaspiOperation $posting) {}

    /**
     * Operations in processing of one organization.
     *
     * @param  int|null  $receivedBeforeSeconds  Only operations received at least this long ago.
     * @param  int|null  $limit  At most this many operations.
     * @param  KaspiDeadline|null  $budget  Stop when this runs out; the rest wait for the next round.
     * @return int Number of posted operations.
     */
    public function forOrganization(int $organizationId, ?int $receivedBeforeSeconds = null, ?int $limit = null, ?KaspiDeadline $budget = null): int
    {
        $batchSize = max(1, (int) config('kaspi.posting.batch_size', 100));
        $receivedBefore = $receivedBeforeSeconds === null ? null : now()->subSeconds($receivedBeforeSeconds);
        $posted = 0;
        $tried = [];

        while (($limit === null || count($tried) < $limit) && ($budget === null || ! $budget->expired())) {
            $operationIds = KaspiOperation::query()
                ->where('organization_id', $organizationId)
                ->where('state', KaspiOperationState::Pending->value)
                ->when($receivedBefore !== null, fn ($query) => $query->where('received_at', '<=', $receivedBefore))
                ->when($tried !== [], fn ($query) => $query->whereNotIn('id', $tried))
                // Never tried first — NULL sorts first — then the one tried longest
                // ago, along the index (organization, state, time of the last try).
                ->orderBy('posting_attempted_at')
                ->orderBy('id')
                ->limit($limit === null ? $batchSize : min($batchSize, $limit - count($tried)))
                ->pluck('id');

            if ($operationIds->isEmpty()) {
                break;
            }

            foreach ($operationIds as $operationId) {
                if ($budget?->expired()) {
                    break 2;
                }

                $tried[] = (int) $operationId;
                $outcome = $this->postOne((int) $operationId, $budget);

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
     * older than the pickup age, in a circle by organization id from the one
     * after the organization the previous round worked on last, within the
     * time budget of the round. An organization whose latest month is closed
     * with nothing after it gets the next month first, as at deployment.
     *
     * @return int Number of posted operations.
     */
    public function pickUp(): int
    {
        $pickupAfter = (int) config('kaspi.posting.pickup_after', 60);
        $perOrganization = max(1, (int) config('kaspi.posting.pickup_per_organization', 50));
        $round = KaspiDeadline::in((float) config('kaspi.posting.pickup_seconds', 40));

        $organizationIds = KaspiOperation::query()
            ->where('state', KaspiOperationState::Pending->value)
            ->where('received_at', '<=', now()->subSeconds($pickupAfter))
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn (mixed $organizationId): int => (int) $organizationId);

        $lastOrganizationId = $this->lastPickedUpOrganization();

        $inTurn = $organizationIds->filter(fn (int $organizationId): bool => $organizationId > $lastOrganizationId)
            ->concat($organizationIds->filter(fn (int $organizationId): bool => $organizationId <= $lastOrganizationId));

        $posted = 0;

        foreach ($inTurn as $organizationId) {
            if ($round->expired()) {
                break;
            }

            $this->openNextMonthAfterClosed($organizationId);

            $organizationBudget = KaspiDeadline::in(min(
                (float) config('kaspi.posting.pickup_organization_seconds', 10),
                $round->remaining(),
            ));

            $posted += $this->forOrganization($organizationId, $pickupAfter, $perOrganization, $organizationBudget);

            // Its turn is used, finished or not: the next round starts after it.
            $this->rememberPickedUpOrganization($organizationId);
        }

        return $posted;
    }

    private function lastPickedUpOrganization(): int
    {
        try {
            return (int) Cache::get(self::LAST_ORGANIZATION_KEY, 0);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    private function rememberPickedUpOrganization(int $organizationId): void
    {
        try {
            Cache::forever(self::LAST_ORGANIZATION_KEY, $organizationId);
        } catch (Throwable $exception) {
            // The next round starts from the beginning of the circle instead.
            report($exception);
        }
    }

    /**
     * @return true|'month'|'row' Posted; held by the whole month; held by its own row or an error.
     */
    private function postOne(int $operationId, ?KaspiDeadline $budget): bool|string
    {
        $deadline = KaspiDeadline::in(min(
            (float) config('kaspi.posting.job_lock_wait_seconds', 30),
            $budget?->remaining() ?? PHP_FLOAT_MAX,
        ));

        try {
            $this->posting->handle($operationId, $deadline);

            return true;
        } catch (CannotPostKaspiOperation $exception) {
            $this->posting->rememberAttempt($operationId, $exception->getMessage(), $deadline);

            return $exception->isMonthWide() ? 'month' : 'row';
        } catch (Throwable $exception) {
            report($exception);
            $this->posting->rememberAttempt($operationId, 'Ошибка проводки, повтор автоматически', $deadline);

            return 'row';
        } finally {
            KaspiRetryLog::fold($operationId, $deadline);
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

    /**
     * Repeats left in their own table, folded into the journal: rows a posting
     * held while they came in. A held row is left for the next round.
     */
    public function foldRetries(): void
    {
        $deadline = KaspiDeadline::in(0);

        try {
            $operationIds = DB::table('kaspi_operation_retries')
                ->select('kaspi_operation_id')
                ->distinct()
                ->orderBy('kaspi_operation_id')
                ->limit(500)
                ->pluck('kaspi_operation_id');

            foreach ($operationIds as $operationId) {
                KaspiRetryLog::fold((int) $operationId, $deadline);
            }
        } finally {
            $deadline->restore();
        }
    }
}
