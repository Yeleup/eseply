<?php

namespace App\Models;

use App\ClientType;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'client_type',
    'unit_price',
    'per_person_price',
    'starts_on',
    'status',
])]
class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_type' => 'individual',
        'status' => 'active',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Tariff $tariff): void {
            if (! $tariff->organization_id) {
                return;
            }

            $tariff->utility_service_id = UtilityService::query()
                ->where('organization_id', $tariff->organization_id)
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
            'unit_price' => 'decimal:2',
            'per_person_price' => 'decimal:2',
            'starts_on' => 'date',
        ];
    }
}
