<?php

namespace App\Models;

use Database\Factories\AccrualFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id',
    'client_id',
    'utility_service_id',
    'period',
    'account_number',
    'client_name',
    'utility_service_name',
    'billing_type',
    'volume',
    'tariff_price',
    'amount',
    'paid_amount',
    'opening_balance',
    'closing_balance',
    'closed_at',
])]
class Accrual extends Model
{
    /** @use HasFactory<AccrualFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'paid_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 0,
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

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume' => 'decimal:4',
            'tariff_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }
}
