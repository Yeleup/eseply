<?php

namespace App\Models;

use App\PaymentMethod;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id',
    'client_id',
    'billing_period_id',
    'method',
    'external_provider',
    'external_payment_id',
    'received_by_user_id',
    'amount',
    'paid_at',
    'note',
    'period',
])]
class Payment extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'method' => 'cash',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function paymentTransaction(): HasOne
    {
        return $this->hasOne(PaymentTransaction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Payment $payment): void {
            $payment->resolveBillingPeriodIdFromPeriodCode(useCurrentWhenMissing: true);
            $payment->ensureBillingPeriodIsEditable();
        });

        static::deleting(function (Payment $payment): void {
            $payment->ensureBillingPeriodIsEditable();
        });

        static::saved(function (Payment $payment): void {
            Receipt::refreshPaymentTotalsFor(
                $payment->organization_id,
                $payment->client_id,
                $payment->billing_period_id,
            );

            if (! $payment->wasChanged(['organization_id', 'client_id', 'billing_period_id'])) {
                return;
            }

            Receipt::refreshPaymentTotalsFor(
                $payment->getOriginal('organization_id'),
                $payment->getOriginal('client_id'),
                $payment->getOriginal('billing_period_id'),
            );
        });

        static::deleted(function (Payment $payment): void {
            Receipt::refreshPaymentTotalsFor(
                $payment->organization_id,
                $payment->client_id,
                $payment->billing_period_id,
            );
        });
    }
}
