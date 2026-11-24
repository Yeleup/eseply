<?php

namespace App\Support;

use App\Models\BillingPeriod;
use App\Models\Organization;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class BillingPeriodOperationLock
{
    public const int LIFETIME = 3600;

    private const int WAIT_STEP_MICROSECONDS = 100_000;

    public static function acquire(Organization $organization, string|BillingPeriod $period): Lock
    {
        $lock = Cache::lock(self::key($organization, $period), self::LIFETIME);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Дождитесь завершения операции с расчётным месяцем.');
        }

        return $lock;
    }

    /**
     * Wait for the lock no longer than the given number of seconds.
     *
     * A Kaspi payment is posted under the same lock as the start of closing, so
     * a payment either lands before the month starts closing or sees it closing.
     */
    public static function waitFor(Organization|int $organization, BillingPeriod $period, float $seconds): ?Lock
    {
        $lock = Cache::lock(self::key($organization, $period), self::LIFETIME);

        // Measured on the real clock, not on Carbon: a frozen test clock must
        // not turn the wait into an endless loop.
        $waitUntil = microtime(true) + max($seconds, 0.0);

        while (! $lock->get()) {
            if (microtime(true) >= $waitUntil) {
                return null;
            }

            usleep(self::WAIT_STEP_MICROSECONDS);
        }

        return $lock;
    }

    public static function restore(Organization $organization, BillingPeriod $period, string $owner): Lock
    {
        return Cache::restoreLock(self::key($organization, $period), $owner);
    }

    private static function key(Organization|int $organization, string|BillingPeriod $period): string
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;
        $code = $period instanceof BillingPeriod ? $period->starts_on->format('Ym') : BillingPeriod::normalizeCode($period);

        return "billing-period-operation:{$organizationId}:{$code}";
    }
}
