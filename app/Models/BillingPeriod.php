<?php

namespace App\Models;

use App\BillingPeriodStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BillingPeriodFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

#[Fillable([
    'organization_id',
    'starts_on',
    'status',
    'opened_at',
    'opened_by_user_id',
    'processing_started_at',
    'closed_at',
    'closed_by_user_id',
    'failed_at',
    'failure_message',
    'active_clients_count',
    'created_accruals_count',
    'skipped_accruals_count',
    'failed_clients_count',
    'period',
])]
class BillingPeriod extends Model
{
    /** @use HasFactory<BillingPeriodFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'active_clients_count' => 0,
        'created_accruals_count' => 0,
        'skipped_accruals_count' => 0,
        'failed_clients_count' => 0,
    ];

    public static function openFor(
        Organization|int $organization,
        string $period,
        User|int|null $openedBy = null,
        BillingPeriodStatus $status = BillingPeriodStatus::Open,
    ): self {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $startsOn = self::periodStart($period);
        $openedById = $openedBy instanceof User ? (int) $openedBy->getKey() : $openedBy;

        return self::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'starts_on' => $startsOn->toDateString(),
            ],
            [
                'status' => $status,
                'opened_at' => now(),
                'opened_by_user_id' => $openedById,
                'closed_at' => $status === BillingPeriodStatus::Closed ? now() : null,
            ],
        );
    }

    public static function periodStart(string|DateTimeInterface $period): CarbonImmutable
    {
        if ($period instanceof DateTimeInterface) {
            return CarbonImmutable::instance($period)->startOfMonth();
        }

        if (! preg_match('/^\d{6}$/', $period)) {
            throw new InvalidArgumentException('Расчётный месяц должен быть в формате ГГГГММ.');
        }

        $month = (int) substr($period, 4, 2);

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Месяц в расчётном месяце должен быть от 01 до 12.');
        }

        return CarbonImmutable::createFromFormat('Ym', $period)->startOfMonth();
    }

    public static function normalizeCode(string|DateTimeInterface $period): string
    {
        return self::periodStart($period)->format('Ym');
    }

    public static function currentEditableFor(Organization|int $organization): ?self
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return self::query()
            ->forOrganization($organizationId)
            ->whereIn('status', [
                BillingPeriodStatus::Open->value,
                BillingPeriodStatus::Failed->value,
            ])
            ->orderByDesc('starts_on')
            ->first();
    }

    public static function requireCurrentEditableFor(Organization|int $organization): self
    {
        $billingPeriod = self::currentEditableFor($organization);

        if (! $billingPeriod) {
            throw ValidationException::withMessages([
                'billing_period_id' => 'Нет открытого расчётного месяца. Откройте месяц в разделе «Расчётные месяцы».',
            ]);
        }

        return $billingPeriod;
    }

    public static function openNextFor(Organization|int $organization, User|int|null $openedBy = null): self
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        $latestBillingPeriod = self::query()
            ->forOrganization($organizationId)
            ->orderByDesc('starts_on')
            ->first();

        if (! $latestBillingPeriod) {
            return self::openFor($organizationId, now()->format('Ym'), $openedBy);
        }

        if ($latestBillingPeriod->status !== BillingPeriodStatus::Closed) {
            throw ValidationException::withMessages([
                'period' => 'Предыдущий расчётный месяц должен быть закрыт перед открытием нового.',
            ]);
        }

        $nextPeriod = self::periodStart($latestBillingPeriod->starts_on)
            ->addMonth()
            ->format('Ym');

        return self::openFor($organizationId, $nextPeriod, $openedBy);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(BalanceAdjustment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function closureErrors(): HasMany
    {
        return $this->hasMany(BillingPeriodClosureError::class);
    }

    public function getCodeAttribute(): string
    {
        return $this->starts_on->format('Ym');
    }

    public function getPeriodAttribute(): string
    {
        return $this->code;
    }

    public function setPeriodAttribute(mixed $period): void
    {
        if ($period === null || $period === '') {
            return;
        }

        $this->setAttribute('starts_on', self::periodStart((string) $period)->toDateString());
    }

    public function getLabelAttribute(): string
    {
        return $this->starts_on->format('m.Y');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function canStartClosing(): bool
    {
        return $this->status->canStartClosing();
    }

    public function assertCanBeCreatedAfterLatestPeriod(): void
    {
        $organizationId = $this->getAttribute('organization_id');

        if (! $organizationId) {
            return;
        }

        $startsOnValue = $this->getAttribute('starts_on');

        if (! $startsOnValue) {
            return;
        }

        $startsOn = self::periodStart($startsOnValue);

        $latestBillingPeriod = self::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('starts_on')
            ->first();

        if (! $latestBillingPeriod) {
            return;
        }

        if ($latestBillingPeriod->status !== BillingPeriodStatus::Closed) {
            throw ValidationException::withMessages([
                'period' => 'Предыдущий расчётный месяц должен быть закрыт перед открытием нового.',
            ]);
        }

        $expectedStartsOn = self::periodStart($latestBillingPeriod->starts_on)->addMonth();

        if (! $startsOn->equalTo($expectedStartsOn)) {
            throw ValidationException::withMessages([
                'period' => 'Новый расчётный месяц должен идти сразу после последнего расчётного месяца.',
            ]);
        }
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => BillingPeriodStatus::Processing,
            'processing_started_at' => now(),
            'failed_at' => null,
            'failure_message' => null,
        ])->save();

        $this->closureErrors()->delete();
    }

    /**
     * @param  array{active:int, created:int, skipped:int, failed:int}  $summary
     */
    public function markClosed(array $summary, User|int|null $closedBy = null): void
    {
        $closedById = $closedBy instanceof User ? (int) $closedBy->getKey() : $closedBy;

        $this->forceFill([
            'status' => BillingPeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by_user_id' => $closedById,
            'failed_at' => null,
            'failure_message' => null,
            'active_clients_count' => $summary['active'],
            'created_accruals_count' => $summary['created'],
            'skipped_accruals_count' => $summary['skipped'],
            'failed_clients_count' => $summary['failed'],
        ])->save();

        $this->closureErrors()->delete();
    }

    /**
     * @param  array{active:int, created:int, skipped:int, failed:int}  $summary
     * @param  list<array{client_id:int|null, account_number:string|null, client_name:string|null, billing_type:string|null, code:string, message:string, context:array<string, mixed>|null}>  $errors
     */
    public function markFailed(array $summary, string $message, array $errors = []): void
    {
        $this->forceFill([
            'status' => BillingPeriodStatus::Failed,
            'failed_at' => now(),
            'failure_message' => $message,
            'active_clients_count' => $summary['active'],
            'created_accruals_count' => $summary['created'],
            'skipped_accruals_count' => $summary['skipped'],
            'failed_clients_count' => $summary['failed'],
        ])->save();

        $this->closureErrors()->delete();

        if ($errors === []) {
            return;
        }

        $this->closureErrors()->createMany(
            array_map(fn (array $error): array => [
                'organization_id' => $this->organization_id,
                'client_id' => $error['client_id'],
                'account_number' => $error['account_number'],
                'client_name' => $error['client_name'],
                'billing_type' => $error['billing_type'],
                'code' => $error['code'],
                'message' => $error['message'],
                'context' => $error['context'],
            ], $errors)
        );
    }

    /**
     * @param  Builder<BillingPeriod>  $query
     * @return Builder<BillingPeriod>
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? (int) $organization->getKey() : $organization;

        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<BillingPeriod>  $query
     * @return Builder<BillingPeriod>
     */
    public function scopeForCode(Builder $query, string $period): Builder
    {
        return $query->whereDate('starts_on', self::periodStart($period)->toDateString());
    }

    protected static function booted(): void
    {
        static::creating(function (BillingPeriod $billingPeriod): void {
            $billingPeriod->assertCanBeCreatedAfterLatestPeriod();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'status' => BillingPeriodStatus::class,
            'opened_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'failed_at' => 'datetime',
            'active_clients_count' => 'integer',
            'created_accruals_count' => 'integer',
            'skipped_accruals_count' => 'integer',
            'failed_clients_count' => 'integer',
        ];
    }
}
