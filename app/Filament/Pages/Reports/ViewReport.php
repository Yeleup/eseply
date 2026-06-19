<?php

namespace App\Filament\Pages\Reports;

use App\Models\Organization;
use App\Models\User;
use App\Reports\Contracts\OrganizationReport;
use App\Reports\ReportRegistry;
use App\Reports\ReportSummaryGroup;
use App\Reports\ReportSummaryService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewReport extends Page implements HasTable
{
    use InteractsWithTable;

    private const MODE_DETAIL = 'detail';

    private const MODE_SUMMARY = 'summary';

    protected static ?string $slug = 'reports/{report}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.reports.view-report';

    public string $report;

    public string $mode = self::MODE_DETAIL;

    public string $summaryGroup = ReportSummaryGroup::Controller->value;

    public function mount(string $report, ?string $mode = null, ?string $group = null): void
    {
        abort_unless(app(ReportRegistry::class)->find($report) instanceof OrganizationReport, 404);

        $this->report = $report;
        $this->mode = $this->normalizeMode($mode ?? request()->query('mode'));
        $this->summaryGroup = $this->normalizeSummaryGroup($group ?? request()->query('group'))->value;

        if ($this->isSummaryMode() && ! $this->summaryService()->supports($this->report)) {
            $this->mode = self::MODE_DETAIL;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getReport()->title();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $description = $this->getReport()->description();

        if (! $this->isSummaryMode()) {
            return $description;
        }

        return trim(($description ? $description.' ' : '').'Режим сводки: '.$this->currentSummaryGroup()->label().'.');
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless($tenant instanceof Organization, 404);
        abort_unless($user instanceof User, 403);

        if ($this->isSummaryMode()) {
            return $this->summaryService()->table(
                $table,
                $this->report,
                $this->currentSummaryGroup(),
                $tenant,
                $user,
            );
        }

        return $this->getReport()->table($table, $tenant, $user);
    }

    public function downloadExcel(): StreamedResponse
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        abort_unless($tenant instanceof Organization, 404);
        abort_unless($user instanceof User, 403);

        if ($this->isSummaryMode()) {
            return $this->summaryService()->downloadExcel(
                $this->report,
                $this->currentSummaryGroup(),
                $tenant,
                $user,
            );
        }

        return $this->getReport()->downloadExcel($tenant, $user);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('detailMode')
                ->label('Детально')
                ->color($this->isSummaryMode() ? 'gray' : 'primary')
                ->url($this->reportModeUrl(self::MODE_DETAIL)),
            $this->summaryGroupAction(ReportSummaryGroup::Controller),
            $this->summaryGroupAction(ReportSummaryGroup::Region),
            $this->summaryGroupAction(ReportSummaryGroup::Street),
            Action::make('downloadExcel')
                ->label('Скачать Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->action(fn (): StreamedResponse => $this->downloadExcel()),
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

    private function summaryGroupAction(ReportSummaryGroup $group): Action
    {
        return Action::make('summaryBy'.$group->name)
            ->label($group->label())
            ->color($this->isSummaryMode($group) ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->summaryService()->supports($this->report))
            ->url($this->reportModeUrl(self::MODE_SUMMARY, $group));
    }

    private function isSummaryMode(?ReportSummaryGroup $group = null): bool
    {
        if ($this->mode !== self::MODE_SUMMARY) {
            return false;
        }

        if (! $group instanceof ReportSummaryGroup) {
            return true;
        }

        return $this->currentSummaryGroup() === $group;
    }

    private function currentSummaryGroup(): ReportSummaryGroup
    {
        return ReportSummaryGroup::tryFrom($this->summaryGroup) ?? ReportSummaryGroup::Controller;
    }

    private function normalizeMode(mixed $mode): string
    {
        return $mode === self::MODE_SUMMARY ? self::MODE_SUMMARY : self::MODE_DETAIL;
    }

    private function normalizeSummaryGroup(mixed $group): ReportSummaryGroup
    {
        return is_string($group)
            ? ReportSummaryGroup::tryFrom($group) ?? ReportSummaryGroup::Controller
            : ReportSummaryGroup::Controller;
    }

    private function reportModeUrl(string $mode, ?ReportSummaryGroup $group = null): string
    {
        $parameters = ['report' => $this->report];

        if ($mode === self::MODE_SUMMARY) {
            $parameters['mode'] = self::MODE_SUMMARY;
            $parameters['group'] = ($group ?? ReportSummaryGroup::Controller)->value;
        }

        return static::getUrl($parameters);
    }

    private function summaryService(): ReportSummaryService
    {
        return app(ReportSummaryService::class);
    }
}
