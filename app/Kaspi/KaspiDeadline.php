<?php

namespace App\Kaspi;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One deadline for every lock wait of a Kaspi request.
 *
 * Kaspi drops the connection after 15 seconds, so the row locks of the
 * database (the session lock wait timeout of MariaDB) and the cache lock of
 * the billing month together never wait past this deadline. It is measured on
 * the monotonic clock, so a change of the system time does not move it.
 *
 * MariaDB counts the lock wait timeout in whole seconds, so every wait gets the
 * whole seconds that are left, rounded down: less than a second left means a
 * lock that is taken fails at once instead of waiting past the deadline.
 */
final class KaspiDeadline
{
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
     * Limit the next row lock waits of this connection to what is left.
     *
     * @throws CannotPostKaspiOperation when nothing is left to wait.
     */
    public function limitRowLockWait(): void
    {
        if ($this->expired()) {
            throw CannotPostKaspiOperation::lockWaitExceeded();
        }

        $this->setLockWaitTimeout((int) floor($this->remaining()));
    }

    /**
     * For bookkeeping that must not hold the answer up: wait at most a second,
     * and not at all once the deadline has passed.
     */
    public function limitBookkeepingWait(): void
    {
        $this->setLockWaitTimeout(min(1, (int) floor($this->remaining())));
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
     * Whether a query failed because it waited for a row lock too long or
     * lost a deadlock: either way the lock was not obtained in time.
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

        DB::statement('set session innodb_lock_wait_timeout = '.max(0, $seconds));
    }
}
