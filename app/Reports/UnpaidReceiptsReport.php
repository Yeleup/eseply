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

class UnpaidReceiptsReport implements OrganizationReport
{
    use FormatsReportValues;

    public function slug(): string
    {
        return 'unpaid-receipts';
    }

    public function title(): string
    {
        return 'Отчёт по неоплаченным';
    }

    public function description(): ?string
    {
        return 'Квитанции текущего расчётного месяца, где сумма начисления больше суммы оплат.';
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
                TextColumn::make('receipt_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('unpaid_amount_for_report')
                    ->label('Не оплачено')
                    ->state(fn (Receipt $record): float => $this->unpaidAmount($record))
                    ->money('KZT'),
                TextColumn::make('issued_at')
                    ->label('Квитанция сформирована')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Неоплаченных квитанций нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц все квитанции оплачены по начислению.'
                : 'Откройте расчётный месяц, чтобы увидеть неоплаченные квитанции.')
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
            ->whereRaw('receipts.amount > receipts.paid_amount')
            ->orderBy('account_number')
            ->orderBy('id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('receipts.id', 0);
        }

        return $query->whereBelongsTo($billingPeriod);
    }

    private function unpaidAmount(Receipt $receipt): float
    {
        return max(0, (float) $receipt->amount - (float) $receipt->paid_amount);
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'unpaid-receipts-%d-%s-%s.xlsx',
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
        $options->setColumnWidth(16, 5);
        $options->setColumnWidth(16, 6);
        $options->setColumnWidth(16, 7);
        $options->setColumnWidth(20, 8);

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
            'Начислено',
            'Оплачено',
            'Не оплачено',
            'Квитанция сформирована',
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
            new NumericCell((float) $receipt->amount, null),
            new NumericCell((float) $receipt->paid_amount, null),
            new NumericCell($this->unpaidAmount($receipt), null),
            new StringCell($receipt->issued_at?->format('d.m.Y H:i') ?? '', null),
        ];
    }
}
