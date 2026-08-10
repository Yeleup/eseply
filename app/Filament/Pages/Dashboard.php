<?php

namespace App\Filament\Pages;

use App\Filament\Support\DashboardBillingPeriod;
use App\Models\Organization;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Дашборд';

    protected static ?string $navigationLabel = 'Дашборд';

    protected static ?int $navigationSort = -100;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('billing_period_id')
                ->label('Расчётный месяц')
                ->options(fn (): array => $this->billingPeriodOptions())
                ->default(fn (): ?int => $this->defaultBillingPeriodId())
                ->selectablePlaceholder(false)
                ->native(false),
        ]);
    }

    /**
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return 4;
    }

    /**
     * @return array<int, string>
     */
    private function billingPeriodOptions(): array
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            ? DashboardBillingPeriod::options($tenant)
            : [];
    }

    private function defaultBillingPeriodId(): ?int
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return null;
        }

        $billingPeriod = DashboardBillingPeriod::default($tenant);

        return $billingPeriod === null ? null : (int) $billingPeriod->getKey();
    }
}
