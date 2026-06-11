<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Payment;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                        Select::make('billing_period_id')
                            ->label('Расчётный месяц')
                            ->options(fn (): array => BillingPeriodOptions::editable($this->ownerRecord->organization))
                            ->helperText('Оплату можно внести только в открытый месяц.')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(BillingPeriod::class, 'id')
                            ->native(false),
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
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->organization_id;

                        return $data;
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
