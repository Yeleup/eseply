<?php

namespace App\Models;

use App\PaymentMethod;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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
     * Provider of the payments the Kaspi integration posts.
     */
    public const string KASPI_PROVIDER = 'kaspi';

    public const string INTEGRATION_LOCKED_MESSAGE = 'Оплату из интеграции нельзя изменить или удалить: ошибку исправляют корректировкой сальдо.';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'method' => 'cash',
    ];

    /**
     * A payment from an integration mirrors the provider's record, so nobody
     * changes or deletes it here: the two records would no longer match.
     */
    public function isFromIntegration(): bool
    {
        return filled($this->external_provider) || filled($this->external_payment_id);
    }

    /**
     * Short note under the payment method in the payment tables: the Kaspi
     * number of an integration payment, or that an old Kaspi payment was
     * entered by hand before the integration.
     */
    public function methodNote(): ?string
    {
        if ($this->isFromIntegration()) {
            return $this->external_provider === self::KASPI_PROVIDER
                ? '№ Kaspi '.$this->external_payment_id
                : 'из интеграции, № '.$this->external_payment_id;
        }

        return $this->method === PaymentMethod::Kaspi ? 'внесена вручную' : null;
    }

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

        static::updating(function (Payment $payment): void {
            $payment->ensureNotFromIntegration();

            if ($payment->isDirty(['external_provider', 'external_payment_id'])) {
                throw ValidationException::withMessages(['payment' => self::INTEGRATION_LOCKED_MESSAGE]);
            }
        });

        static::deleting(function (Payment $payment): void {
            $payment->ensureNotFromIntegration();
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

    /**
     * Checked against the stored values: clearing the provider fields in the
     * same save must not unlock an integration payment.
     */
    private function ensureNotFromIntegration(): void
    {
        if (blank($this->getOriginal('external_provider')) && blank($this->getOriginal('external_payment_id'))) {
            return;
        }

        throw ValidationException::withMessages(['payment' => self::INTEGRATION_LOCKED_MESSAGE]);
    }
}
