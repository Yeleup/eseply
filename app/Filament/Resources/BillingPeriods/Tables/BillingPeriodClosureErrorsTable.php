<?php

namespace App\Filament\Resources\BillingPeriods\Tables;

use App\Models\BillingPeriod;
use App\Models\BillingPeriodClosureError;
use App\Reports\BillingPeriodClosureErrorsReport;
use App\Support\BillingClosureIssue;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillingPeriodClosureErrorsTable
{
    /**
     * Number of error rows shown on one page of the report.
     */
    public const int DEFAULT_PAGE_SIZE = 50;

    public static function configure(Table $table, BillingPeriod $billingPeriod): Table
    {
        $report = app(BillingPeriodClosureErrorsReport::class);

        return $table
            ->query($report->query($billingPeriod))
            ->columns([
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('client_name')
                    ->label('ФИО')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->placeholder('Без имени'),
                TextColumn::make('billing_type')
                    ->label('Тип начисления')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $report->billingTypeName($state))
                    ->placeholder('-'),
                TextColumn::make('code')
                    ->label('Код ошибки')
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (?string $state): string => BillingClosureIssue::labelFor($state))
                    ->sortable(),
                TextColumn::make('message')
                    ->label('Причина')
                    ->wrap(),
                TextColumn::make('context')
                    ->label('Контекст')
                    ->state(fn (BillingPeriodClosureError $record): ?string => $report->formatContext($record))
                    ->wrap()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('code')
                    ->label('Код ошибки')
                    ->options(fn (): array => $report->codeOptions($billingPeriod)),
                SelectFilter::make('billing_type')
                    ->label('Тип начисления')
                    ->options(fn (): array => $report->billingTypeOptions()),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(self::DEFAULT_PAGE_SIZE)
            ->emptyStateHeading('Ошибок закрытия нет')
            ->emptyStateDescription('Для этого расчётного месяца не сохранён отчёт ошибок.')
            ->striped();
    }
}
