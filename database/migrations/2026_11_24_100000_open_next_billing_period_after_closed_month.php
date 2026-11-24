<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opens the next billing month once for every organization whose latest month
 * is closed with nothing after it.
 *
 * From now on a successful closing opens the next month in its own
 * transaction; organizations closed before that would otherwise stay without an
 * open month until an operator opened one by hand.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $latestStartsOn = DB::table('billing_periods')
            ->select('organization_id')
            ->selectRaw('max(starts_on) as starts_on')
            ->groupBy('organization_id');

        $now = now();

        DB::table('billing_periods')
            ->joinSub($latestStartsOn, 'latest', function ($join): void {
                $join->on('latest.organization_id', '=', 'billing_periods.organization_id')
                    ->on('latest.starts_on', '=', 'billing_periods.starts_on');
            })
            ->where('billing_periods.status', 'closed')
            ->orderBy('billing_periods.organization_id')
            ->select(['billing_periods.organization_id', 'billing_periods.starts_on'])
            ->get()
            ->each(function (object $latest) use ($now): void {
                DB::table('billing_periods')->insertOrIgnore([
                    'organization_id' => $latest->organization_id,
                    'starts_on' => CarbonImmutable::parse($latest->starts_on)->startOfMonth()->addMonth()->toDateString(),
                    'status' => 'open',
                    'opened_at' => $now,
                    'opened_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     *
     * The opened months may already hold payments, readings and adjustments, so
     * they are not removed.
     */
    public function down(): void
    {
        //
    }
};
