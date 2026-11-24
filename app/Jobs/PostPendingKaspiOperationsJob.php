<?php

namespace App\Jobs;

use App\Kaspi\KaspiDeadline;
use App\Kaspi\PostPendingKaspiOperations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Posts every Kaspi payment of an organization left in processing, once its
 * closing of the month has ended — successfully, with data errors or with a
 * technical failure.
 */
class PostPendingKaspiOperationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public int $organizationId) {}

    public function handle(PostPendingKaspiOperations $postPending): void
    {
        // Within the job's timeout; what is left goes to the scheduler's rounds.
        $postPending->forOrganization($this->organizationId, budget: KaspiDeadline::in($this->timeout - 60));
    }
}
