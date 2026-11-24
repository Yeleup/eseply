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
 */
final class KaspiRetryLog
{
    /**
     * Repeats folded at a time; the rest wait for the next fold.
     */
    private const int PORTION = 500;

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

                $newestRetryAt = CarbonImmutable::parse($retries->max('received_at'));

                $operation->forceFill([
                    'retries_count' => $operation->retries_count + $retries->count(),
                    'last_retry_at' => $operation->last_retry_at?->greaterThan($newestRetryAt) ? $operation->last_retry_at : $newestRetryAt,
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
