<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Filament\Resources\BalanceAdjustments\Pages\CreateBalanceAdjustment;
use App\Filament\Resources\BalanceAdjustments\Pages\ListBalanceAdjustments;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Support\CurrentBillingPeriod;
use App\Http\Controllers\ClientCardController;
use App\Http\Controllers\ReceiptPrintController;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->tenant(Organization::class)
            ->tenantRegistration(RegisterOrganization::class)
            ->tenantProfile(EditOrganizationProfile::class)
            ->searchableTenantMenu()
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                function (): View {
                    $tenant = Filament::getTenant();

                    return view('filament.billing-period-required-callout', [
                        'billingPeriod' => $tenant instanceof Organization
                            ? CurrentBillingPeriod::get($tenant)
                            : null,
                        'tenant' => $tenant,
                    ]);
                },
                scopes: [
                    CreateBalanceAdjustment::class,
                    CreateMeterReading::class,
                    CreatePayment::class,
                    EditClient::class,
                    EditMeter::class,
                    ListAccruals::class,
                    ListBalanceAdjustments::class,
                    ListMeterReadings::class,
                    ListPayments::class,
                ],
            )
            ->authenticatedTenantRoutes(function (): void {
                Route::get('/', function (): RedirectResponse {
                    return redirect()->to(ClientResource::getUrl(panel: 'admin', tenant: Filament::getTenant()));
                })->name('home');

                Route::get('/clients/{client}/card', ClientCardController::class)
                    ->name('clients.card');
                Route::get('/receipts/{receipt}/print', [ReceiptPrintController::class, 'single'])
                    ->name('receipts.print');
                Route::get('/receipts/print-bulk', [ReceiptPrintController::class, 'bulk'])
                    ->name('receipts.print-bulk');
            })
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
