<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\Receipt;
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

class DebtsReport implements OrganizationReport
{
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
        return 'Квитанции текущего расчётного месяца с положительным конечным сальдо.';
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
                TextColumn::make('client_name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Receipt $record): string => $this->formatClientAddress($record->client)),
                TextColumn::make('debt_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('opening_balance')
                    ->label('Начальное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('amount')
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
                TextColumn::make('closing_balance')
                    ->label('Долг')
                    ->money('KZT')
                    ->sortable(),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Долгов нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц положительное конечное сальдо не найдено.'
                : 'Откройте расчётный месяц, чтобы увидеть долги.')
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
     * @return Builder<Receipt>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Receipt::query()
            ->select('receipts.*')
            ->with([
                'billingPeriod',
                'client.region',
                'client.street',
            ])
            ->where('receipts.organization_id', $organization->getKey())
            ->whereHas(
                'client',
                fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organization),
            )
            ->where('receipts.closing_balance', '>', 0)
            ->orderByDesc('closing_balance')
            ->orderBy('account_number')
            ->orderBy('id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('receipts.id', 0);
        }

        return $query->whereBelongsTo($billingPeriod);
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
        /** @var Receipt $receipt */
        $receipt = $record;

        return [
            new StringCell((string) $receipt->account_number, null),
            new StringCell((string) $receipt->client_name, null),
            new StringCell($this->formatClientAddress($receipt->client), (new Style)->setShouldWrapText()),
            new StringCell($billingPeriod?->label ?? '', null),
            new NumericCell((float) $receipt->opening_balance, null),
            new NumericCell((float) $receipt->amount, null),
            new NumericCell((float) $receipt->paid_amount, null),
            new NumericCell((float) $receipt->adjustment_amount, null),
            new NumericCell((float) $receipt->closing_balance, null),
        ];
    }
}
