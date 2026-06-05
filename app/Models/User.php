<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\OrganizationMemberRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->organizations()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization
            && $this->organizations()->whereKey($tenant->getKey())->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }

    public function organizationRole(Organization|int|string|null $organization): ?OrganizationMemberRole
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null) {
            return null;
        }

        $role = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->getKey())
            ->value('role');

        return is_string($role) ? OrganizationMemberRole::tryFrom($role) : null;
    }

    public function isOrganizationOperator(Organization|int|string|null $organization): bool
    {
        return $this->organizationRole($organization) === OrganizationMemberRole::Operator;
    }

    public function isOrganizationController(Organization|int|string|null $organization): bool
    {
        return $this->organizationRole($organization) === OrganizationMemberRole::Controller;
    }

    public function canManageOrganization(Organization|int|string|null $organization): bool
    {
        return $this->isOrganizationOperator($organization);
    }

    public function canCreateClientsInOrganization(Organization|int|string|null $organization): bool
    {
        return $this->canManageOrganization($organization);
    }

    public function canUpdateClientInOrganization(Client $client, Organization|int|string|null $organization): bool
    {
        return $this->canManageOrganization($organization)
            && $this->canAccessClientInOrganization($client, $organization);
    }

    public function canDeleteClientInOrganization(Client $client, Organization|int|string|null $organization): bool
    {
        return $this->canUpdateClientInOrganization($client, $organization);
    }

    public function canCreateMetersInOrganization(Organization|int|string|null $organization): bool
    {
        return $this->canManageOrganization($organization);
    }

    public function canUpdateMeterInOrganization(Meter $meter, Organization|int|string|null $organization): bool
    {
        return $this->canManageOrganization($organization)
            && $this->canAccessMeterInOrganization($meter, $organization);
    }

    public function canDeleteMeterInOrganization(Meter $meter, Organization|int|string|null $organization): bool
    {
        return $this->canUpdateMeterInOrganization($meter, $organization);
    }

    public function canCreateMeterReadingForMeterInOrganization(Meter $meter, Organization|int|string|null $organization): bool
    {
        return ($this->isOrganizationOperator($organization) || $this->isOrganizationController($organization))
            && $this->canAccessMeterInOrganization($meter, $organization);
    }

    public function canUpdateMeterReadingInOrganization(MeterReading $meterReading, Organization|int|string|null $organization): bool
    {
        return ($this->isOrganizationOperator($organization) || $this->isOrganizationController($organization))
            && $this->canAccessMeterReadingInOrganization($meterReading, $organization);
    }

    public function canDeleteMeterReadingInOrganization(MeterReading $meterReading, Organization|int|string|null $organization): bool
    {
        return $this->isOrganizationOperator($organization)
            && $this->canAccessMeterReadingInOrganization($meterReading, $organization);
    }

    public function canAccessClientInOrganization(Client $client, Organization|int|string|null $organization): bool
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null || (int) $client->organization_id !== $organizationId) {
            return false;
        }

        if ($this->isOrganizationOperator($organizationId)) {
            return true;
        }

        if (! $this->isOrganizationController($organizationId)) {
            return false;
        }

        $regionIds = $this->assignedRegionIdsForOrganization($organizationId);
        $streetIds = $this->assignedStreetIdsForOrganization($organizationId);

        return ($client->region_id !== null && in_array((int) $client->region_id, $regionIds, true))
            || ($client->street_id !== null && in_array((int) $client->street_id, $streetIds, true));
    }

    public function canAccessMeterInOrganization(Meter $meter, Organization|int|string|null $organization): bool
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null || (int) $meter->organization_id !== $organizationId) {
            return false;
        }

        if ($this->isOrganizationOperator($organizationId)) {
            return true;
        }

        $meter->loadMissing('client');

        return $meter->client instanceof Client
            && $this->canAccessClientInOrganization($meter->client, $organizationId);
    }

    public function canAccessMeterReadingInOrganization(MeterReading $meterReading, Organization|int|string|null $organization): bool
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null || (int) $meterReading->organization_id !== $organizationId) {
            return false;
        }

        if ($this->isOrganizationOperator($organizationId)) {
            return true;
        }

        $meterReading->loadMissing('client');

        return $meterReading->client instanceof Client
            && $this->canAccessClientInOrganization($meterReading->client, $organizationId);
    }

    /**
     * @return list<int>
     */
    public function assignedRegionIdsForOrganization(Organization|int|string|null $organization): array
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null) {
            return [];
        }

        return DB::table('organization_user_regions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->getKey())
            ->pluck('region_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function assignedStreetIdsForOrganization(Organization|int|string|null $organization): array
    {
        $organizationId = self::organizationId($organization);

        if ($organizationId === null) {
            return [];
        }

        return DB::table('organization_user_streets')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->getKey())
            ->pluck('street_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
