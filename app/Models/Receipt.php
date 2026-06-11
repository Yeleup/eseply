<?php

namespace App\Models;

use App\BillingPeriodStatus;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'client_id',
    'accrual_id',
    'billing_period_id',
    'receipt_number',
    'account_number',
    'client_name',
    'utility_service_name',
    'billing_type',
    'volume',
    'tariff_price',
    'amount',
    'paid_amount',
    'adjustment_amount',
    'opening_balance',
    'closing_balance',
    'issued_at',
    'period',
])]
class Receipt extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'paid_amount' => 0,
        'adjustment_amount' => 0,
        'opening_balance' => 0,
        'closing_balance' => 0,
    ];

    public static function fromAccrual(Accrual $accrual): self
    {
        return self::query()->firstOrCreate(
            [
                'organization_id' => $accrual->organization_id,
                'client_id' => $accrual->client_id,
                'billing_period_id' => $accrual->billing_period_id,
            ],
            [
                'accrual_id' => $accrual->id,
                'receipt_number' => self::receiptNumber($accrual),
                'account_number' => $accrual->account_number,
                'client_name' => $accrual->client_name,
                'utility_service_name' => $accrual->utility_service_name,
                'billing_type' => $accrual->billing_type,
                'volume' => $accrual->volume,
                'tariff_price' => $accrual->tariff_price,
                'amount' => $accrual->amount,
                'paid_amount' => $accrual->paid_amount,
                'adjustment_amount' => $accrual->adjustment_amount,
                'opening_balance' => $accrual->opening_balance,
                'closing_balance' => $accrual->closing_balance,
                'issued_at' => now(),
            ],
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function accrual(): BelongsTo
    {
        return $this->belongsTo(Accrual::class);
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
            'adjustment_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    private static function receiptNumber(Accrual $accrual): string
    {
        return "{$accrual->period}-{$accrual->account_number}";
    }

    protected static function booted(): void
    {
        static::saving(function (Receipt $receipt): void {
            $receipt->resolveBillingPeriodIdFromPeriodCode(BillingPeriodStatus::Closed);
        });
    }
}
