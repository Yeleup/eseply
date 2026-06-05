<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\OrganizationMemberAccess;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(OrganizationMemberAccess::canCreateClients(), 403);

        $tenant = Filament::getTenant();

        $data['organization_id'] = $tenant?->getKey();
        $data['utility_service_id'] = $tenant?->utilityService?->getKey();

        return $data;
    }
}
