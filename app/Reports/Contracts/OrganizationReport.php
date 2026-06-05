<?php

namespace App\Reports\Contracts;

use App\Models\Organization;
use App\Models\User;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface OrganizationReport
{
    public function slug(): string;

    public function title(): string;

    public function description(): ?string;

    public function table(Table $table, Organization $organization, User $user): Table;

    public function downloadExcel(Organization $organization, User $user): StreamedResponse;
}
