<?php

namespace App\Models;

use Database\Factories\BillingPeriodClosureErrorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'billing_period_id',
    'organization_id',
    'client_id',
    'account_number',
    'client_name',
    'billing_type',
    'code',
    'message',
    'context',
])]
class BillingPeriodClosureError extends Model
{
    /** @use HasFactory<BillingPeriodClosureErrorFactory> */
    use HasFactory;

    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

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
            'context' => 'array',
        ];
    }
}
