<?php

namespace App\Models;

use App\BalanceAdjustmentType;
use Database\Factories\BalanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'organization_id',
    'client_id',
    'billing_period_id',
    'type',
    'amount',
    'adjusted_at',
    'note',
    'period',
])]
class BalanceAdjustment extends Model
{
    use HasBillingPeriod;

    /** @use HasFactory<BalanceAdjustmentFactory> */
    use HasFactory;

    public const string OPENING_BALANCE_LOCKED_MESSAGE = 'Входящий остаток можно ввести, только пока у абонента нет начислений.';

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

    protected static function booted(): void
    {
        static::saving(function (BalanceAdjustment $balanceAdjustment): void {
            $balanceAdjustment->resolveBillingPeriodIdFromPeriodCode(useCurrentWhenMissing: true);
            $balanceAdjustment->ensureBillingPeriodIsEditable();
            $balanceAdjustment->ensureOpeningBalanceHasNoAccruals();
        });

        static::deleting(function (BalanceAdjustment $balanceAdjustment): void {
            $balanceAdjustment->ensureBillingPeriodIsEditable();
        });
    }

    /**
     * У абонента с начислениями входящий остаток уже нельзя завести.
     */
    public static function openingBalanceLockedFor(int|string|null $clientId): bool
    {
        if (! $clientId) {
            return false;
        }

        return Accrual::query()
            ->where('client_id', $clientId)
            ->exists();
    }

    /**
     * Входящий остаток — разовый ввод стартового сальдо лицевого счёта, поэтому
     * он допустим, только пока у абонента нет ни одного начисления. Существующая
     * строка входящего остатка остаётся редактируемой: правило проверяется при
     * создании и при смене типа или абонента.
     */
    protected function ensureOpeningBalanceHasNoAccruals(): void
    {
        if ($this->type !== BalanceAdjustmentType::OpeningBalance) {
            return;
        }

        if ($this->exists && ! $this->isDirty(['type', 'client_id'])) {
            return;
        }

        if (self::openingBalanceLockedFor($this->client_id)) {
            throw ValidationException::withMessages([
                'type' => self::OPENING_BALANCE_LOCKED_MESSAGE,
            ]);
        }
    }
}
