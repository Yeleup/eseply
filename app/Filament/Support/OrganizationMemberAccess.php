<?php

namespace App\Filament\Support;

use App\Models\Client;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

final class OrganizationMemberAccess
{
    public static function tenant(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canAccessTenant(): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->organizationRole($tenant) !== null;
    }

    public static function canManageTenant(): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canManageOrganization($tenant);
    }

    public static function canViewClient(Client $client): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessClientInOrganization($client, $tenant);
    }

    public static function canCreateClients(): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canCreateClientsInOrganization($tenant);
    }

    public static function canManageClient(Client $client): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canUpdateClientInOrganization($client, $tenant);
    }

    public static function canDeleteClient(Client $client): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canDeleteClientInOrganization($client, $tenant);
    }

    public static function canViewMeter(Meter $meter): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessMeterInOrganization($meter, $tenant);
    }

    public static function canCreateMeters(): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canCreateMetersInOrganization($tenant);
    }

    public static function canManageMeter(Meter $meter): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canUpdateMeterInOrganization($meter, $tenant);
    }

    public static function canDeleteMeter(Meter $meter): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canDeleteMeterInOrganization($meter, $tenant);
    }

    public static function canViewMeterReading(MeterReading $meterReading): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessMeterReadingInOrganization($meterReading, $tenant);
    }

    public static function canCreateMeterReadingForMeter(Meter $meter): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canCreateMeterReadingForMeterInOrganization($meter, $tenant);
    }

    public static function canUpdateMeterReading(MeterReading $meterReading): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canUpdateMeterReadingInOrganization($meterReading, $tenant);
    }

    public static function canDeleteMeterReading(MeterReading $meterReading): bool
    {
        $tenant = self::tenant();
        $user = self::user();

        return $tenant instanceof Organization
            && $user instanceof User
            && $user->canDeleteMeterReadingInOrganization($meterReading, $tenant);
    }
}
