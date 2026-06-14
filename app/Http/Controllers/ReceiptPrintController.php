<?php

namespace App\Http\Controllers;

use App\Actions\BuildReceiptPrintViewData;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class ReceiptPrintController extends Controller
{
    public function __invoke(string $tenantKey, Receipt $receipt, BuildReceiptPrintViewData $buildReceiptPrintViewData): Response
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless(
            $tenant instanceof Organization
                && $user instanceof User
                && (string) $tenant->getRouteKey() === $tenantKey
                && (int) $receipt->organization_id === (int) $tenant->getKey()
                && $user->canManageOrganization($tenant),
            404,
        );

        return response()
            ->view('receipts.print', $buildReceiptPrintViewData->handle($receipt), 200)
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
