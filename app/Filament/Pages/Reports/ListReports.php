<?php

namespace App\Filament\Pages\Reports;

use App\Reports\Contracts\OrganizationReport;
use App\Reports\ReportRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ListReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Отчёты';

    protected static ?string $navigationLabel = 'Отчёты';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.reports.list-reports';

    /**
     * The list stays highlighted on a report page, but not on the report builder,
     * which has its own navigation item.
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [
            static::getRouteName(),
            ViewReport::getRouteName(),
        ];
    }

    public function canBuildReports(): bool
    {
        return BuildReport::canAccess();
    }

    /**
     * @return list<OrganizationReport>
     */
    public function getReports(): array
    {
        return app(ReportRegistry::class)->all();
    }
}
