<?php

namespace App\Filament\Pages\Reports;

use App\Models\Organization;
use App\Reports\Contracts\OrganizationReport;
use App\Reports\ReportRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ViewReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'reports/{report}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.reports.view-report';

    public string $report;

    public function mount(string $report): void
    {
        abort_unless(app(ReportRegistry::class)->find($report) instanceof OrganizationReport, 404);

        $this->report = $report;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getReport()->title();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getReport()->description();
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 404);

        return $this->getReport()->table($table, $tenant);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToReports')
                ->label('Все отчёты')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(ListReports::getUrl()),
        ];
    }

    private function getReport(): OrganizationReport
    {
        return app(ReportRegistry::class)->find($this->report) ?? abort(404);
    }
}
