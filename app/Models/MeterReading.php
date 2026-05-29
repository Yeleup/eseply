<?php

namespace App\Models;

use Database\Factories\MeterReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'meter_id',
    'client_id',
    'utility_service_id',
    'period',
    'previous_reading',
    'current_reading',
    'consumption',
    'read_at',
    'note',
])]
class MeterReading extends Model
{
    /** @use HasFactory<MeterReadingFactory> */
    use HasFactory;

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

        $period = $period === null ? null : (string) $period;

        if ($period !== null && preg_match('/^\d{6}$/', $period) === 1) {
            $previousReadingQuery->where('period', '<', $period);
        }

        $previousReading = $previousReadingQuery
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->value('current_reading');

        return $previousReading === null
            ? (float) $meter->initial_reading
            : (float) $previousReading;
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

            $meterReading->consumption = (float) $meterReading->current_reading - (float) $meterReading->previous_reading;
        });
    }
}
