<?php

namespace App\Models;

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
    'period',
])]
class MeterReading extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<MeterReadingFactory> */
    use HasFactory;

    public const DUPLICATE_BILLING_PERIOD_MESSAGE = 'За текущий расчётный месяц уже есть показание по этому счётчику. Измените существующее показание вместо создания нового.';

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

    public static function previousReadingFor(int|string|null $meterId, int|string|null $period = null): ?float
    {
        if ($meterId === null || $meterId === '') {
            return null;
        }

        $meter = Meter::query()->find((int) $meterId);

        if (! $meter) {
            return null;
        }

        $previousReadingQuery = self::query()
            ->where('meter_id', $meter->id);

        if ($period !== null && $period !== '') {
            $previousReadingQuery->beforePeriod((string) $period);
        }

        $previousReading = $previousReadingQuery
            ->orderByBillingPeriodDesc()
            ->orderByDesc('id')
            ->value('current_reading');

        return $previousReading === null
            ? (float) $meter->initial_reading
            : (float) $previousReading;
    }

    public static function previousReadingForBillingPeriod(int|string|null $meterId, int|string|null $billingPeriodId = null): ?float
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_reading' => 'decimal:4',
            'current_reading' => 'decimal:4',
            'consumption' => 'decimal:4',
            'read_at' => 'date',
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

            $meterReading->consumption = (float) $meterReading->current_reading - (float) $meterReading->previous_reading;
        });

        static::deleting(function (MeterReading $meterReading): void {
            $meterReading->ensureBillingPeriodIsEditable();
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
