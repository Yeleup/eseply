<?php

namespace App\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "This meter was read in this billing period".
 *
 * A `meter_readings` row with an empty `current_reading` records a visit where
 * the meter could not be read — sealed, no access, nobody home — and carries
 * only the photo and the note explaining it. Such a row must never satisfy this
 * predicate: it would push the meter off the walk list, out of the "не снятые"
 * report and up the completion percentage, all while nobody has read anything.
 *
 * The rule lives here because it is duplicated across the dashboard, the
 * controller progress report and the report summaries as hand-written EXISTS
 * subqueries. A copy that silently drifts turns "не снято" into "снято", so
 * every copy goes through this class.
 *
 * The Eloquent side of the same rule is `MeterReading::scopeTaken()`.
 */
final class TakenMeterReading
{
    /**
     * The condition as raw SQL, for the one query that has to be a raw
     * `case when exists (...)` select.
     */
    public const string SQL_CONDITION = 'meter_readings.current_reading is not null';

    /**
     * Fills a sub-select with "a taken reading of this period exists for
     * `meters.id`". The caller supplies the `whereExists` / `whereNotExists`.
     */
    public static function applyExists(QueryBuilder $query, int|string $billingPeriodId): void
    {
        $query
            ->selectRaw('1')
            ->from('meter_readings')
            ->whereColumn('meter_readings.meter_id', 'meters.id')
            ->where('meter_readings.billing_period_id', $billingPeriodId)
            ->whereNotNull('meter_readings.current_reading');
    }
}
