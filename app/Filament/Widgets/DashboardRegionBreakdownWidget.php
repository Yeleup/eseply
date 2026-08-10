<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\DashboardBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class DashboardRegionBreakdownWidget extends TableWidget
{
    use InteractsWithPageFilters;

    /**
     * Rows shown on the dashboard; the full list lives in the debts report.
     */
    private const int ROW_LIMIT = 10;

    protected int|string|array $columnSpan = 2;

    protected static ?int $sort = 5;

    /**
     * Accruals, receipts and payments are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Срез по районам')
            ->records(fn (): array => $this->records())
            ->columns([
                TextColumn::make('region_label')
                    ->label('Район')
                    ->wrap(),
                TextColumn::make('clients')
                    ->label('Абонентов')
                    ->numeric(),
                TextColumn::make('readings_percent_label')
                    ->label('Снято'),
                TextColumn::make('charged')
                    ->label('Начислено')
                    ->money('KZT'),
                TextColumn::make('paid')
                    ->label('Оплачено')
                    ->money('KZT'),
                TextColumn::make('debt')
                    ->label('Долг')
                    ->money('KZT')
                    ->color('danger'),
            ])
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading('Нет данных по районам')
            ->emptyStateDescription('В районах организации ещё нет активных абонентов.')
            ->striped();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
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

        $breakdown = app(DashboardMetrics::class)->regionBreakdown($organization, $billingPeriod);

        $records = [];

        foreach (array_slice($breakdown, 0, self::ROW_LIMIT) as $row) {
            $records[$row['region_id']] = [
                ...$row,
                'region_label' => $row['city'] === '' ? $row['region'] : "{$row['city']} / {$row['region']}",
                'readings_percent_label' => $this->formatPercent($row['readings_percent']),
            ];
        }

        return $records;
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.').' %';
    }
}
