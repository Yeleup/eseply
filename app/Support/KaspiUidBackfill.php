<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives every organization without a Kaspi uid its own, once, at deployment.
 * A uid that is already there is never touched.
 */
final class KaspiUidBackfill
{
    public static function run(string $table = 'organizations'): int
    {
        $filled = 0;

        DB::table($table)
            ->whereNull('kaspi_uid')
            ->orderBy('id')
            ->select('id')
            ->each(function (object $organization) use ($table, &$filled): void {
                $filled += DB::table($table)
                    ->where('id', $organization->id)
                    ->whereNull('kaspi_uid')
                    ->update(['kaspi_uid' => (string) Str::uuid()]);
            });

        return $filled;
    }
}
