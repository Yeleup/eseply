<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Builder\BuiltReport;
use App\Reports\Builder\ReportBuild;
use App\Reports\Builder\ReportDimension;
use App\Reports\Builder\ReportField;
use App\Reports\Builder\ReportMetric;
use App\Reports\Builder\ReportSource;
use App\Reports\Builder\ReportSourceRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * One-off report assembled by the operator from the catalog of a data source.
 *
 * Nothing is saved: the whole build — source, fields, dimension, turned off metrics,
 * billing period and mode — lives in the address of the page, so a link reproduces
 * it. The address is user input, so every part of it is looked up in the catalog
 * and anything unknown falls back to the default of the source.
 *
 * The static slug is discovered and registered before `reports/{report}` of
 * `ViewReport`, so it is never taken for a report slug.
 */
class BuildReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'reports/custom';

    protected static ?string $title = 'Конструктор отчётов';

    protected static ?string $navigationLabel = 'Конструктор отчётов';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.reports.build-report';

    #[Url(as: 'source')]
    public string $source = '';

    /**
     * Comma separated field keys, empty while the source defaults are shown.
     */
    #[Url(as: 'fields')]
    public string $fields = '';

    /**
     * Dimension key, empty for the source default, `none` for «Без группировки».
     */
    #[Url(as: 'group')]
    public string $group = '';

    /**
     * Comma separated keys of the metrics turned off.
     */
    #[Url(as: 'off')]
    public string $disabledMetrics = '';

    #[Url(as: 'period')]
    public ?string $period = null;

    #[Url(as: 'mode')]
    public string $mode = ReportBuild::MODE_DETAIL;

    private ?ReportBuild $cachedBuild = null;

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public function mount(): void
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        $this->normalizeBuild();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Разовый отчёт для оператора организации: соберите из разрешённых полей, посмотрите и выгрузите. Сборка живёт в адресе страницы — ссылку можно переслать, ничего не сохраняется.';
    }

    public function table(Table $table): Table
    {
        return $this->builtReport()->table($table, $this->tenant(), $this->user());
    }

    /**
     * A build property changed by the client is normalized like the address is.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['source', 'fields', 'group', 'disabledMetrics', 'period', 'mode'], true)) {
            $this->applyBuild();
        }
    }

    /**
     * Another source starts from its own default fields, dimension and metrics.
     */
    public function selectSource(mixed $key): void
    {
        $source = app(ReportSourceRegistry::class)->find($key);

        if (! $source instanceof ReportSource || $source->key() === $this->reportBuild()->source->key()) {
            return;
        }

        $this->source = $source->key();
        $this->fields = '';
        $this->group = '';
        $this->disabledMetrics = '';

        $this->applyBuild();
    }

    /**
     * The last chosen field stays: a report without columns shows nothing.
     */
    public function toggleField(mixed $key): void
    {
        $build = $this->reportBuild();
        $field = $this->catalogField($key);

        if (! $field instanceof ReportField) {
            return;
        }

        $keys = array_map(fn (ReportField $field): string => $field->key, $build->fields);

        if (in_array($field->key, $keys, true)) {
            if (count($keys) === 1) {
                return;
            }

            $keys = array_values(array_diff($keys, [$field->key]));
        } else {
            $keys[] = $field->key;
        }

        $this->fields = ReportBuild::fieldsInput(
            $build->source,
            ReportBuild::fieldsOf($build->source, implode(',', $keys)),
        );

        $this->applyBuild();
    }

    /**
     * Without a dimension there is nothing to summarize, so the detail table is shown.
     */
    public function selectDimension(mixed $key): void
    {
        $source = $this->reportBuild()->source;
        $dimension = ReportBuild::dimensionOf($source, $key);

        $this->group = ReportBuild::dimensionInput($source, $dimension);

        if (! $dimension instanceof ReportDimension) {
            $this->mode = ReportBuild::MODE_DETAIL;
        }

        $this->applyBuild();
    }

    public function toggleMetric(mixed $key): void
    {
        $build = $this->reportBuild();
        $enabled = array_map(fn (ReportMetric $metric): string => $metric->key, $build->metrics);

        $metrics = array_values(array_filter(
            $build->source->metrics(),
            fn (ReportMetric $metric): bool => $metric->key === $key
                ? ! in_array($metric->key, $enabled, true)
                : in_array($metric->key, $enabled, true),
        ));

        $this->disabledMetrics = ReportBuild::disabledMetricsInput($build->source, $metrics);

        $this->applyBuild();
    }

    public function selectMode(mixed $mode): void
    {
        $this->mode = ReportBuild::modeOf($mode);

        $this->applyBuild();
    }

    public function selectBillingPeriod(mixed $billingPeriodId): void
    {
        $this->period = $billingPeriodId === null ? null : (string) $billingPeriodId;

        $this->applyBuild();
    }

    public function downloadXlsx(): StreamedResponse
    {
        abort_unless(OrganizationMemberAccess::canManageTenant(), 403);

        return $this->builtReport()->downloadFilteredExcel(
            $this->tenant(),
            $this->user(),
            $this->appliedTableFilters(),
        );
    }

    public function reportBuild(): ReportBuild
    {
        if ($this->cachedBuild instanceof ReportBuild) {
            return $this->cachedBuild;
        }

        $registry = app(ReportSourceRegistry::class);
        $source = $registry->find($this->source) ?? $registry->default();

        return $this->cachedBuild = new ReportBuild(
            source: $source,
            fields: ReportBuild::fieldsOf($source, $this->fields),
            dimension: ReportBuild::dimensionOf($source, $this->group),
            metrics: ReportBuild::metricsOf($source, $this->disabledMetrics),
            billingPeriod: ReportBuild::billingPeriodOf($source, $this->tenant(), ReportBuild::billingPeriodIdOf($this->period)),
            mode: ReportBuild::modeOf($this->mode),
        );
    }

    /**
     * @return list<array{key: string, label: string, hint: string, active: bool}>
     */
    public function sourceOptions(): array
    {
        $current = $this->reportBuild()->source->key();

        return array_map(
            fn (ReportSource $source): array => [
                'key' => $source->key(),
                'label' => $source->label(),
                'hint' => $source->hint(),
                'active' => $source->key() === $current,
            ],
            app(ReportSourceRegistry::class)->all(),
        );
    }

    /**
     * @return list<array{key: string, label: string, kind: string, computed: bool, checked: bool, locked: bool}>
     */
    public function fieldOptions(): array
    {
        $build = $this->reportBuild();
        $chosen = array_map(fn (ReportField $field): string => $field->key, $build->fields);

        return array_map(
            fn (ReportField $field): array => [
                'key' => $field->key,
                'label' => $field->label,
                'kind' => $field->computed ? 'вычисляемое' : $field->type->label(),
                'computed' => $field->computed,
                'checked' => in_array($field->key, $chosen, true),
                'locked' => $chosen === [$field->key],
            ],
            $build->source->fields(),
        );
    }

    public function fieldsCounter(): string
    {
        $build = $this->reportBuild();

        return 'выбрано '.count($build->fields).' из '.count($build->source->fields());
    }

    /**
     * @return array<string, string>
     */
    public function dimensionOptions(): array
    {
        $options = [ReportBuild::NO_DIMENSION => 'Без группировки'];

        foreach ($this->reportBuild()->source->dimensions() as $dimension) {
            $options[$dimension->key] = $dimension->label;
        }

        return $options;
    }

    public function selectedDimensionKey(): string
    {
        return $this->reportBuild()->dimension?->key ?? ReportBuild::NO_DIMENSION;
    }

    /**
     * @return list<array{key: string, label: string, checked: bool}>
     */
    public function metricOptions(): array
    {
        $build = $this->reportBuild();
        $enabled = array_map(fn (ReportMetric $metric): string => $metric->key, $build->metrics);

        return array_map(
            fn (ReportMetric $metric): array => [
                'key' => $metric->key,
                'label' => $metric->label,
                'checked' => in_array($metric->key, $enabled, true),
            ],
            $build->source->metrics(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function billingPeriodOptions(): array
    {
        return BillingPeriod::query()
            ->forOrganization($this->tenant())
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (BillingPeriod $billingPeriod): array => [
                $billingPeriod->getKey() => $billingPeriod->label.' — '.$billingPeriod->status->getLabel(),
            ])
            ->all();
    }

    public function isSummaryLocked(): bool
    {
        $build = $this->reportBuild();

        return $build->mode === ReportBuild::MODE_SUMMARY && ! $build->isSummary();
    }

    public function previewNote(): string
    {
        $build = $this->reportBuild();
        $period = $build->billingPeriod?->label ?? 'расчётный месяц не выбран';

        if ($build->isSummary()) {
            return 'Сводка: '.mb_strtolower($build->source->label()).', '.mb_strtolower((string) $build->dimension?->label).', '.$period.'. Фильтры применены.';
        }

        return $build->source->label().': '.$period.'.';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetBuild')
                ->label('Сбросить')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->url(static::getUrl()),
            Action::make('downloadXlsx')
                ->label('Скачать XLSX')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->downloadXlsx()),
        ];
    }

    private function builtReport(): BuiltReport
    {
        return new BuiltReport(
            $this->reportBuild(),
            [$this->getTableSortColumn(), $this->getTableSortDirection()],
        );
    }

    /**
     * Rewrites every build property to its canonical value, so the address shows
     * only what the page actually uses.
     */
    private function normalizeBuild(): void
    {
        $this->cachedBuild = null;

        $build = $this->reportBuild();
        $registry = app(ReportSourceRegistry::class);

        $this->source = $build->source->key() === $registry->default()->key() ? '' : $build->source->key();
        $this->fields = ReportBuild::fieldsInput($build->source, $build->fields);
        $this->group = ReportBuild::dimensionInput($build->source, $build->dimension);
        $this->disabledMetrics = ReportBuild::disabledMetricsInput($build->source, $build->metrics);
        $this->mode = $build->mode;

        $chosenBillingPeriodId = ReportBuild::billingPeriodIdOf($this->period);
        $this->period = $chosenBillingPeriodId !== null && $build->billingPeriod?->getKey() === $chosenBillingPeriodId
            ? (string) $chosenBillingPeriodId
            : null;
    }

    /**
     * The table is built once a request, before the properties change, so a new
     * build needs a new table. The filters, the search and the sort stay.
     */
    private function applyBuild(): void
    {
        $this->normalizeBuild();

        $this->cachedDefaultTableColumnState = null;
        $this->bootedInteractsWithTable();
        $this->flushCachedTableRecords();
        $this->resetPage();
    }

    private function catalogField(mixed $key): ?ReportField
    {
        foreach ($this->reportBuild()->source->fields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
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

    private function tenant(): Organization
    {
        $tenant = OrganizationMemberAccess::tenant();

        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }

    private function user(): User
    {
        $user = OrganizationMemberAccess::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
