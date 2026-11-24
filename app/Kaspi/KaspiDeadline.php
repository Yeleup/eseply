<?php

namespace App\Kaspi;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One deadline for every lock wait of a Kaspi request.
 *
 * Kaspi drops the connection after 15 seconds, so the row locks of the
 * database (the session lock wait timeout) and the cache lock of the billing
 * month together wait no longer than this deadline.
 */
final class KaspiDeadline
{
    private ?int $originalLockWaitTimeout = null;

    private function __construct(private readonly float $endsAt) {}

    public static function in(float $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    public function remaining(): float
    {
        return max(0.0, $this->endsAt - microtime(true));
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

        if (! $this->supportsLockWaitTimeout()) {
            return;
        }

        $this->originalLockWaitTimeout ??= (int) DB::scalar('select @@session.innodb_lock_wait_timeout');

        DB::statement('set session innodb_lock_wait_timeout = '.max(1, (int) ceil($this->remaining())));
    }

    /**
     * Give the connection back its own lock wait timeout: under Octane the
     * same connection serves the next request.
     */
    public function restore(): void
    {
        if ($this->originalLockWaitTimeout === null) {
            return;
        }

        try {
            DB::statement('set session innodb_lock_wait_timeout = '.$this->originalLockWaitTimeout);
        } finally {
            $this->originalLockWaitTimeout = null;
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

    private function supportsLockWaitTimeout(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
