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

    public static function acquire(Organization $organization, string|BillingPeriod $period): Lock
    {
        $lock = Cache::lock(self::key($organization, $period), self::LIFETIME);

        if (! $lock->get()) {
            throw new InvalidArgumentException('Дождитесь завершения операции с расчётным месяцем.');
        }

        return $lock;
    }

    public static function restore(Organization $organization, BillingPeriod $period, string $owner): Lock
    {
        return Cache::restoreLock(self::key($organization, $period), $owner);
    }

    private static function key(Organization $organization, string|BillingPeriod $period): string
    {
        $code = $period instanceof BillingPeriod ? $period->starts_on->format('Ym') : BillingPeriod::normalizeCode($period);

        return "billing-period-operation:{$organization->id}:{$code}";
    }
}
