<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardMetrics;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class DashboardChargesChartWidget extends ChartWidget
{
    protected ?string $heading = 'Начисления и оплаты по месяцам';

    protected ?string $description = 'Последние 12 расчётных месяцев организации.';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    /**
     * Accruals, receipts and payments are operator-only data.
     */
    public static function canView(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $organization = Filament::getTenant();

        if (! $organization instanceof Organization) {
            return ['datasets' => [], 'labels' => []];
        }

        $totals = app(DashboardMetrics::class)->monthlyTotals($organization);

        return [
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => array_map(fn (array $total): float => $total['charged'], $totals),
                    'backgroundColor' => '#f59e0b',
                ],
                [
                    'label' => 'Оплачено',
                    'data' => array_map(fn (array $total): float => $total['paid'], $totals),
                    'backgroundColor' => '#10b981',
                ],
            ],
            'labels' => array_map(fn (array $total): string => $total['label'], $totals),
        ];
    }
}
