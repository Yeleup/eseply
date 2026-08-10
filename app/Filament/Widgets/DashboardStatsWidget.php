<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Абоненты и снятие показаний';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    /**
     * @return int|array<string, ?int>
     */
    protected function getColumns(): int|array
    {
        return 4;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $organization = Filament::getTenant();
        $user = auth()->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $metrics = app(DashboardMetrics::class)->operations($organization, $billingPeriod, $user);
        $unit = $organization->utilityService?->unit_of_measurement;

        return [
            Stat::make('Абоненты', (string) $metrics['clients_active'])
                ->description("всего {$metrics['clients_total']} · новых за месяц {$metrics['clients_new']}")
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('primary'),
            Stat::make('Счётчики', (string) $metrics['meters_active'])
                ->description("по счётчику {$metrics['meters_metered']}")
                ->descriptionIcon(Heroicon::OutlinedCpuChip)
                ->color('primary'),
            Stat::make('Снято показаний', $this->formatPercent($metrics['readings_percent']))
                ->description("{$metrics['readings_taken']} из {$metrics['readings_expected']}")
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color($this->readingsColor($metrics['readings_percent'])),
            Stat::make('Потребление', (string) $metrics['consumption'])
                ->description($unit === null ? 'за выбранный месяц' : "за выбранный месяц, {$unit}")
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary'),
        ];
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }

    private function readingsColor(float $percent): string
    {
        return match (true) {
            $percent < 70.0 => 'danger',
            $percent < 95.0 => 'warning',
            default => 'success',
        };
    }
}
