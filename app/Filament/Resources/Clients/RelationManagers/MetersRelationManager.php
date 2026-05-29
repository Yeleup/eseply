<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Meters\MeterResource;
use App\Models\Meter;
use Filament\Actions\Action;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MetersRelationManager extends RelationManager
{
    protected static string $relationship = 'meters';

    protected static ?string $title = 'Счётчики';

    protected static ?string $modelLabel = 'счётчик';

    protected static ?string $pluralModelLabel = 'счётчики';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Счётчик')
                    ->columns(2)
                    ->schema([
                        TextInput::make('number')
                            ->label('Номер счётчика')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(),
                        TextInput::make('initial_reading')
                            ->label('Начальное показание')
                            ->numeric()
                            ->step('0.0001')
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        DatePicker::make('installed_on')
                            ->label('Дата установки')
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
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('utilityService')
                ->orderBy('number'))
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utilityService.name')
                    ->label('Услуга')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Не указана'),
                TextColumn::make('initial_reading')
                    ->label('Начальное показание')
                    ->numeric(4)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'removed' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('installed_on')
                    ->label('Установлен')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('removed_on')
                    ->label('Снят')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активный',
                        'removed' => 'В архиве',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->organization_id;
                        $data['utility_service_id'] = $this->ownerRecord->utility_service_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Meter $record): string => MeterResource::getUrl('edit', ['record' => $record])),
                Action::make('archive')
                    ->label('Отправить в архив')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Отправить счётчик в архив?')
                    ->modalDescription('Дата снятия будет проставлена сегодняшней датой.')
                    ->modalSubmitActionLabel('Отправить в архив')
                    ->visible(fn (Meter $record): bool => ! $record->isArchived())
                    ->action(function (Meter $record): void {
                        $record->archive();
                    }),
                Action::make('restoreFromArchive')
                    ->label('Вывести из архива')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Вывести счётчик из архива?')
                    ->modalDescription('Дата снятия будет очищена, счётчик снова станет активным.')
                    ->modalSubmitActionLabel('Вывести из архива')
                    ->visible(fn (Meter $record): bool => $record->isArchived())
                    ->action(function (Meter $record): void {
                        $record->restoreFromArchive();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
