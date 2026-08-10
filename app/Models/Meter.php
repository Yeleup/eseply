<?php

namespace App\Models;

use App\Support\MeterReadingPhotoStorage;
use Database\Factories\MeterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id',
    'client_id',
    'utility_service_id',
    'number',
    'installed_on',
    'initial_reading',
    'note',
])]
class Meter extends Model
{
    /** @use HasFactory<MeterFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'initial_reading' => 0,
        'status' => 'active',
    ];

    /**
     * @var array<int, string>
     */
    private array $deletedReadingPhotoPaths = [];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function utilityService(): BelongsTo
    {
        return $this->belongsTo(UtilityService::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * @param  array<int, string>  $photoPaths
     */
    public function rememberDeletedReadingPhotoPaths(array $photoPaths): void
    {
        $this->deletedReadingPhotoPaths = $photoPaths;
    }

    public function flushDeletedReadingPhotoPaths(): void
    {
        MeterReadingPhotoStorage::deleteMany($this->deletedReadingPhotoPaths);
        $this->deletedReadingPhotoPaths = [];
    }

    public function archive(): void
    {
        $this->forceFill([
            'removed_on' => today(),
            'status' => 'removed',
        ])->save();
    }

    public function restoreFromArchive(): void
    {
        $this->forceFill([
            'removed_on' => null,
            'status' => 'active',
        ])->save();
    }

    public function isArchived(): bool
    {
        return $this->status === 'removed' || $this->removed_on !== null;
    }

    /**
     * @param  Builder<Meter>  $query
     * @return Builder<Meter>
     */
    public function scopeVisibleToOrganizationMember(Builder $query, User $user, Organization|int|string|null $organization): Builder
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where($query->getModel()->qualifyColumn('organization_id'), $organizationId);

        if ($user->isOrganizationOperator($organizationId)) {
            return $query;
        }

        if (! $user->isOrganizationController($organizationId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'client',
            fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organizationId),
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Meter $meter): void {
            if ($meter->exists && $meter->isDirty('initial_reading')) {
                $meter->initial_reading = $meter->getOriginal('initial_reading');
            }

            $meter->initial_reading = MeterReading::wholeReading($meter->getAttributes()['initial_reading'] ?? null) ?? 0;

            if ($meter->organization_id) {
                $meter->utility_service_id = UtilityService::query()
                    ->where('organization_id', $meter->organization_id)
                    ->value('id');
            }
        });

        static::deleting(function (Meter $meter): void {
            $photoPaths = [];

            $meter->readings()
                ->whereNotNull('photo_path')
                ->select(['id', 'photo_path'])
                ->chunkById(500, function (Collection $readings) use (&$photoPaths): void {
                    foreach ($readings as $reading) {
                        $photoPaths[] = $reading->photo_path;
                    }
                });

            $meter->rememberDeletedReadingPhotoPaths($photoPaths);
        });

        static::deleted(function (Meter $meter): void {
            $meter->flushDeletedReadingPhotoPaths();
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
            'installed_on' => 'date',
            'initial_reading' => 'numeric',
            'removed_on' => 'date',
        ];
    }

    private static function organizationId(Organization|int|string|null $organization): ?int
    {
        if ($organization instanceof Organization) {
            return (int) $organization->getKey();
        }

        if (is_int($organization)) {
            return $organization;
        }

        if (is_string($organization) && ctype_digit($organization)) {
            return (int) $organization;
        }

        return null;
    }
}
