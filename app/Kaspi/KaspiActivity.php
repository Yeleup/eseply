<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Figures of the Kaspi journal of one organization: the tiles of the journal
 * and the line under the Kaspi section of the profile.
 *
 * Every query starts with the organization and uses an index of
 * `kaspi_operations`, so the figures cost the same for 1 or 100 organizations.
 */
final class KaspiActivity
{
    public static function lastRequestAt(Organization $organization): ?CarbonImmutable
    {
        $receivedAt = KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->max('received_at');

        return $receivedAt === null ? null : CarbonImmutable::parse($receivedAt);
    }

    /**
     * Operations received in the last 24 hours that Kaspi got an error for.
     */
    public static function errorsInLastDay(Organization $organization): int
    {
        return KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->where('received_at', '>=', now()->subDay())
            ->where('state', KaspiOperationState::Error->value)
            ->count();
    }

    public static function pendingCount(Organization $organization): int
    {
        return KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->where('state', KaspiOperationState::Pending->value)
            ->count();
    }

    /**
     * Posted payments whose Kaspi date is today, as Kaspi's register counts them.
     *
     * @return array{count: int, sum: float}
     */
    public static function postedToday(Organization $organization): array
    {
        $row = KaspiOperation::query()
            ->where('organization_id', $organization->getKey())
            ->where('txn_date', '>=', today())
            ->where('txn_date', '<', today()->addDay())
            ->where('command', KaspiCommand::Pay->value)
            ->where('state', KaspiOperationState::Posted->value)
            ->selectRaw('count(*) as operations_count, coalesce(sum(kaspi_operations.sum), 0) as operations_sum')
            ->first();

        return [
            'count' => (int) ($row?->getAttribute('operations_count') ?? 0),
            'sum' => (float) ($row?->getAttribute('operations_sum') ?? 0),
        ];
    }

    /**
     * «сегодня в 14:32» or «23.11.2026 в 14:32».
     */
    public static function formatMoment(CarbonImmutable $moment): string
    {
        return ($moment->isToday() ? 'сегодня' : $moment->format('d.m.Y')).' в '.$moment->format('H:i');
    }
}
