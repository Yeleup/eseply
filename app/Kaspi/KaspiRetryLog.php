<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repeats of Kaspi operations: written at once, folded into the journal later.
 *
 * A repeat is answered from the state of its operation within the deadline. A
 * posting may hold the journal row longer than that, so the repeat does not
 * update the row itself: it inserts a row of its own, which waits for nothing,
 * and the repeats are folded — count, last time, last code, mismatch — under
 * the lock of the journal row: right away when the row is free, otherwise
 * after the posting and by the scheduler every minute.
 */
final class KaspiRetryLog
{
    public static function record(KaspiOperation $operation, bool $mismatch, int $result, CarbonInterface $receivedAt): void
    {
        DB::table('kaspi_operation_retries')->insert([
            'kaspi_operation_id' => $operation->getKey(),
            'received_at' => $receivedAt,
            'result' => $result,
            'mismatch' => $mismatch,
        ]);
    }

    /**
     * Fold the repeats of one operation into its journal row.
     *
     * @param  KaspiDeadline|null  $deadline  With a deadline, a held journal row is not waited for: the fold is left for later.
     */
    public static function fold(int $operationId, ?KaspiDeadline $deadline = null): void
    {
        try {
            $deadline?->withoutWaiting();

            DB::transaction(function () use ($operationId): void {
                // Locking reads only, the journal row first: the repeats are read
                // and removed under its lock, so none is counted twice or lost.
                $operation = KaspiOperation::query()->whereKey($operationId)->lockForUpdate()->first();

                $retries = DB::table('kaspi_operation_retries')
                    ->where('kaspi_operation_id', $operationId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if (! $operation instanceof KaspiOperation || $retries->isEmpty()) {
                    return;
                }

                $latest = $retries->last();

                $operation->forceFill([
                    'retries_count' => $operation->retries_count + $retries->count(),
                    'last_retry_at' => $retries->max('received_at'),
                    'last_result' => (int) $latest->result,
                    'retry_mismatch' => $operation->retry_mismatch || $retries->contains(fn (object $retry): bool => (bool) $retry->mismatch),
                ])->save();

                DB::table('kaspi_operation_retries')->whereIn('id', $retries->pluck('id'))->delete();
            });
        } catch (QueryException $exception) {
            // The repeats stay in their own table until the next fold.
            Log::info('Kaspi: повторы пока не перенесены в журнал', [
                'kaspi_operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
