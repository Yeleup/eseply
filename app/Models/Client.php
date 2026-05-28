<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'tariff_category_id',
    'account_number',
    'name',
    'client_type',
    'phone',
    'address',
    'status',
    'starting_balance',
    'billing_type',
    'residents_count',
    'area',
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
        'billing_type' => 'normative',
        'residents_count' => 0,
        'area' => 0,
        'fixed_amount' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tariffCategory(): BelongsTo
    {
        return $this->belongsTo(TariffCategory::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
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
            'starting_balance' => 'decimal:2',
            'residents_count' => 'integer',
            'area' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
        ];
    }
}
