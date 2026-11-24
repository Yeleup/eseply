<?php

namespace App\Jobs;

use App\Kaspi\CannotPostKaspiOperation;
use App\Kaspi\KaspiDeadline;
use App\Kaspi\KaspiOperationState;
use App\Kaspi\KaspiRetryLog;
use App\Kaspi\PostKaspiOperation;
use App\Models\KaspiOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Posts one Kaspi payment left in processing, with pauses between tries.
 *
 * Posting does not wait for Kaspi to repeat the request; once the tries are
 * spent the scheduler keeps picking the operation up every minute.
 */
class PostKaspiOperationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 6;

    public function __construct(public int $operationId)
    {
        $this->tries = count($this->pauses()) + 1;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->pauses();
    }

    public function handle(PostKaspiOperation $posting): void
    {
        $operation = KaspiOperation::query()->find($this->operationId);

        if (! $operation instanceof KaspiOperation || $operation->state !== KaspiOperationState::Pending) {
            return;
        }

        $deadline = KaspiDeadline::in((float) config('kaspi.posting.job_lock_wait_seconds', 30));

        try {
            $posting->handle($operation, $deadline);
        } catch (CannotPostKaspiOperation $exception) {
            $posting->rememberAttempt($operation, $exception->getMessage(), $deadline, $exception->wasAttempted());

            if ($this->attempts() < $this->tries) {
                $this->release($this->pauses()[$this->attempts() - 1] ?? 60);
            }
        } finally {
            KaspiRetryLog::fold((int) $operation->getKey(), $deadline);
            $deadline->restore();
        }
    }

    /**
     * @return list<int>
     */
    private function pauses(): array
    {
        return array_values(array_map(intval(...), (array) config('kaspi.posting.backoff', [10, 30, 60, 120, 300])));
    }
}
