<?php

namespace App\Reports\Builder;

use App\Models\Organization;
use App\Models\User;
use App\Reports\Concerns\AppliesReportFilters;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\Contracts\FiltersExcelExport;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report assembled on the report builder page: its screen table in the detail
 * or the summary mode and its XLSX export.
 *
 * The screen, the summary and the export apply the same filters of the source
 * on top of the same source query, so all of them describe the same rows.
 */
final class BuiltReport implements FiltersExcelExport
{
    use AppliesReportFilters;
    use FormatsReportValues;

    /**
     * Shown instead of a missing value, such as a percentage of a zero base.
     */
    public const string EMPTY_VALUE = '—';

    /**
     * @param  array{0: ?string, 1: ?string}  $sort  Column key and direction the operator sorted the screen table by.
     */
    public function __construct(
        private readonly ReportBuild $build,
        private readonly array $sort = [null, null],
    ) {}

    public function build(): ReportBuild
    {
        return $this->build;
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $table = $table
            ->filters($this->build->source->filters($organization), layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['sm' => 2, 'xl' => 3])
            ->recordUrl(null)
            ->emptyStateHeading($this->emptyStateHeading())
            ->emptyStateDescription($this->emptyStateDescription())
            ->striped();

        if ($this->build->isSummary()) {
            return $table
                ->records(fn (?array $filters): array => $this->summaryRecords($organization, $user, $filters ?? []))
                ->columns($this->summaryColumns())
                ->paginated(false);
        }

        return $table
            ->query(fn (): Builder => $this->detailQuery($organization, $user))
            ->columns($this->detailColumns())
            ->defaultSort(fn (Builder $query): Builder => $this->build->source->orderRows($query))
            ->defaultPaginationPageOption(50);
    }

    /**
     * The source query with the chosen fields selected and the relations they read
     * loaded in advance. The user filters are not applied yet.
     *
     * @return Builder<Model>
     */
    public function detailQuery(Organization $organization, User $user): Builder
    {
        $query = $this->build->source->query($organization, $user, $this->build->billingPeriod);
        $relations = [];

        foreach ($this->build->fields as $field) {
            if ($field->expression !== null) {
                $query->selectRaw("({$field->expression}) as {$field->alias()}");
            }

            $relations = [...$relations, ...$field->relations];
        }

        if ($relations !== []) {
            $query->with(array_values(array_unique($relations)));
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters  Applied table filter state, keyed by filter name.
     * @return array<string, array<string, mixed>>
     */
    public function summaryRecords(Organization $organization, User $user, array $filters): array
    {
        return app(ReportSummaryQuery::class)->records(
            $this->build,
            $this->filteredQuery($organization, $user, $filters),
            $organization,
        );
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        return $this->downloadFilteredExcel($organization, $user, []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public function downloadFilteredExcel(Organization $organization, User $user, array $filters): StreamedResponse
    {
        if ($this->build->isSummary()) {
            $records = array_values($this->summaryRecords($organization, $user, $filters));

            return $this->downloadXlsx(
                $this->excelFileName($organization),
                $this->summaryExcelOptions(),
                [$this->build->dimension?->heading ?? '', ...array_map(fn (ReportMetric $metric): string => $metric->label, $this->build->metrics)],
                fn (): iterable => array_map(fn (array $record): object => (object) $record, $records),
                fn (object $record): array => $this->summaryExcelCells($record),
            );
        }

        $query = $this->orderedForExport($this->filteredQuery($organization, $user, $filters));

        return $this->downloadXlsx(
            $this->excelFileName($organization),
            $this->detailExcelOptions(),
            array_map(fn (ReportField $field): string => $field->label, $this->build->fields),
            fn (): iterable => $query->lazy(500),
            fn (object $record): array => $this->detailExcelCells($record),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    private function filteredQuery(Organization $organization, User $user, array $filters): Builder
    {
        return $this->applyReportFilters(
            $this->detailQuery($organization, $user),
            $this->build->source->filters($organization),
            array_filter($filters, fn (mixed $state): bool => is_array($state)),
        );
    }

    /**
     * The export keeps the order of the screen: the column the operator sorted by when
     * it is one of the chosen sortable fields, otherwise the order of the source.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function orderedForExport(Builder $query): Builder
    {
        [$column, $direction] = $this->sort;

        foreach ($this->build->fields as $field) {
            if ($field->key === $column && $field->sortable && $field->expression !== null) {
                $query->orderBy($field->alias(), $direction === 'desc' ? 'desc' : 'asc');
            }
        }

        return $this->build->source
            ->orderRows($query)
            ->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * @return list<TextColumn>
     */
    private function detailColumns(): array
    {
        return array_map(fn (ReportField $field): TextColumn => $this->detailColumn($field), $this->build->fields);
    }

    private function detailColumn(ReportField $field): TextColumn
    {
        $billingPeriod = $this->build->billingPeriod;

        $column = $this->formatColumn(
            TextColumn::make($field->key)
                ->label($field->label)
                ->state(fn (Model $record): mixed => $field->valueOf($record, $billingPeriod))
                ->placeholder(self::EMPTY_VALUE)
                ->wrap($field->key === 'address'),
            $field->type,
        );

        if ($field->sortable && $field->expression !== null) {
            $column->sortable(query: fn (Builder $query, string $direction): Builder => $query
                ->orderBy($field->alias(), $direction === 'desc' ? 'desc' : 'asc'));
        }

        if ($field->searchable && $field->expression !== null) {
            $column->searchable(query: fn (Builder $query, string $search): Builder => $query
                ->where($field->expression, 'like', '%'.$search.'%'));
        }

        return $column;
    }

    /**
     * @return list<TextColumn>
     */
    private function summaryColumns(): array
    {
        $weight = fn (array $record): ?FontWeight => ($record['is_total'] ?? false) ? FontWeight::Bold : null;

        $columns = [
            TextColumn::make('group_label')
                ->label($this->build->dimension?->heading ?? '')
                ->weight($weight)
                ->wrap(),
        ];

        foreach ($this->build->metrics as $metric) {
            $columns[] = $this->formatColumn(
                TextColumn::make($metric->key)
                    ->label($metric->label)
                    ->placeholder(self::EMPTY_VALUE)
                    ->weight($weight),
                $metric->type,
            );
        }

        return $columns;
    }

    private function formatColumn(TextColumn $column, ReportFieldType $type): TextColumn
    {
        $column = match ($type) {
            ReportFieldType::Text => $column,
            ReportFieldType::Date => $column->date('d.m.Y'),
            ReportFieldType::Int => $column->numeric(),
            ReportFieldType::Money => $column->money('KZT'),
            ReportFieldType::Percent => $column->formatStateUsing(fn (mixed $state): string => self::percent($state)),
            ReportFieldType::Float => $column->numeric(decimalPlaces: 2),
        };

        return $type->isNumeric() ? $column->alignEnd() : $column;
    }

    private static function percent(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ' ').'%';
    }

    /**
     * @return list<Cell>
     */
    private function detailExcelCells(object $record): array
    {
        /** @var Model $record */
        $cells = [];

        foreach ($this->build->fields as $field) {
            $cells[] = $this->excelCell(
                $field->type,
                $field->valueOf($record, $this->build->billingPeriod),
                $field->key === 'address' ? (new Style)->setShouldWrapText() : null,
            );
        }

        return $cells;
    }

    /**
     * @return list<Cell>
     */
    private function summaryExcelCells(object $record): array
    {
        $style = ($record->is_total ?? false) ? (new Style)->setFontBold() : null;

        $cells = [new StringCell((string) $record->group_label, $style)];

        foreach ($this->build->metrics as $metric) {
            $cells[] = $this->excelCell($metric->type, $record->{$metric->key} ?? null, $style);
        }

        return $cells;
    }

    /**
     * A number is written as the screen shows it: whole numbers stay whole, money and
     * fractions are the value rounded once by `ReportFieldType::numberOf()` — the same
     * value the screen formats, so a tie such as 0.125 is 0.13 in both places — and a
     * percentage is rounded half up to two decimals, like `percent()` on the screen.
     */
    private function excelCell(ReportFieldType $type, mixed $value, ?Style $style): Cell
    {
        if ($value === null || $value === '') {
            return new StringCell(self::EMPTY_VALUE, $style);
        }

        return match ($type) {
            ReportFieldType::Text => new StringCell((string) $value, $style),
            ReportFieldType::Date => new StringCell(self::date($value), $style),
            ReportFieldType::Int, ReportFieldType::Money => new NumericCell($type->numberOf($value), $style),
            ReportFieldType::Float => new NumericCell($type->numberOf($value), self::twoDecimals($style)),
            ReportFieldType::Percent => new NumericCell(round((float) $value, 2), self::twoDecimals($style)),
        };
    }

    /**
     * The bold style of «Итого» is shared by the whole row, so the format goes on a copy.
     */
    private static function twoDecimals(?Style $style): Style
    {
        return ($style instanceof Style ? clone $style : new Style)->setFormat('0.00');
    }

    private static function date(mixed $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);

        return $date->format('d.m.Y');
    }

    private function emptyStateHeading(): string
    {
        return $this->build->billingPeriod === null ? 'Расчётный месяц не найден' : 'Строк нет';
    }

    private function emptyStateDescription(): string
    {
        return $this->build->billingPeriod === null
            ? 'Откройте расчётный месяц, чтобы собрать отчёт.'
            : 'Ни одной строки по текущим фильтрам.';
    }

    private function excelFileName(Organization $organization): string
    {
        return sprintf(
            'report-builder-%s-%s-%d-%s-%s.xlsx',
            $this->build->source->key(),
            $this->build->isSummary() ? 'summary-'.$this->build->dimension?->key : 'detail',
            $organization->getKey(),
            $this->build->billingPeriod?->code ?? 'no-period',
            today()->format('Y-m-d'),
        );
    }

    private function detailExcelOptions(): Options
    {
        $options = new Options;

        foreach ($this->build->fields as $index => $field) {
            $options->setColumnWidth(match (true) {
                $field->key === 'address' => 36,
                $field->type === ReportFieldType::Text => 24,
                default => 16,
            }, $index + 1);
        }

        return $options;
    }

    private function summaryExcelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(32, 1);

        foreach (array_keys($this->build->metrics) as $index) {
            $options->setColumnWidth(18, $index + 2);
        }

        return $options;
    }
}
