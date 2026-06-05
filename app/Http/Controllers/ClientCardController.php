<?php

namespace App\Http\Controllers;

use App\Actions\BuildClientCardViewData;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class ClientCardController extends Controller
{
    public function __invoke(string $tenantKey, Client $client, BuildClientCardViewData $buildClientCardViewData): Response
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && (string) $tenant->getRouteKey() === $tenantKey
                && $user->canAccessClientInOrganization($client, $tenant),
            404,
        );

        return response()
            ->view('clients.card', $buildClientCardViewData->handle($client), 200)
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
