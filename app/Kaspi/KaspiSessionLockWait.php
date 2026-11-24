<?php

namespace App\Kaspi;

use Illuminate\Database\Connection;
use Throwable;

/**
 * The connection a Kaspi deadline holds at a zero lock wait timeout.
 *
 * Outside a transaction Laravel reconnects by itself when the connection to
 * the database is lost, and repeats the statement on the new connection, which
 * starts with the server's lock wait timeout. While a deadline holds its
 * connection at zero, every new connection of that name gets zero again before
 * the repeated statement runs (the ConnectionEstablished listener of
 * AppServiceProvider), so no statement of the deadline ever waits for a lock.
 *
 * Scoped: one instance per request and per queued job, so the mark never
 * outlives them under Octane or in a queue worker; the deadline clears it in
 * restore().
 */
final class KaspiSessionLockWait
{
    private ?string $connectionName = null;

    private ?KaspiDeadline $holder = null;

    public function hold(KaspiDeadline $deadline, string $connectionName): void
    {
        $this->holder = $deadline;
        $this->connectionName = $connectionName;
    }

    public function release(KaspiDeadline $deadline): void
    {
        if ($this->holder !== $deadline) {
            return;
        }

        $this->holder = null;
        $this->connectionName = null;
    }

    /**
     * Set the zero timeout on a connection just opened, if it is the one held.
     *
     * The statement goes straight to PDO, so its own failure never starts
     * another reconnect; it is swallowed: if the connection is gone again, the
     * repeated statement fails by itself.
     */
    public function applyTo(Connection $connection): void
    {
        if ($this->connectionName === null || $connection->getName() !== $this->connectionName) {
            return;
        }

        try {
            $connection->getPdo()->exec('set session innodb_lock_wait_timeout = 0');
        } catch (Throwable) {
            // Nothing to do: see above.
        }
    }
}
