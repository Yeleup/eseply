<?php

namespace App\Models;

use App\BalanceAdjustmentType;
use Database\Factories\BalanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'client_id',
    'period',
    'type',
    'amount',
    'adjusted_at',
    'note',
])]
class BalanceAdjustment extends Model
{
    /** @use HasFactory<BalanceAdjustmentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'manual_adjustment',
        'amount' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BalanceAdjustmentType::class,
            'amount' => 'decimal:2',
            'adjusted_at' => 'date',
        ];
    }
}
