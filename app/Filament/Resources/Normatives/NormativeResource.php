<?php

namespace App\Filament\Resources\Normatives;

use App\Filament\Resources\Normatives\Pages\CreateNormative;
use App\Filament\Resources\Normatives\Pages\EditNormative;
use App\Filament\Resources\Normatives\Pages\ListNormatives;
use App\Filament\Resources\Normatives\Schemas\NormativeForm;
use App\Filament\Resources\Normatives\Tables\NormativesTable;
use App\Models\Normative;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NormativeResource extends Resource
{
    protected static ?string $model = Normative::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'норматив';

    protected static ?string $pluralModelLabel = 'нормативы';

    protected static ?string $navigationLabel = 'Нормативы';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return NormativeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NormativesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNormatives::route('/'),
            'create' => CreateNormative::route('/create'),
            'edit' => EditNormative::route('/{record}/edit'),
        ];
    }
}
