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
        'previous_reading' => 0,
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

            $meterReading->consumption = (float) $meterReading->current_reading - (float) $meterReading->previous_reading;
        });
    }
}
