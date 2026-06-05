<?php

namespace App\Filament\Resources\Meters\Pages;

use App\Filament\Resources\Meters\MeterResource;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMeter extends CreateRecord
{
    protected static string $resource = MeterResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        $client = Client::query()->find($data['client_id'] ?? null);

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && $client instanceof Client
                && OrganizationMemberAccess::canCreateMeters()
                && $user->canAccessClientInOrganization($client, $tenant),
            403,
        );

        $data['utility_service_id'] = $tenant->utilityService?->getKey();

        return $data;
    }
}
