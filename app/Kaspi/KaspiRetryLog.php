<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use Carbon\CarbonImmutable;
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
 *
 * The fold locks only the journal row and the repeats it removes: it reads a
 * portion of them with a plain read and deletes exactly those rows by their
 * primary key. No range of the repeat log is locked, so a repeat that comes
 * during a fold is written at once and waits for the next fold.
 *
 * The first request of an operation a job posted before it answers 0 that the
 * row does not know yet; it goes through the same log as an answer that is
 * not a repeat: it takes part in the last code, not in the count or the time
 * of repeats.
 */
final class KaspiRetryLog
{
    /**
     * Repeats folded at a time; the rest wait for the next fold.
     */
    private const int PORTION = 500;

    public static function record(KaspiOperation $operation, bool $mismatch, int $result, CarbonInterface $receivedAt): void
    {
        self::insert($operation, $result, $receivedAt, $mismatch, isRetry: true);
    }

    /**
     * The answer the first request sent, when the row does not hold it yet.
     */
    public static function recordFirstAnswer(KaspiOperation $operation, int $result, CarbonInterface $receivedAt): void
    {
        self::insert($operation, $result, $receivedAt, mismatch: false, isRetry: false);
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
                // The journal row first, with a locking read: folds of one
                // operation go one at a time, so no repeat is counted twice.
                $operation = KaspiOperation::query()->whereKey($operationId)->lockForUpdate()->first();

                if (! $operation instanceof KaspiOperation) {
                    return;
                }

                // A plain read: it locks nothing in the repeat log.
                $retries = DB::table('kaspi_operation_retries')
                    ->where('kaspi_operation_id', $operationId)
                    ->orderBy('id')
                    ->limit(self::PORTION)
                    ->get();

                if ($retries->isEmpty()) {
                    return;
                }

                $lastResult = (int) $operation->last_result;

                foreach ($retries as $retry) {
                    $lastResult = self::followingResult($lastResult, (int) $retry->result);
                }

                $repeats = $retries->filter(fn (object $retry): bool => (bool) $retry->is_retry);
                $lastRetryAt = $operation->last_retry_at;

                if ($repeats->isNotEmpty()) {
                    $newestRetryAt = CarbonImmutable::parse($repeats->max('received_at'));
                    $lastRetryAt = $lastRetryAt?->greaterThan($newestRetryAt) ? $lastRetryAt : $newestRetryAt;
                }

                $operation->forceFill([
                    'retries_count' => $operation->retries_count + $repeats->count(),
                    'last_retry_at' => $lastRetryAt,
                    'last_result' => $lastResult,
                    'retry_mismatch' => $operation->retry_mismatch || $retries->contains(fn (object $retry): bool => (bool) $retry->mismatch),
                ])->save();

                // Exactly the rows read, by their primary key.
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

    private static function insert(KaspiOperation $operation, int $result, CarbonInterface $receivedAt, bool $mismatch, bool $isRetry): void
    {
        DB::table('kaspi_operation_retries')->insert([
            'kaspi_operation_id' => $operation->getKey(),
            'received_at' => $receivedAt,
            'result' => $result,
            'mismatch' => $mismatch,
            'is_retry' => $isRetry,
        ]);
    }

    /**
     * The last code sent to Kaspi after one more repeat: codes follow in the
     * order the repeats were written, but 4 never replaces 0 — a repeat gets 4
     * only before the posting commits and 0 only after it, so a 4 written
     * after a 0 was answered earlier.
     */
    private static function followingResult(int $lastResult, int $repeatResult): int
    {
        return $lastResult === 0 && $repeatResult === 4 ? 0 : $repeatResult;
    }
}
