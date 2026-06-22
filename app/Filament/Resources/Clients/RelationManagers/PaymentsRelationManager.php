<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Actions\CreateKaspiPaymentTransaction;
use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Payment;
use App\PaymentMethod;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Оплаты';

    protected static ?string $modelLabel = 'оплата';

    protected static ?string $pluralModelLabel = 'оплаты';

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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Оплата')
                    ->columns(2)
                    ->schema([
                        TextInput::make('amount')
                            ->label('Сумма')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->required(),
                        Select::make('method')
                            ->label('Способ оплаты')
                            ->options(PaymentMethod::class)
                            ->default(PaymentMethod::Cash->value)
                            ->required()
                            ->native(false),
                        DatePicker::make('paid_at')
                            ->label('Дата оплаты')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Примечание')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('period')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('billingPeriod')
                ->orderByDesc('paid_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Способ')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => PaymentMethod::labelFor($state) ?? (string) $state)
                    ->color(fn (mixed $state): string => PaymentMethod::colorFor($state)),
                TextColumn::make('paid_at')
                    ->label('Дата оплаты')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('-'),
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
                SelectFilter::make('method')
                    ->label('Способ оплаты')
                    ->options(PaymentMethod::class),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить оплату')
                    ->disabled(fn (): bool => CurrentBillingPeriod::missing($this->ownerRecord->organization))
                    ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip($this->ownerRecord->organization))
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->organization_id;
                        $data['method'] ??= PaymentMethod::Cash->value;
                        $data['received_by_user_id'] = auth()->id();

                        return $data;
                    }),
                Action::make('createKaspiQr')
                    ->label('Создать Kaspi QR')
                    ->icon(Heroicon::OutlinedQrCode)
                    ->color('danger')
                    ->modalHeading('Создать Kaspi QR')
                    ->modalSubmitActionLabel('Создать QR')
                    ->disabled(fn (): bool => CurrentBillingPeriod::missing($this->ownerRecord->organization))
                    ->tooltip(fn (): ?string => CurrentBillingPeriod::missingTooltip($this->ownerRecord->organization))
                    ->schema(fn (): array => [
                        Section::make('Kaspi QR')
                            ->columns(2)
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Сумма')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(1)
                                    ->required(),
                                TextInput::make('payer_phone')
                                    ->label('Телефон плательщика')
                                    ->tel()
                                    ->maxLength(255),
                                Textarea::make('note')
                                    ->label('Примечание')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $paymentTransaction = app(CreateKaspiPaymentTransaction::class)->handle(
                                client: $this->ownerRecord,
                                amount: $data['amount'],
                                payerPhone: $data['payer_phone'] ?? null,
                                note: $data['note'] ?? null,
                            );
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Не удалось создать Kaspi QR')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Kaspi QR создан')
                            ->body($paymentTransaction->qr_url
                                ? "Ссылка QR: {$paymentTransaction->qr_url}"
                                : 'Заявка создана. Ссылка QR не вернулась от XPayment.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Payment $record): bool => $record->billingPeriod?->isEditable() ?? false),
                DeleteAction::make()
                    ->visible(fn (Payment $record): bool => $record->billingPeriod?->isEditable() ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
