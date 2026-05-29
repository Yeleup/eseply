<?php

namespace App\Models;

use App\ClientType;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'account_number',
    'name',
    'client_type',
    'phone',
    'region_id',
    'street_id',
    'house',
    'apartment',
    'status',
    'starting_balance',
    'billing_type',
    'residents_count',
    'fixed_amount',
    'note',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_type' => 'individual',
        'status' => 'active',
        'starting_balance' => 0,
        'billing_type' => 'per_person',
        'residents_count' => 0,
        'fixed_amount' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if (! $client->organization_id) {
                return;
            }

            $client->utility_service_id = UtilityService::query()
                ->where('organization_id', $client->organization_id)
                ->value('id');
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
            'client_type' => ClientType::class,
            'starting_balance' => 'decimal:2',
            'residents_count' => 'integer',
            'fixed_amount' => 'decimal:2',
        ];
    }
}
