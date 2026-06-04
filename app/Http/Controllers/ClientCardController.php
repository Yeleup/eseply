<?php

namespace App\Http\Controllers;

use App\Actions\BuildClientCardViewData;
use App\Models\Client;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class ClientCardController extends Controller
{
    public function __invoke(string $tenantKey, Client $client, BuildClientCardViewData $buildClientCardViewData): Response
    {
        $tenant = Filament::getTenant();

        abort_unless(
            $tenant
                && (string) $tenant->getRouteKey() === $tenantKey
                && (int) $client->organization_id === (int) $tenant->getKey(),
            404,
        );

        return response()
            ->view('clients.card', $buildClientCardViewData->handle($client), 200)
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
