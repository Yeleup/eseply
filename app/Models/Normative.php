<?php

namespace App\Models;

use Database\Factories\NormativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'tariff_category_id',
    'value',
    'calculation_type',
    'starts_on',
    'status',
])]
class Normative extends Model
{
    /** @use HasFactory<NormativeFactory> */
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'starts_on' => 'date',
        ];
    }
}
