<?php

namespace App\Kaspi;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One deadline for every lock wait of a Kaspi request.
 *
 * Kaspi drops the connection after 15 seconds, so nothing in one request waits
 * for a lock past this deadline, measured on the monotonic clock.
 *
 * MariaDB's lock wait timeout applies to each single wait, and one posting
 * waits for many rows in turn — the operation, the subscriber, the month, the
 * payments and the receipt — some of them inside model events. So no
 * statement of a Kaspi request waits at all: the session timeout is 0 and a
 * statement that meets a held lock fails at once. The whole attempt is then
 * rolled back and tried again after a short pause, as long as the deadline
 * allows. The deadline bounds the sum of all waits, however many locks there
 * are.
 */
final class KaspiDeadline
{
    /**
     * Pause between two attempts, in seconds.
     */
    private const float RETRY_PAUSE = 0.05;

    private ?int $originalLockWaitTimeout = null;

    private function __construct(private readonly int $endsAtNanoseconds) {}

    public static function in(float $seconds): self
    {
        return new self(hrtime(true) + (int) round(max($seconds, 0.0) * 1_000_000_000));
    }

    public function remaining(): float
    {
        return max(0.0, ($this->endsAtNanoseconds - hrtime(true)) / 1_000_000_000);
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /**
     * Run the attempt with no lock wait in the database; while the deadline
     * allows, try it again when a lock was held or the month changed.
     *
     * The attempt must be a whole unit — its own transaction or one statement
     * — so a failed try leaves nothing behind.
     *
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @return T
     *
     * @throws CannotPostKaspiOperation when the deadline passes first.
     */
    public function retryWithoutWaiting(Closure $attempt): mixed
    {
        while (true) {
            $this->setLockWaitTimeout(0);

            try {
                return $attempt();
            } catch (QueryException $exception) {
                if (! self::isLockTimeout($exception) && ! self::isSnapshotConflict($exception)) {
                    throw $exception;
                }

                $lastFailure = CannotPostKaspiOperation::lockWaitExceeded();
            } catch (CannotPostKaspiOperation $exception) {
                if (! $exception->isRetryable()) {
                    throw $exception;
                }

                $lastFailure = $exception;
            }

            if ($this->remaining() <= self::RETRY_PAUSE) {
                throw $lastFailure;
            }

            $this->pause(self::RETRY_PAUSE);
        }
    }

    /**
     * For bookkeeping that must not hold the answer up: one try, no waiting.
     */
    public function withoutWaiting(): void
    {
        $this->setLockWaitTimeout(0);
    }

    /**
     * Sleep the given seconds, never past the deadline.
     */
    public function pause(float $seconds): void
    {
        $sleep = min($seconds, $this->remaining());

        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
    }

    /**
     * Give the connection back its own lock wait timeout: under Octane the
     * same connection serves the next request. If it cannot be given back,
     * the connection is dropped, so the next request opens a fresh one with
     * the server's default.
     */
    public function restore(): void
    {
        if ($this->originalLockWaitTimeout === null) {
            return;
        }

        $original = $this->originalLockWaitTimeout;
        $this->originalLockWaitTimeout = null;

        try {
            DB::statement('set session innodb_lock_wait_timeout = '.$original);
        } catch (Throwable $exception) {
            Log::warning('Kaspi: тайм-аут блокировки сессии не восстановлен, соединение закрыто', [
                'error' => $exception->getMessage(),
            ]);

            DB::disconnect();
        }
    }

    /**
     * Whether a query failed because it met a held row lock or lost a deadlock.
     */
    public static function isLockTimeout(QueryException $exception): bool
    {
        return in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true);
    }

    /**
     * MariaDB with snapshot isolation refuses a locking read of a row another
     * transaction changed after this one took its snapshot (error 1020).
     */
    public static function isSnapshotConflict(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1020;
    }

    private function setLockWaitTimeout(int $seconds): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->originalLockWaitTimeout ??= (int) DB::scalar('select @@session.innodb_lock_wait_timeout');

        DB::statement('set session innodb_lock_wait_timeout = '.$seconds);
    }
}
