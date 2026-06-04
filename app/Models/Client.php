<?php

namespace App\Models;

use App\ClientType;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'organization_id',
    'utility_service_id',
    'account_number',
    'name',
    'iin',
    'client_type',
    'phone',
    'contract',
    'technical_conditions',
    'region_id',
    'street_id',
    'house',
    'apartment',
    'status',
    'billing_type',
    'residents_count',
    'fixed_amount',
    'note',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const FIRST_AUTOMATIC_ACCOUNT_NUMBER = 100001;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'client_type' => 'individual',
        'status' => 'active',
        'billing_type' => 'per_person',
        'residents_count' => 1,
        'fixed_amount' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function accruals(): HasMany
    {
        return $this->hasMany(Accrual::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceAdjustments(): HasMany
    {
        return $this->hasMany(BalanceAdjustment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->exists && $client->isDirty('account_number')) {
                $client->account_number = $client->getOriginal('account_number');
            }

            if (! $client->organization_id) {
                return;
            }

            if (! $client->exists && blank($client->account_number)) {
                $client->account_number = self::nextAccountNumberFor($client->organization_id);
            }

            $client->utility_service_id = UtilityService::query()
                ->where('organization_id', $client->organization_id)
                ->value('id');

            self::advanceNextAccountNumber($client);
        });
    }

    private static function nextAccountNumberFor(int $organizationId): string
    {
        return DB::transaction(function () use ($organizationId): string {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            $accountNumber = max(
                self::FIRST_AUTOMATIC_ACCOUNT_NUMBER,
                (int) $organization->next_client_account_number,
            );

            $organization->forceFill([
                'next_client_account_number' => $accountNumber + 1,
            ])->save();

            return (string) $accountNumber;
        }, attempts: 5);
    }

    private static function advanceNextAccountNumber(Client $client): void
    {
        if (blank($client->account_number) || ! ctype_digit((string) $client->account_number)) {
            return;
        }

        $nextAccountNumber = ((int) $client->account_number) + 1;

        if ($nextAccountNumber <= self::FIRST_AUTOMATIC_ACCOUNT_NUMBER) {
            return;
        }

        Organization::query()
            ->whereKey($client->organization_id)
            ->where('next_client_account_number', '<', $nextAccountNumber)
            ->update([
                'next_client_account_number' => $nextAccountNumber,
            ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'residents_count' => 'integer',
            'fixed_amount' => 'decimal:2',
        ];
    }
}
