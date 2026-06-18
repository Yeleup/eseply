<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ControllerMeterReadingProgressReport implements OrganizationReport
{
    public function slug(): string
    {
        return 'controller-meter-reading-progress';
    }

    public function title(): string
    {
        return 'Процент снятия по контроллерам';
    }

    public function description(): ?string
    {
        return 'Доля активных счётчиков в зоне каждого контроллера, по которым снято показание за текущий расчётный месяц.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $billingPeriod))
            ->columns([
                TextColumn::make('name')
                    ->label('Контроллер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('assigned_regions_for_report')
                    ->label('Регионы')
                    ->state(fn (User $record): string => $this->assignedRegionNames($organization, $record))
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('assigned_streets_for_report')
                    ->label('Улицы')
                    ->state(fn (User $record): string => $this->assignedStreetNames($organization, $record))
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('billing_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('total_meters_for_report')
                    ->label('Всего счётчиков')
                    ->state(fn (User $record): int => $this->totalMeters($record))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('read_meters_for_report')
                    ->label('Снято')
                    ->state(fn (User $record): int => $this->readMeters($record))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('missing_meters_for_report')
                    ->label('Не снято')
                    ->state(fn (User $record): int => $this->missingMeters($record))
                    ->numeric(),
                TextColumn::make('reading_completion_percent_for_report')
                    ->label('Процент снятия')
                    ->state(fn (User $record): string => $this->formattedCompletionPercent($record))
                    ->badge()
                    ->color(fn (User $record): string => $this->completionColor($record)),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Нет контроллеров' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'В выбранной организации нет пользователей с ролью контроллера.'
                : 'Откройте расчётный месяц, чтобы увидеть процент снятия по контроллерам.')
            ->striped();
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return response()->streamDownload(
            function () use ($organization, $billingPeriod): void {
                $writer = new Writer($this->excelOptions());
                $writer->openToFile('php://output');

                $writer->addRow(new Row($this->excelHeadingCells()));

                foreach ($this->query($organization, $billingPeriod)->lazy(500) as $controller) {
                    $writer->addRow(new Row($this->excelCells($organization, $controller, $billingPeriod)));
                }

                $writer->close();
            },
            $this->excelFileName($organization, $billingPeriod),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    private function query(Organization $organization, ?BillingPeriod $billingPeriod): Builder
    {
        $query = User::query()
            ->select('users.*')
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->getKey())
            ->where('organization_user.role', OrganizationMemberRole::Controller->value)
            ->orderBy('users.name')
            ->orderBy('users.id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('users.id', 0);
        }

        return $query->addSelect([
            'total_meters_for_report' => $this->meterCountQuery($organization),
            'read_meters_for_report' => $this->meterCountQuery($organization, $billingPeriod),
        ]);
    }

    private function meterCountQuery(Organization $organization, ?BillingPeriod $billingPeriod = null): Builder
    {
        $query = Meter::query()
            ->selectRaw('count(distinct meters.id)')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->where('meters.organization_id', $organization->getKey())
            ->where('meters.status', 'active')
            ->where('clients.status', 'active')
            ->where('clients.billing_type', 'meter')
            ->where(function (Builder $query) use ($organization): void {
                $query
                    ->whereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_regions')
                            ->where('organization_user_regions.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_regions.user_id', 'users.id')
                            ->whereColumn('organization_user_regions.region_id', 'clients.region_id');
                    })
                    ->orWhereExists(function (QueryBuilder $query) use ($organization): void {
                        $query
                            ->selectRaw('1')
                            ->from('organization_user_streets')
                            ->where('organization_user_streets.organization_id', $organization->getKey())
                            ->whereColumn('organization_user_streets.user_id', 'users.id')
                            ->whereColumn('organization_user_streets.street_id', 'clients.street_id');
                    });
            });

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $query) use ($billingPeriod): void {
            $query
                ->selectRaw('1')
                ->from('meter_readings')
                ->whereColumn('meter_readings.meter_id', 'meters.id')
                ->where('meter_readings.billing_period_id', $billingPeriod->getKey());
        });
    }

    private function assignedRegionNames(Organization $organization, User $controller): string
    {
        $names = DB::table('organization_user_regions')
            ->join('regions', 'regions.id', '=', 'organization_user_regions.region_id')
            ->where('organization_user_regions.organization_id', $organization->getKey())
            ->where('organization_user_regions.user_id', $controller->getKey())
            ->orderBy('regions.name')
            ->pluck('regions.name')
            ->all();

        return $names === [] ? '-' : implode(', ', $names);
    }

    private function assignedStreetNames(Organization $organization, User $controller): string
    {
        $streets = DB::table('organization_user_streets')
            ->join('streets', 'streets.id', '=', 'organization_user_streets.street_id')
            ->leftJoin('regions', 'regions.id', '=', 'streets.region_id')
            ->where('organization_user_streets.organization_id', $organization->getKey())
            ->where('organization_user_streets.user_id', $controller->getKey())
            ->orderBy('regions.name')
            ->orderBy('streets.name')
            ->get(['streets.name', 'regions.name as region_name'])
            ->map(fn (object $street): string => ($street->region_name ? $street->region_name.' / ' : '').$street->name)
            ->all();

        return $streets === [] ? '-' : implode(', ', $streets);
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'controller-meter-reading-progress-%d-%s-%s.xlsx',
            $organization->getKey(),
            $billingPeriod?->code ?? 'no-open-period',
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(28, 1);
        $options->setColumnWidth(30, 2);
        $options->setColumnWidth(32, 3);
        $options->setColumnWidth(36, 4);
        $options->setColumnWidth(14, 5);
        $options->setColumnWidth(18, 6);
        $options->setColumnWidth(14, 7);
        $options->setColumnWidth(14, 8);
        $options->setColumnWidth(18, 9);

        return $options;
    }

    /**
     * @return list<Cell>
     */
    private function excelHeadingCells(): array
    {
        $style = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(229, 231, 235));

        return array_map(
            fn (string $heading): StringCell => new StringCell($heading, $style),
            [
                'Контроллер',
                'Email',
                'Регионы',
                'Улицы',
                'Период',
                'Всего счётчиков',
                'Снято',
                'Не снято',
                'Процент снятия',
            ],
        );
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(Organization $organization, User $controller, ?BillingPeriod $billingPeriod): array
    {
        return [
            new StringCell((string) $controller->name, null),
            new StringCell((string) $controller->email, null),
            new StringCell($this->assignedRegionNames($organization, $controller), (new Style)->setShouldWrapText()),
            new StringCell($this->assignedStreetNames($organization, $controller), (new Style)->setShouldWrapText()),
            new StringCell($billingPeriod?->label ?? '', null),
            new NumericCell($this->totalMeters($controller), null),
            new NumericCell($this->readMeters($controller), null),
            new NumericCell($this->missingMeters($controller), null),
            new NumericCell($this->completionPercent($controller), (new Style)->setFormat('0.00')),
        ];
    }

    private function totalMeters(User $controller): int
    {
        return (int) ($controller->getAttribute('total_meters_for_report') ?? 0);
    }

    private function readMeters(User $controller): int
    {
        return (int) ($controller->getAttribute('read_meters_for_report') ?? 0);
    }

    private function missingMeters(User $controller): int
    {
        return max(0, $this->totalMeters($controller) - $this->readMeters($controller));
    }

    private function completionPercent(User $controller): float
    {
        $totalMeters = $this->totalMeters($controller);

        if ($totalMeters === 0) {
            return 0.0;
        }

        return round(($this->readMeters($controller) / $totalMeters) * 100, 2);
    }

    private function formattedCompletionPercent(User $controller): string
    {
        return number_format($this->completionPercent($controller), 2, '.', '').'%';
    }

    private function completionColor(User $controller): string
    {
        $percent = $this->completionPercent($controller);

        return match (true) {
            $percent >= 90 => 'success',
            $percent >= 50 => 'warning',
            default => 'danger',
        };
    }
}
