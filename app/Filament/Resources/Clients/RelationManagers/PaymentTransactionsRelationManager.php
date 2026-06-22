<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Actions\SyncPaymentTransactionStatus;
use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\PaymentTransaction;
use App\PaymentTransactionStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class PaymentTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentTransactions';

    protected static ?string $title = 'Kaspi-заявки';

    protected static ?string $modelLabel = 'Kaspi-заявка';

    protected static ?string $pluralModelLabel = 'Kaspi-заявки';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('merchant_order_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'billingPeriod',
                    'payment',
                ])
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => PaymentTransactionStatus::labelFor($state) ?? (string) $state)
                    ->color(fn (mixed $state): string => PaymentTransactionStatus::colorFor($state)),
                TextColumn::make('payer_phone')
                    ->label('Телефон')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('external_payment_id')
                    ->label('XPayment ID')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment.id')
                    ->label('Оплата')
                    ->state(fn (PaymentTransaction $record): string => $record->payment_id ? '#'.$record->payment_id : '-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('billing_period_id')
                    ->label('Период')
                    ->options(fn (): array => BillingPeriodOptions::all($this->ownerRecord->organization)),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(PaymentTransactionStatus::class),
            ])
            ->recordActions([
                Action::make('syncStatus')
                    ->label('Синхронизировать')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (PaymentTransaction $record): bool => ! $record->hasFinalStatus() && filled($record->external_payment_id))
                    ->action(function (PaymentTransaction $record): void {
                        try {
                            app(SyncPaymentTransactionStatus::class)->handle($record);
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Не удалось синхронизировать Kaspi-заявку')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Kaspi-заявка синхронизирована')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
