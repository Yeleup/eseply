<?php

namespace App\Filament\Resources\Meters\RelationManagers;

use App\Models\MeterReading;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderByDesc('period'))
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
                    ->mutateDataUsing(function (array $data): array {
                        $data['previous_reading'] = $this->previousReadingForPeriod($data['period'] ?? null);

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['previous_reading'] = $this->previousReadingForPeriod($data['period'] ?? null);

                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
}
