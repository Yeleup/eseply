<?php

namespace App\Support;

use App\Kaspi\KaspiDeadline;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class BillingPeriodOperationLock
{
    public const int LIFETIME = 3600;

    private const float WAIT_STEP_SECONDS = 0.05;

    public static function acquire(Organization $organization, string|BillingPeriod $period): Lock
    {
        $lock = Cache::lock(self::key($organization, $period), self::LIFETIME);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Дождитесь завершения операции с расчётным месяцем.');
        }

        return $lock;
    }

    /**
     * Wait for the lock no longer than the deadline allows.
     *
     * A Kaspi payment is posted under the same lock as the start of closing, so
     * a payment either lands before the month starts closing or sees it closing.
     * The wait is measured on the deadline's monotonic clock and never sleeps
     * past it.
     */
    public static function waitFor(Organization|int $organization, BillingPeriod $period, KaspiDeadline $deadline): ?Lock
    {
        $lock = Cache::lock(self::key($organization, $period), self::LIFETIME);

        while (! $lock->get()) {
            if ($deadline->expired()) {
                return null;
            }

            $deadline->pause(self::WAIT_STEP_SECONDS);
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
