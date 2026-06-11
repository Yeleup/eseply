<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\BalanceAdjustmentType;
use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BalanceAdjustment;
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

class BalanceAdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'balanceAdjustments';

    protected static ?string $title = 'Корректировки сальдо';

    protected static ?string $modelLabel = 'корректировка сальдо';

    protected static ?string $pluralModelLabel = 'корректировки сальдо';

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
                Section::make('Корректировка сальдо')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label('Тип')
                            ->options(BalanceAdjustmentType::class)
                            ->default(BalanceAdjustmentType::ManualAdjustment->value)
                            ->required()
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Сумма')
                            ->numeric()
                            ->step('0.01')
                            ->default(0)
                            ->required(),
                        DatePicker::make('adjusted_at')
                            ->label('Дата корректировки')
                            ->native(false),
                        Textarea::make('note')
                            ->label('Причина / примечание')
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
                ->orderByDesc('adjusted_at')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (BalanceAdjustmentType|string $state): string => BalanceAdjustmentType::labelFor($state) ?? (string) $state),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('adjusted_at')
                    ->label('Дата корректировки')
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
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(BalanceAdjustmentType::class),
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
                    ->visible(fn (BalanceAdjustment $record): bool => $record->billingPeriod?->isEditable() ?? false),
                DeleteAction::make()
                    ->visible(fn (BalanceAdjustment $record): bool => $record->billingPeriod?->isEditable() ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
