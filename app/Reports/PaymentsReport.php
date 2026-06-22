<?php

namespace App\Reports;

use App\Models\BillingPeriod;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
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

class PaymentsReport implements OrganizationReport
{
    use FormatsReportValues;

    public function slug(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return 'Отчёт по оплатам';
    }

    public function description(): ?string
    {
        return 'Оплаты выбранной организации за текущий открытый или ошибочный расчётный месяц.';
    }

    public function table(Table $table, Organization $organization, User $user): Table
    {
        $billingPeriod = BillingPeriod::currentEditableFor($organization);

        return $table
            ->query($this->query($organization, $user, $billingPeriod))
            ->columns([
                TextColumn::make('client.account_number')
                    ->label('Лицевой счёт')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'client',
                        fn (Builder $query): Builder => $query->where('account_number', 'like', '%'.$search.'%'),
                    )),
                TextColumn::make('client.name')
                    ->label('Абонент')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'client',
                        fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'),
                    )),
                TextColumn::make('client_address')
                    ->label('Адрес')
                    ->state(fn (Payment $record): string => $this->formatClientAddress($record->client)),
                TextColumn::make('payment_period_for_report')
                    ->label('Период')
                    ->state(fn (): string => $billingPeriod?->label ?? '-'),
                TextColumn::make('paid_at')
                    ->label('Дата оплаты')
                    ->date('d.m.Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Способ')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => PaymentMethod::labelFor($state) ?? (string) $state)
                    ->color(fn (mixed $state): string => PaymentMethod::colorFor($state)),
                TextColumn::make('note')
                    ->label('Примечание')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading($billingPeriod instanceof BillingPeriod ? 'Оплат нет' : 'Расчётный месяц не открыт')
            ->emptyStateDescription($billingPeriod instanceof BillingPeriod
                ? 'За текущий расчётный месяц оплаты не найдены.'
                : 'Откройте расчётный месяц, чтобы увидеть оплаты.')
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
     * @return Builder<Payment>
     */
    private function query(Organization $organization, User $user, ?BillingPeriod $billingPeriod): Builder
    {
        $query = Payment::query()
            ->select('payments.*')
            ->with([
                'billingPeriod',
                'client.region',
                'client.street',
            ])
            ->where('payments.organization_id', $organization->getKey())
            ->whereHas(
                'client',
                fn (Builder $query): Builder => $query->visibleToOrganizationMember($user, $organization),
            )
            ->orderBy('paid_at')
            ->orderBy('id');

        if (! $billingPeriod instanceof BillingPeriod) {
            return $query->where('payments.id', 0);
        }

        return $query->whereBelongsTo($billingPeriod);
    }

    private function excelFileName(Organization $organization, ?BillingPeriod $billingPeriod): string
    {
        return sprintf(
            'payments-%d-%s-%s.xlsx',
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
        $options->setColumnWidth(32, 8);

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
            'Дата оплаты',
            'Сумма',
            'Способ',
            'Примечание',
        ];
    }

    /**
     * @return list<Cell>
     */
    private function excelCells(object $record, ?BillingPeriod $billingPeriod): array
    {
        /** @var Payment $payment */
        $payment = $record;

        return [
            new StringCell((string) ($payment->client?->account_number ?? ''), null),
            new StringCell((string) ($payment->client?->name ?? ''), null),
            new StringCell($this->formatClientAddress($payment->client), (new Style)->setShouldWrapText()),
            new StringCell($billingPeriod?->label ?? '', null),
            new StringCell($payment->paid_at?->format('d.m.Y') ?? '', null),
            new NumericCell((float) $payment->amount, null),
            new StringCell(PaymentMethod::labelFor($payment->method) ?? '', null),
            new StringCell((string) ($payment->note ?? ''), null),
        ];
    }
}
