<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Actions\RecordManualPayment;
use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\CurrentBillingPeriod;
use App\Filament\Support\OrganizationMemberAccess;
use App\Filament\Support\PaymentTableParts;
use App\Models\Payment;
use App\PaymentMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                PaymentTableParts::methodColumn(),
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
                    // Always cash, with the subscriber row locked first (RecordManualPayment).
                    ->using(fn (array $data): Payment => app(RecordManualPayment::class)->handle(
                        $this->ownerRecord->organization,
                        (int) $this->ownerRecord->getKey(),
                        $data,
                        auth()->id(),
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Payment $record): bool => $this->canChange($record)),
                DeleteAction::make()
                    ->visible(fn (Payment $record): bool => $this->canChange($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    PaymentTableParts::deleteBulkAction(),
                ]),
            ]);
    }

    /**
     * Only a payment entered by hand, and only while its month is editable.
     */
    private function canChange(Payment $payment): bool
    {
        return ! $payment->isFromIntegration()
            && ($payment->billingPeriod?->isEditable() ?? false);
    }
}
