<?php

namespace App\Filament\Pages\Reports;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Contracts\FiltersExcelExport;
use App\Reports\Contracts\OrganizationReport;
use App\Reports\Contracts\SelectsBillingPeriod;
use App\Reports\ReportRegistry;
use App\Reports\ReportSummaryGroup;
use App\Reports\ReportSummaryService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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

    public ?int $billingPeriodId = null;

    private ?BillingPeriod $cachedBillingPeriod = null;

    private bool $billingPeriodResolved = false;

    public function mount(string $report, ?string $mode = null, ?string $group = null, ?string $period = null): void
    {
        abort_unless(app(ReportRegistry::class)->find($report) instanceof OrganizationReport, 404);

        $this->report = $report;
        $this->mode = $this->normalizeMode($mode ?? request()->query('mode'));
        $this->summaryGroup = $this->normalizeSummaryGroup($group ?? request()->query('group'))->value;
        $this->billingPeriodId = $this->normalizeBillingPeriodId($period ?? request()->query('period'));

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
                $this->resolvedBillingPeriod(),
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
                $this->resolvedBillingPeriod(),
            );
        }

        $report = $this->getReport();

        if ($report instanceof FiltersExcelExport) {
            return $report->downloadFilteredExcel($tenant, $user, $this->appliedTableFilters());
        }

        return $report->downloadExcel($tenant, $user);
    }

    /**
     * Only filters the user has actually applied, never the pending state of a deferred filter form.
     *
     * @return array<string, array<string, mixed>>
     */
    private function appliedTableFilters(): array
    {
        return array_filter(
            $this->tableFilters ?? [],
            fn (mixed $data): bool => is_array($data),
        );
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->billingPeriodAction(),
            Action::make('detailMode')
                ->label('Детально')
                ->color($this->isSummaryMode() ? 'gray' : 'primary')
                ->url($this->reportModeUrl(self::MODE_DETAIL)),
            $this->summaryGroupAction(ReportSummaryGroup::City),
            $this->summaryGroupAction(ReportSummaryGroup::Region),
            $this->summaryGroupAction(ReportSummaryGroup::Street),
            $this->summaryGroupAction(ReportSummaryGroup::Controller),
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
        $report = app(ReportRegistry::class)->find($this->report) ?? abort(404);

        if ($report instanceof SelectsBillingPeriod) {
            return $report->forBillingPeriod($this->resolvedBillingPeriod());
        }

        return $report;
    }

    private function billingPeriodAction(): Action
    {
        $billingPeriod = $this->selectsBillingPeriod() ? $this->resolvedBillingPeriod() : null;

        return Action::make('selectBillingPeriod')
            ->label('Расчётный месяц: '.($billingPeriod?->label ?? 'не выбран'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (): bool => $this->selectsBillingPeriod())
            ->modalHeading('Расчётный месяц отчёта')
            ->modalSubmitActionLabel('Показать')
            ->schema([
                Select::make('billing_period_id')
                    ->label('Расчётный месяц')
                    ->options(fn (): array => $this->billingPeriodOptions())
                    ->default($billingPeriod?->getKey())
                    ->native(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->redirect($this->reportModeUrl(
                    $this->mode,
                    $this->currentSummaryGroup(),
                    (int) $data['billing_period_id'],
                ));
            });
    }

    /**
     * @return array<int, string>
     */
    private function billingPeriodOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return [];
        }

        return BillingPeriod::query()
            ->forOrganization($tenant)
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->getKey() => $billingPeriod->label.' — '.$billingPeriod->status->getLabel(),
            ])
            ->all();
    }

    private function selectsBillingPeriod(): bool
    {
        return app(ReportRegistry::class)->find($this->report) instanceof SelectsBillingPeriod;
    }

    private function resolvedBillingPeriod(): ?BillingPeriod
    {
        if ($this->billingPeriodResolved) {
            return $this->cachedBillingPeriod;
        }

        $this->billingPeriodResolved = true;

        $tenant = Filament::getTenant();
        $report = app(ReportRegistry::class)->find($this->report);

        if (! $tenant instanceof Organization || ! $report instanceof SelectsBillingPeriod) {
            return $this->cachedBillingPeriod = null;
        }

        if ($this->billingPeriodId !== null) {
            $selected = BillingPeriod::query()
                ->forOrganization($tenant)
                ->whereKey($this->billingPeriodId)
                ->first();

            if ($selected instanceof BillingPeriod) {
                return $this->cachedBillingPeriod = $selected;
            }
        }

        return $this->cachedBillingPeriod = $report->defaultBillingPeriodFor($tenant);
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

    /**
     * The identifier comes from the URL, so only a positive integer is kept.
     * The tenant of the identifier is checked while the billing period is resolved.
     */
    private function normalizeBillingPeriodId(mixed $billingPeriodId): ?int
    {
        if (! is_numeric($billingPeriodId)) {
            return null;
        }

        $identifier = (int) $billingPeriodId;

        return $identifier > 0 ? $identifier : null;
    }

    private function reportModeUrl(string $mode, ?ReportSummaryGroup $group = null, ?int $billingPeriodId = null): string
    {
        $parameters = ['report' => $this->report];

        if ($mode === self::MODE_SUMMARY) {
            $parameters['mode'] = self::MODE_SUMMARY;
            $parameters['group'] = ($group ?? ReportSummaryGroup::Controller)->value;
        }

        $selectedBillingPeriodId = $billingPeriodId ?? $this->resolvedBillingPeriod()?->getKey();

        if ($selectedBillingPeriodId !== null) {
            $parameters['period'] = $selectedBillingPeriodId;
        }

        return static::getUrl($parameters);
    }

    private function summaryService(): ReportSummaryService
    {
        return app(ReportSummaryService::class);
    }
}
