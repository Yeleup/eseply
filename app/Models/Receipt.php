<?php

namespace App\Models;

use App\ClientType;
use Carbon\CarbonImmutable;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

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

    public static function fromMeterReading(MeterReading $meterReading): self
    {
        $meterReading->loadMissing([
            'billingPeriod',
            'client',
            'organization.utilityService',
            'utilityService',
        ]);

        $client = $meterReading->client;
        $billingPeriod = $meterReading->billingPeriod;

        if (! $client || ! $billingPeriod) {
            throw new InvalidArgumentException('Нельзя сформировать квитанцию без абонента и расчётного месяца.');
        }

        $volume = self::meterReadingVolume($meterReading);
        $tariff = self::activeMeterTariff($client, $meterReading, $billingPeriod);
        $tariffPrice = $tariff?->unit_price === null ? null : (float) $tariff->unit_price;
        $amount = $tariffPrice === null ? 0.0 : round($volume * $tariffPrice, 2);
        $paidAmount = self::paidAmount($client, $billingPeriod);
        $adjustmentAmount = self::adjustmentAmount($client, $billingPeriod);
        $openingBalance = self::openingBalance($client, $billingPeriod);

        $receipt = self::query()->firstOrNew([
            'organization_id' => $meterReading->organization_id,
            'client_id' => $client->id,
            'billing_period_id' => $billingPeriod->id,
        ]);

        $receipt->fill([
            'accrual_id' => null,
            'receipt_number' => self::receiptNumber($billingPeriod, $client),
            'account_number' => $client->account_number,
            'client_name' => $client->name,
            'utility_service_name' => $meterReading->utilityService?->name
                ?? $meterReading->organization?->utilityService?->name,
            'billing_type' => $client->billing_type,
            'volume' => $volume,
            'tariff_price' => $tariffPrice,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'adjustment_amount' => $adjustmentAmount,
            'opening_balance' => $openingBalance,
            'closing_balance' => $openingBalance + $amount - $paidAmount + $adjustmentAmount,
            'issued_at' => now(),
        ]);

        $receipt->save();

        return $receipt;
    }

    public static function refreshPaymentTotalsFor(
        int|string|null $organizationId,
        int|string|null $clientId,
        int|string|null $billingPeriodId,
    ): ?self {
        if (! $organizationId || ! $clientId || ! $billingPeriodId) {
            return null;
        }

        $receipt = self::query()
            ->where('organization_id', (int) $organizationId)
            ->where('client_id', (int) $clientId)
            ->where('billing_period_id', (int) $billingPeriodId)
            ->first();

        if (! $receipt) {
            return null;
        }

        $paidAmount = self::paidAmountFor(
            (int) $organizationId,
            (int) $clientId,
            (int) $billingPeriodId,
        );

        $receipt->fill([
            'paid_amount' => $paidAmount,
            'closing_balance' => (float) $receipt->opening_balance
                + (float) $receipt->amount
                - $paidAmount
                + (float) $receipt->adjustment_amount,
            'issued_at' => now(),
        ]);

        $receipt->save();

        return $receipt;
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

    private static function receiptNumber(BillingPeriod $billingPeriod, Client $client): string
    {
        return "{$billingPeriod->code}-{$client->account_number}";
    }

    private static function meterReadingVolume(MeterReading $meterReading): float
    {
        return (float) MeterReading::query()
            ->where('organization_id', $meterReading->organization_id)
            ->where('client_id', $meterReading->client_id)
            ->where('billing_period_id', $meterReading->billing_period_id)
            ->sum('consumption');
    }

    private static function activeMeterTariff(Client $client, MeterReading $meterReading, BillingPeriod $billingPeriod): ?Tariff
    {
        $utilityServiceId = $meterReading->utility_service_id
            ?? $meterReading->organization?->utilityService?->id;

        if (! $utilityServiceId) {
            return null;
        }

        return Tariff::query()
            ->where('organization_id', $client->organization_id)
            ->where('utility_service_id', $utilityServiceId)
            ->where('client_type', self::clientTypeValue($client))
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', CarbonImmutable::instance($billingPeriod->starts_on)->startOfMonth()->toDateString())
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();
    }

    private static function paidAmount(Client $client, BillingPeriod $billingPeriod): float
    {
        return self::paidAmountFor(
            (int) $client->organization_id,
            (int) $client->getKey(),
            (int) $billingPeriod->getKey(),
        );
    }

    private static function paidAmountFor(int $organizationId, int $clientId, int $billingPeriodId): float
    {
        return (float) Payment::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->where('billing_period_id', $billingPeriodId)
            ->sum('amount');
    }

    private static function adjustmentAmount(Client $client, BillingPeriod $billingPeriod): float
    {
        return (float) $client->balanceAdjustments()
            ->whereBelongsTo($billingPeriod)
            ->sum('amount');
    }

    private static function openingBalance(Client $client, BillingPeriod $billingPeriod): float
    {
        $previousReceipt = $client->receipts()
            ->beforePeriod($billingPeriod->code)
            ->orderByBillingPeriodDesc()
            ->first();

        if ($previousReceipt) {
            return (float) $previousReceipt->closing_balance;
        }

        return 0.0;
    }

    private static function clientTypeValue(Client $client): string
    {
        if ($client->client_type instanceof ClientType) {
            return $client->client_type->value;
        }

        return (string) $client->client_type;
    }

    protected static function booted(): void
    {
        static::saving(function (Receipt $receipt): void {
            $receipt->resolveBillingPeriodIdFromPeriodCode();
        });
    }
}
