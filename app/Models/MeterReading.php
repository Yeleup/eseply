<?php

namespace App\Models;

use App\Support\MeterReadingPhotoStorage;
use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'organization_id',
    'meter_id',
    'client_id',
    'utility_service_id',
    'billing_period_id',
    'previous_reading',
    'current_reading',
    'consumption',
    'read_at',
    'note',
    'photo_path',
    'period',
])]
class MeterReading extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<MeterReadingFactory> */
    use HasFactory;

    public const DUPLICATE_BILLING_PERIOD_MESSAGE = 'За текущий расчётный месяц по этому счётчику уже есть запись. Измените существующую запись вместо создания новой.';

    public const BELOW_PREVIOUS_READING_MESSAGE = 'Показание не может быть меньше предыдущего (:previous). Если счётчик перекрутился или заменён, показание вводит оператор.';

    public const int MAXIMUM_CURRENT_READING = 99_999;

    public const PHOTO_DISK = 'public';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'consumption' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    /**
     * Rows that actually carry a reading.
     *
     * A row with an empty `current_reading` records a visit where the meter
     * could not be read — sealed, no access, nobody home — and exists only to
     * hold the photo and the note explaining it. It is not a reading: it never
     * counts towards "снято", never contributes consumption, never becomes the
     * previous reading and never lets the month close. Every query that asks
     * "was this meter read this period" goes through this scope.
     *
     * @param  Builder<MeterReading>  $query
     * @return Builder<MeterReading>
     */
    public function scopeTaken(Builder $query): Builder
    {
        return $query->whereNotNull($query->getModel()->qualifyColumn('current_reading'));
    }

    public function hasAcceptedNegativeConsumption(): bool
    {
        return $this->closure_resolution === 'negative_meter_consumption'
            && $this->closure_accepted_at !== null;
    }

    public function isTaken(): bool
    {
        return $this->current_reading !== null;
    }

    /**
     * @param  Builder<MeterReading>  $query
     * @return Builder<MeterReading>
     */
    public function scopeVisibleToOrganizationMember(Builder $query, User $user, Organization|int|string|null $organization): Builder
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where($query->getModel()->qualifyColumn('organization_id'), $organizationId);

        if ($user->isOrganizationOperator($organizationId)) {
            return $query;
        }

        if (! $user->isOrganizationController($organizationId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'client',
            fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organizationId),
        );
    }

    public static function previousReadingFor(int|string|null $meterId, int|string|null $period = null): ?int
    {
        if ($meterId === null || $meterId === '') {
            return null;
        }

        $meter = Meter::query()->find((int) $meterId);

        if (! $meter) {
            return null;
        }

        $previousReadingQuery = self::query()
            ->where('meter_id', $meter->id)
            // A visit that took no reading must never become the baseline of
            // the next one: it would reset the meter to its initial value and
            // bill the whole history in a single month.
            ->taken();

        if ($period !== null && $period !== '') {
            $previousReadingQuery->beforePeriod((string) $period);
        }

        $previousReading = $previousReadingQuery
            ->orderByBillingPeriodDesc()
            ->orderByDesc('id')
            ->value('current_reading');

        return $previousReading === null
            ? self::wholeReading($meter->initial_reading)
            : self::wholeReading($previousReading);
    }

    public static function previousReadingForBillingPeriod(int|string|null $meterId, int|string|null $billingPeriodId = null): ?int
    {
        if ($billingPeriodId === null || $billingPeriodId === '') {
            return self::previousReadingFor($meterId);
        }

        $billingPeriod = BillingPeriod::query()->find((int) $billingPeriodId);

        return self::previousReadingFor($meterId, $billingPeriod?->code);
    }

    public static function existsForMeterBillingPeriod(
        int|string|null $meterId,
        int|string|null $billingPeriodId,
        int|string|null $exceptReadingId = null,
    ): bool {
        if ($meterId === null || $meterId === '' || $billingPeriodId === null || $billingPeriodId === '') {
            return false;
        }

        $query = self::query()
            ->where('meter_id', (int) $meterId)
            ->where('billing_period_id', (int) $billingPeriodId);

        if ($exceptReadingId !== null && $exceptReadingId !== '') {
            $query->whereKeyNot((int) $exceptReadingId);
        }

        return $query->exists();
    }

    /**
     * The lowest value that may be saved as the current reading.
     *
     * A reading equal to the previous one is always allowed: an empty flat
     * consumes nothing, and zero consumption never blocks the closure of the
     * month. Only a reading strictly below the previous one is restricted.
     */
    public static function minimumCurrentReading(?int $previousReading, bool $canGoBelowPrevious): int
    {
        if ($canGoBelowPrevious) {
            return 0;
        }

        return max(0, $previousReading ?? 0);
    }

    public static function belowPreviousReadingMessage(?int $previousReading): string
    {
        return str_replace(':previous', (string) ($previousReading ?? 0), self::BELOW_PREVIOUS_READING_MESSAGE);
    }

    public static function maximumCurrentReadingMessage(): string
    {
        return __('meter-readings.validation.current_reading.max', [
            'max' => self::MAXIMUM_CURRENT_READING,
        ]);
    }

    /**
     * @param  array{current_reading: int, consumption: int, average_consumption: float}  $confirmation
     */
    public static function largeConsumptionConfirmationDescription(array $confirmation): string
    {
        return __('meter-readings.confirmation.large_consumption.description', [
            'current' => $confirmation['current_reading'],
            'consumption' => $confirmation['consumption'],
            'average' => self::formatAverageConsumption($confirmation['average_consumption']),
        ]);
    }

    /**
     * Returns the values that must be shown before a controller confirms an
     * unusually large consumption, or `null` when confirmation is unnecessary.
     *
     * @return array{current_reading: int, consumption: int, average_consumption: float}|null
     */
    public static function largeConsumptionConfirmationFor(
        int|string|null $meterId,
        int|string|null $billingPeriodId,
        ?int $previousReading,
        mixed $currentReading,
    ): ?array {
        $reading = self::wholeReading($currentReading);

        if ($meterId === null || $meterId === '' || $billingPeriodId === null || $billingPeriodId === '' || $reading === null) {
            return null;
        }

        $consumption = $reading - ($previousReading ?? 0);
        $averageConsumption = self::averageConsumptionForPreviousBillingMonths($meterId, $billingPeriodId);

        if ($averageConsumption === null || $averageConsumption <= 0 || $consumption <= 3 * $averageConsumption) {
            return null;
        }

        return [
            'current_reading' => $reading,
            'consumption' => $consumption,
            'average_consumption' => $averageConsumption,
        ];
    }

    private static function averageConsumptionForPreviousBillingMonths(
        int|string $meterId,
        int|string $billingPeriodId,
    ): ?float {
        $billingPeriod = BillingPeriod::query()->find((int) $billingPeriodId);

        if (! $billingPeriod instanceof BillingPeriod) {
            return null;
        }

        $consumptions = self::query()
            ->where('meter_id', (int) $meterId)
            ->taken()
            ->where('consumption', '>=', 0)
            ->whereHas(
                'billingPeriod',
                fn (Builder $query): Builder => $query->whereDate('starts_on', '<', $billingPeriod->starts_on->toDateString()),
            )
            ->orderByBillingPeriodDesc()
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('consumption');

        if ($consumptions->isEmpty()) {
            return null;
        }

        return (float) $consumptions->avg();
    }

    private static function formatAverageConsumption(float $averageConsumption): string
    {
        return rtrim(rtrim(number_format($averageConsumption, 2, ',', ' '), '0'), ',');
    }

    public static function photoDirectoryFor(int|string $organizationId): string
    {
        return MeterReadingPhotoStorage::directoryFor($organizationId);
    }

    /**
     * Readings are whole numbers: any fractional input is rounded before it is stored.
     *
     * Pass the raw attribute value, not the model accessor: the "integer" cast
     * would already have truncated the fractional part.
     */
    public static function wholeReading(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_reading' => 'integer',
            'current_reading' => 'integer',
            'consumption' => 'integer',
            'read_at' => 'date',
            'closure_accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MeterReading $meterReading): void {
            if ($meterReading->meter_id) {
                $meter = $meterReading->meter()->first();

                if ($meter) {
                    $meterReading->organization_id = $meter->organization_id;
                    $meterReading->client_id = $meter->client_id;
                    $meterReading->utility_service_id = $meter->utility_service_id;
                }
            }

            if ($meterReading->meter_id && $meterReading->previous_reading === null) {
                $meterReading->previous_reading = self::previousReadingFor(
                    $meterReading->meter_id,
                    $meterReading->period,
                ) ?? 0;
            }

            $meterReading->resolveBillingPeriodIdFromPeriodCode(useCurrentWhenMissing: true);
            $meterReading->ensureBillingPeriodIsEditable();
            $meterReading->ensureUniqueForBillingPeriod();

            $previousReading = self::wholeReading($meterReading->getAttributes()['previous_reading'] ?? null);
            $currentReading = self::wholeReading($meterReading->getAttributes()['current_reading'] ?? null);

            $meterReading->previous_reading = $previousReading;
            $meterReading->current_reading = $currentReading;

            if ($currentReading !== null && $currentReading > self::MAXIMUM_CURRENT_READING) {
                throw ValidationException::withMessages([
                    'current_reading' => self::maximumCurrentReadingMessage(),
                ]);
            }

            // No reading means no consumption, not a consumption of minus the
            // previous value: the row must stay neutral in every SUM it lands
            // in — the receipt volume, the accrual and the reports.
            $meterReading->consumption = $currentReading === null
                ? 0
                : $currentReading - ($previousReading ?? 0);

            if ($meterReading->isDirty(['previous_reading', 'current_reading', 'meter_id', 'billing_period_id'])) {
                $meterReading->closure_resolution = null;
                $meterReading->closure_accepted_by_user_id = null;
                $meterReading->closure_accepted_at = null;
            }
        });

        static::deleting(function (MeterReading $meterReading): void {
            $meterReading->ensureBillingPeriodIsEditable();
        });

        static::updated(function (MeterReading $meterReading): void {
            $previousPhotoPath = $meterReading->getOriginal('photo_path');

            if ($meterReading->wasChanged('photo_path')) {
                MeterReadingPhotoStorage::delete($previousPhotoPath);
            }
        });

        static::deleted(function (MeterReading $meterReading): void {
            MeterReadingPhotoStorage::delete($meterReading->photo_path);
        });

        static::saved(function (MeterReading $meterReading): void {
            // A visit without a reading is not a billable event, so it must not
            // stamp a receipt on a subscriber nobody has read yet. It still
            // refreshes a receipt that already exists — another meter of the
            // same subscriber may have been read, and clearing a value has to
            // take its charge off the receipt it was on.
            if (! $meterReading->isTaken() && ! Receipt::existsForMeterReading($meterReading)) {
                return;
            }

            Receipt::fromMeterReading($meterReading);
        });
    }

    private function ensureUniqueForBillingPeriod(): void
    {
        if (! $this->meter_id || ! $this->billing_period_id) {
            return;
        }

        if (! self::existsForMeterBillingPeriod($this->meter_id, $this->billing_period_id, $this->getKey())) {
            return;
        }

        throw ValidationException::withMessages([
            'current_reading' => self::DUPLICATE_BILLING_PERIOD_MESSAGE,
        ]);
    }

    private static function organizationId(Organization|int|string|null $organization): ?int
    {
        if ($organization instanceof Organization) {
            return (int) $organization->getKey();
        }

        if (is_int($organization)) {
            return $organization;
        }

        if (is_string($organization) && ctype_digit($organization)) {
            return (int) $organization;
        }

        return null;
    }
}
