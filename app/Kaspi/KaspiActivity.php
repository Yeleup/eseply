<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Figures of Kaspi online of one organization: the tiles of the journal and
 * the line under the Kaspi section of the profile.
 *
 * Every query starts with the organization and uses an index of
 * `kaspi_operations`, so the figures cost the same for 1 or 100 organizations.
 */
final class KaspiActivity
{
    /**
     * Count a request that came from Kaspi to this organization, whatever the
     * answer — a rejected `pay` that creates no operation and a repeat
     * included. Bookkeeping only: it never holds the answer past the deadline.
     */
    public static function record(Organization $organization, int $result, KaspiDeadline $deadline): void
    {
        $now = now();

        try {
            $deadline->limitBookkeepingWait();

            DB::table('kaspi_request_stats')->upsert(
                [[
                    'organization_id' => $organization->getKey(),
                    'hour_start' => $now->copy()->startOfHour(),
                    'requests' => 1,
                    'errors' => $result === 5 ? 1 : 0,
                    'last_request_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['organization_id', 'hour_start'],
                [
                    'requests' => DB::raw('requests + 1'),
                    'errors' => DB::raw('errors + '.($result === 5 ? 1 : 0)),
                    'last_request_at' => DB::raw('greatest(last_request_at, values(last_request_at))'),
                    'updated_at' => DB::raw('values(updated_at)'),
                ],
            );
        } catch (QueryException $exception) {
            Log::info('Kaspi: запрос не учтён в статистике', ['error' => $exception->getMessage()]);
        }
    }

    public static function lastRequestAt(Organization $organization): ?CarbonImmutable
    {
        $lastRequestAt = DB::table('kaspi_request_stats')
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('hour_start')
            ->value('last_request_at');

        return $lastRequestAt === null ? null : CarbonImmutable::parse($lastRequestAt);
    }

    /**
     * Answers with code 5 that Kaspi got in the last 24 hours, counted by the
     * hour the request came in.
     */
    public static function errorsInLastDay(Organization $organization): int
    {
        return (int) DB::table('kaspi_request_stats')
            ->where('organization_id', $organization->getKey())
            ->where('hour_start', '>', now()->subDay())
            ->sum('errors');
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
