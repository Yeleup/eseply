<?php

namespace App\Filament\Resources\Meters\RelationManagers;

use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    protected static ?string $title = 'Показания';

    protected static ?string $modelLabel = 'показание';

    protected static ?string $pluralModelLabel = 'показания';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Показание')
                    ->columns(2)
                    ->schema([
                        Select::make('billing_period_id')
                            ->label('Расчётный месяц')
                            ->options(fn (): array => BillingPeriodOptions::editable($this->ownerRecord->organization))
                            ->helperText('Показание можно внести только в открытый месяц.')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->scopedExists(BillingPeriod::class, 'id')
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('previous_reading', $this->previousReadingForPeriod($state));
                            })
                            ->native(false),
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(fn (Get $get): float => $this->previousReadingForPeriod($get('billing_period_id')))
                            ->readOnly()
                            ->required(),
                        TextInput::make('current_reading')
                            ->label('Текущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('read_at')
                            ->label('Дата ввода')
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
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query
                    ->with('billingPeriod')
                    ->orderByBillingPeriodDesc();

                if ($this->canAccessOwnerRecord()) {
                    return $query;
                }

                return $query->whereRaw('1 = 0');
            })
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('previous_reading')
                    ->label('Предыдущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('current_reading')
                    ->label('Текущее')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('consumption')
                    ->label('Расход')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('read_at')
                    ->label('Дата ввода')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canCreateReadingForOwner())
                    ->mutateDataUsing(function (array $data): array {
                        abort_unless($this->canCreateReadingForOwner(), 403);

                        $data['previous_reading'] = $this->previousReadingForPeriod($data['billing_period_id'] ?? null);

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (MeterReading $record): bool => $this->canEditReading($record))
                    ->mutateDataUsing(function (array $data): array {
                        abort_unless($this->canCreateReadingForOwner(), 403);

                        $data['previous_reading'] = $this->previousReadingForPeriod($data['billing_period_id'] ?? null);

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn (MeterReading $record): bool => OrganizationMemberAccess::canDeleteMeterReading($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => OrganizationMemberAccess::canManageTenant()),
                ]),
            ]);
    }

    protected function previousReadingForPeriod(mixed $billingPeriodId): float
    {
        return MeterReading::previousReadingForBillingPeriod(
            $this->ownerRecord->getKey(),
            $billingPeriodId,
        ) ?? 0;
    }

    private function canAccessOwnerRecord(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $this->ownerRecord instanceof Meter
            && $tenant instanceof Organization
            && $user instanceof User
            && $user->canAccessMeterInOrganization($this->ownerRecord, $tenant);
    }

    private function canCreateReadingForOwner(): bool
    {
        return $this->ownerRecord instanceof Meter
            && OrganizationMemberAccess::canCreateMeterReadingForMeter($this->ownerRecord);
    }

    private function canEditReading(MeterReading $meterReading): bool
    {
        return ($meterReading->billingPeriod?->isEditable() ?? false)
            && $this->canCreateReadingForOwner()
            && OrganizationMemberAccess::canUpdateMeterReading($meterReading);
    }
}
