<?php

namespace App\Kaspi;

use App\Models\KaspiOperation;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Figures of Kaspi online of one organization: the tiles of the journal and
 * the line under the Kaspi section of the profile.
 *
 * Every query starts with the organization and uses an index of
 * `kaspi_operations` or `kaspi_request_log`, so the figures cost the same for
 * 1 or 100 organizations.
 */
final class KaspiActivity
{
    /**
     * Count a request that came from Kaspi to this organization, whatever the
     * answer — a rejected `pay` that creates no operation and a repeat
     * included — at the moment it was received. Bookkeeping only: the row has
     * no foreign key, so writing it waits for no other row.
     */
    public static function record(Organization $organization, int $result, CarbonInterface $receivedAt, KaspiDeadline $deadline): void
    {
        try {
            $deadline->withoutWaiting();

            DB::table('kaspi_request_log')->insert([
                'organization_id' => $organization->getKey(),
                'received_at' => $receivedAt,
                'result' => $result,
            ]);
        } catch (QueryException $exception) {
            Log::info('Kaspi: запрос не учтён в статистике', ['error' => $exception->getMessage()]);
        }
    }

    public static function lastRequestAt(Organization $organization): ?CarbonImmutable
    {
        $lastRequestAt = DB::table('kaspi_request_log')
            ->where('organization_id', $organization->getKey())
            ->max('received_at');

        return $lastRequestAt === null ? null : CarbonImmutable::parse($lastRequestAt);
    }

    /**
     * Answers with code 5 that Kaspi got for requests received in the last 24
     * hours, to the second.
     */
    public static function errorsInLastDay(Organization $organization): int
    {
        return DB::table('kaspi_request_log')
            ->where('organization_id', $organization->getKey())
            ->where('received_at', '>', now()->subDay())
            ->where('result', 5)
            ->count();
    }

    /**
     * Keep 30 days of the request log, and always the latest request of each
     * organization: the profile still knows when Kaspi last called.
     */
    public static function prune(int $keepDays = 30): int
    {
        $before = now()->subDays($keepDays);
        $deleted = 0;

        $organizationIds = DB::table('kaspi_request_log')
            ->where('received_at', '<', $before)
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $latestId = DB::table('kaspi_request_log')->where('organization_id', $organizationId)->max('id');

            $deleted += DB::table('kaspi_request_log')
                ->where('organization_id', $organizationId)
                ->where('received_at', '<', $before)
                ->where('id', '<', $latestId)
                ->delete();
        }

        return $deleted;
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
