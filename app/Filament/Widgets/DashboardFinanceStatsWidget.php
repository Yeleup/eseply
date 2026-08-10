<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class DashboardFinanceStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Начисления и оплаты';

    protected static ?int $sort = 2;

    /**
     * Payments, accruals and receipts are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
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

        if (! $organization instanceof Organization) {
            return [];
        }

        $billingPeriod = DashboardBillingPeriod::resolve(
            $organization,
            $this->pageFilters['billing_period_id'] ?? null,
        );

        if (! $billingPeriod instanceof BillingPeriod) {
            return [];
        }

        $metrics = app(DashboardMetrics::class)->finance($organization, $billingPeriod);

        return [
            Stat::make('Начислено', $this->formatMoney($metrics['charged']))
                ->description($this->chargedDescription($metrics['charged_is_preliminary'], $metrics['charged_documents']))
                ->descriptionIcon(Heroicon::OutlinedDocumentText)
                ->color($metrics['charged_is_preliminary'] ? 'warning' : 'primary'),
            Stat::make('Оплачено', $this->formatMoney($metrics['paid']))
                ->description("{$metrics['payments_count']} оплат · сбор {$this->formatPercent($metrics['collection_percent'])}")
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Долг на конец месяца', $this->formatMoney($metrics['debt']))
                ->description("{$metrics['debtors_count']} абонентов")
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($metrics['debt'] > 0.0 ? 'danger' : 'success'),
        ];
    }

    private function chargedDescription(bool $isPreliminary, int $documents): string
    {
        return $isPreliminary
            ? "предварительно, по {$documents} квитанциям"
            : "по {$documents} начислениям";
    }

    private function formatMoney(float $amount): string
    {
        return Number::currency($amount, 'KZT', 'ru');
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }
}
