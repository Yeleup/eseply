<?php

namespace App\Models;

use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'tariff_category_id',
    'price',
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

    public function tariffCategory(): BelongsTo
    {
        return $this->belongsTo(TariffCategory::class);
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
            'price' => 'decimal:2',
            'starts_on' => 'date',
        ];
    }
}
