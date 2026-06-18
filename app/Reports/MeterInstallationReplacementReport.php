<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeterInstallationReplacementReport implements OrganizationReport
{
    use FormatsReportValues;

    public function slug(): string
    {
        return 'meter-installation-replacement';
    }

    public function title(): string
    {
        return 'Замена/установка счётчика';
    }

    public function description(): ?string
    {
        return 'Счётчики, установленные или снятые в текущем открытом или ошибочном расчётном месяце.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('clients.account_number', 'like', '%'.$search.'%')),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('clients.name', 'like', '%'.$search.'%')),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Meter $record): string => $this->formatClientAddress($record->client)),
                TextColumn::make('number')
                    ->label('Счётчик')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('meter_operation_for_report')
                    ->label('Операция')
                    ->state(fn (Meter $record): string => $this->operationLabel($record, $billingPeriod))
                    ->badge()
                    ->color(fn (Meter $record): string => $this->operationColor($record, $billingPeriod)),
                TextColumn::make('installed_on')
                    ->label('Установлен')
                    ->date('d.m.Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('removed_on')
                    ->label('Снят')
                    ->date('d.m.Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('initial_reading')
                    ->label('Начальное показание')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('meter_status_for_report')
                    ->label('Статус')
                    ->state(fn (Meter $record): string => $this->meterStatusLabel($record->status))
                    ->badge()
                    ->color(fn (Meter $record): string => $record->status === 'active' ? 'success' : 'gray'),
                TextColumn::make('note')
                    ->label('Примечание')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Установок и замен нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц установки или снятия счётчиков не найдены.'
                : 'Откройте расчётный месяц, чтобы увидеть установки и замены счётчиков.')
            ->striped();
    }

    public function downloadExcel(Organization $organization, User $user): StreamedResponse
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $this->downloadXlsx(
            $this->excelFileName($organization, $billingPeriod),
            $this->excelOptions(),
            $this->excelHeadings(),
            fn (): iterable => $this->query($organization, $user, $billingPeriod)->lazy(500),
            fn (object $record): array => $this->excelCells($record, $billingPeriod),
        );
    }

    /**
     * @return Builder<Meter>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Meter::query()
            ->select('meters.*')
            ->join('clients', 'clients.id', '=', 'meters.client_id')
            ->with([
                'client.region',
                'client.street',
                'utilityService',
            ])
            ->visibleToOrganizationMember($user, $organization)
            ->orderBy('clients.account_number')
            ->orderBy('meters.number')
            ->orderBy('meters.id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('meters.id', 0);
        }

        [$startsOn, $endsOn] = $this->billingPeriodDateRange($billingPeriod);

        return $query->where(function (Builder $query) use ($startsOn, $endsOn): void {
            $query
                ->whereBetween('meters.installed_on', [$startsOn, $endsOn])
                ->orWhereBetween('meters.removed_on', [$startsOn, $endsOn]);
        });
    }

    private function operationLabel(Meter $meter, ?BillingPeriod $billingPeriod): string
    {
        $installed = $this->isDateInBillingPeriod($meter->installed_on, $billingPeriod);
        $removed = $this->isDateInBillingPeriod($meter->removed_on, $billingPeriod);

        return match (true) {
            $installed && $removed => 'Установка и снятие',
            $removed => 'Замена / снятие',
            $installed => 'Установка',
            default => '-',
        };
    }

    private function operationColor(Meter $meter, ?BillingPeriod $billingPeriod): string
    {
        if ($this->isDateInBillingPeriod($meter->removed_on, $billingPeriod)) {
            return 'warning';
        }

        return 'success';
    }

    private function meterStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Активный',
            'removed' => 'Снят',
            default => (string) $status,
        };
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'meter-installation-replacement-%d-%s-%s.xlsx',
            $organization->getKey(),
            $billingPeriod?->code ?? 'no-open-period',
            today()->format('Y-m-d'),
        );
    }

    private function excelOptions(): Options
    {
        $options = new Options;
        $options->setColumnWidth(16, 1);
        $options->setColumnWidth(28, 2);
        $options->setColumnWidth(36, 3);
        $options->setColumnWidth(18, 4);
        $options->setColumnWidth(18, 5);
        $options->setColumnWidth(16, 6);
        $options->setColumnWidth(16, 7);
        $options->setColumnWidth(22, 8);
        $options->setColumnWidth(14, 9);
        $options->setColumnWidth(32, 10);

        return $options;
    }

    /**
     * @return list<string>
     */
    private function excelHeadings(): array
    {
        return [
            'Лицевой счёт',
            'Абонент',
            'Адрес',
            'Счётчик',
            'Операция',
            'Установлен',
            'Снят',
            'Начальное показание',
            'Статус',
            'Примечание',
        ];
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(object $record, ?BillingPeriod $billingPeriod): array
    {
        /** @var Meter $meter */
        $meter = $record;

        return [
            new StringCell((string) ($meter->client?->account_number ?? ''), null),
            new StringCell((string) ($meter->client?->name ?? ''), null),
            new StringCell($this->formatClientAddress($meter->client), (new Style)->setShouldWrapText()),
            new StringCell((string) $meter->number, null),
            new StringCell($this->operationLabel($meter, $billingPeriod), null),
            new StringCell($meter->installed_on?->format('d.m.Y') ?? '', null),
            new StringCell($meter->removed_on?->format('d.m.Y') ?? '', null),
            new NumericCell((float) $meter->initial_reading, (new Style)->setFormat('0.0000')),
            new StringCell($this->meterStatusLabel($meter->status), null),
            new StringCell((string) ($meter->note ?? ''), null),
        ];
    }
}
