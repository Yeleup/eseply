<?php

namespace App\Filament\Resources\Regions\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class StreetsRelationManager extends RelationManager
{
    protected static string $relationship = 'streets';

    protected static ?string $title = 'Улицы';

    protected static ?string $modelLabel = 'улица';

    protected static ?string $pluralModelLabel = 'улицы';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'streets',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule
                            ->where('organization_id', $this->ownerRecord->organization_id)
                            ->where('region_id', $this->ownerRecord->getKey()),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['organization_id'] = $this->ownerRecord->organization_id;

                        return $data;
                    }),
            ])
            ->recordActions([
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
