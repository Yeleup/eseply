<?php

namespace App\Filament\Resources\Meters\RelationManagers;

use App\Filament\Support\OrganizationMemberAccess;
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
                        TextInput::make('period')
                            ->label('Период')
                            ->placeholder('202605')
                            ->helperText('Формат: ГГГГММ')
                            ->required()
                            ->length(6)
                            ->regex('/^\d{6}$/')
                            ->rules(['date_format:Ym'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $set('previous_reading', $this->previousReadingForPeriod($state));
                            }),
                        TextInput::make('previous_reading')
                            ->label('Предыдущее показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(fn (Get $get): float => $this->previousReadingForPeriod($get('period')))
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
                $query->orderByDesc('period');

                if ($this->canAccessOwnerRecord()) {
                    return $query;
                }

                return $query->whereRaw('1 = 0');
            })
            ->columns([
                TextColumn::make('period')
                    ->label('Период')
                    ->searchable()
                    ->sortable(),
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

                        $data['previous_reading'] = $this->previousReadingForPeriod($data['period'] ?? null);

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canCreateReadingForOwner())
                    ->mutateDataUsing(function (array $data): array {
                        abort_unless($this->canCreateReadingForOwner(), 403);

                        $data['previous_reading'] = $this->previousReadingForPeriod($data['period'] ?? null);

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

    protected function previousReadingForPeriod(mixed $period): float
    {
        return MeterReading::previousReadingFor(
            $this->ownerRecord->getKey(),
            $period,
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
}
