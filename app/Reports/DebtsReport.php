<?php

namespace App\Reports;

use App\Filament\Support\ClientAddressFilter;
use App\Filament\Support\ControllerZoneFilter;
use App\Models\BillingPeriod;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Reports\Concerns\AppliesReportFilters;
use App\Reports\Concerns\FormatsReportValues;
use App\Reports\Contracts\FiltersExcelExport;
use App\Reports\Contracts\OrganizationReport;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DebtsReport implements FiltersExcelExport, OrganizationReport
{
    use AppliesReportFilters;
    use FormatsReportValues;

    public function slug(): string
    {
        return 'debts';
    }

    public function title(): string
    {
        return 'Отчёт по долгам';
    }

    public function description(): ?string
    {
        return 'Абоненты с долгом на сегодня за текущий расчётный месяц, включая абонентов без квитанции.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Client $record): string => $this->formatClientAddress($record)),
                TextColumn::make('debt_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('opening_balance')
                    ->label('Начальное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('accrual_amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('adjustment_amount')
                    ->label('Корректировка')
                    ->money('KZT')
                    ->toggleable(),
                TextColumn::make('debt_amount')
                    ->label('Долг')
                    ->money('KZT')
                    ->sortable(),
            ])
            ->filters($this->filters($organization))
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Долгов нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц абонентов с долгом не найдено.'
                : 'Откройте расчётный месяц, чтобы увидеть долги.')
            ->striped();
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
        $billingPeriod = BillingPeriod::currentEditableFor($organization);
        $query = $this->applyReportFilters(
            $this->query($organization, $user, $billingPeriod),
            $this->filters($organization),
            $filters,
        );

        return $this->downloadXlsx(
            $this->excelFileName($organization, $billingPeriod),
            $this->excelOptions(),
            $this->excelHeadings(),
            fn (): iterable => $query->lazy(500),
            fn (object $record): array => $this->excelCells($record, $billingPeriod),
        );
    }

    /**
     * The screen table and the XLSX export share one filter definition, so both apply identical rules.
     *
     * @return list<BaseFilter>
     */
    private function filters(Organization $organization): array
    {
        return [
            ClientAddressFilter::make($organization, 'clients.region_id', 'clients.street_id'),
            ControllerZoneFilter::make($organization, null),
        ];
    }

    /**
     * Active clients with a positive closing balance, computed in SQL by the engine of the
     * turnover balance sheet, the payment desk and the dashboard, so a client without a
     * receipt still shows the debt carried from the previous periods.
     *
     * @return Builder<Client>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $values = Client::query()
            ->select('clients.*')
            ->visibleToOrganizationMember($user, $organization)
            ->where('clients.status', 'active');

        if ($billingPeriod instanceof BillingPeriod) {
            $values->addSelect(Arr::except(
                TurnoverBalanceValues::openPeriodSubQueries($organization, $billingPeriod),
                [TurnoverBalanceValues::VOLUME],
            ));
        } else {
            foreach (TurnoverBalanceValues::aliases() as $alias) {
                $values->selectRaw("0 as {$alias}");
            }

            $values->whereRaw('1 = 0');
        }

        $prefix = 'client_values.';

        $balances = DB::query()
            ->fromSub($values->toBase(), 'client_values')
            ->select('client_values.*')
            ->selectRaw(TurnoverBalanceValues::openingBalanceExpression($prefix).' as opening_balance')
            ->selectRaw('coalesce('.$prefix.TurnoverBalanceValues::ACCRUED_AMOUNT.', 0) as accrual_amount')
            ->selectRaw('coalesce('.$prefix.TurnoverBalanceValues::PAID_AMOUNT.', 0) as paid_amount')
            ->selectRaw('coalesce('.$prefix.TurnoverBalanceValues::ADJUSTMENT_AMOUNT.', 0) as adjustment_amount')
            ->selectRaw(TurnoverBalanceValues::closingBalanceExpression($prefix).' as debt_amount');

        return Client::query()
            ->fromSub($balances, 'clients')
            ->select('clients.*')
            ->with(['region', 'street'])
            ->where('clients.debt_amount', '>', 0)
            ->orderByDesc('clients.debt_amount')
            ->orderBy('clients.account_number')
            ->orderBy('clients.id');
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'debts-%d-%s-%s.xlsx',
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
        $options->setColumnWidth(14, 4);
        $options->setColumnWidth(18, 5);
        $options->setColumnWidth(16, 6);
        $options->setColumnWidth(16, 7);
        $options->setColumnWidth(16, 8);
        $options->setColumnWidth(16, 9);

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
            'Период',
            'Начальное сальдо',
            'Начислено',
            'Оплачено',
            'Корректировка',
            'Долг',
        ];
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(object $record, ?BillingPeriod $billingPeriod): array
    {
        /** @var Client $client */
        $client = $record;

        return [
            new StringCell((string) $client->account_number, null),
            new StringCell((string) $client->name, null),
            new StringCell($this->formatClientAddress($client), (new Style)->setShouldWrapText()),
            new StringCell($billingPeriod?->label ?? '', null),
            new NumericCell((float) $client->getAttribute('opening_balance'), null),
            new NumericCell((float) $client->getAttribute('accrual_amount'), null),
            new NumericCell((float) $client->getAttribute('paid_amount'), null),
            new NumericCell((float) $client->getAttribute('adjustment_amount'), null),
            new NumericCell((float) $client->getAttribute('debt_amount'), null),
        ];
    }
}
