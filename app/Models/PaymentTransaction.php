<?php

namespace App\Models;

use App\PaymentTransactionProvider;
use App\PaymentTransactionStatus;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'client_id',
    'billing_period_id',
    'payment_id',
    'provider',
    'merchant_order_id',
    'idempotency_key',
    'external_payment_id',
    'amount',
    'status',
    'qr_url',
    'payer_phone',
    'expires_at',
    'completed_at',
    'failed_at',
    'cancelled_at',
    'raw_payload',
    'note',
    'period',
])]
class PaymentTransaction extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'xpayment',
        'status' => 'pending',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === PaymentTransactionStatus::Pending;
    }

    public function hasFinalStatus(): bool
    {
        return $this->status instanceof PaymentTransactionStatus
            && $this->status->isFinal();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentTransactionProvider::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $paymentTransaction): void {
            $paymentTransaction->resolveBillingPeriodIdFromPeriodCode(useCurrentWhenMissing: true);
            $paymentTransaction->ensureBillingPeriodIsEditable();
        });

        static::saving(function (PaymentTransaction $paymentTransaction): void {
            $paymentTransaction->assertBillingPeriodBelongsToOrganization();
        });
    }
}
