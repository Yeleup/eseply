<?php

namespace App\Models;

use Database\Factories\MeterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'client_id',
    'utility_service_id',
    'active_client_id',
    'number',
    'installed_on',
    'initial_reading',
    'removed_on',
    'status',
    'note',
])]
class Meter extends Model
{
    /** @use HasFactory<MeterFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'initial_reading' => 0,
        'status' => 'active',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Meter $meter): void {
            if ($meter->organization_id) {
                $meter->utility_service_id = UtilityService::query()
                    ->where('organization_id', $meter->organization_id)
                    ->value('id');
            }

            $meter->active_client_id = $meter->status === 'active'
                ? $meter->client_id
                : null;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'initial_reading' => 'decimal:4',
            'removed_on' => 'date',
        ];
    }
}
