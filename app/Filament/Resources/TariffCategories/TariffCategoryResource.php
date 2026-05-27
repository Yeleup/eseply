<?php

namespace App\Filament\Resources\TariffCategories;

use App\Filament\Resources\TariffCategories\Pages\CreateTariffCategory;
use App\Filament\Resources\TariffCategories\Pages\EditTariffCategory;
use App\Filament\Resources\TariffCategories\Pages\ListTariffCategories;
use App\Filament\Resources\TariffCategories\Schemas\TariffCategoryForm;
use App\Filament\Resources\TariffCategories\Tables\TariffCategoriesTable;
use App\Models\TariffCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TariffCategoryResource extends Resource
{
    protected static ?string $model = TariffCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'категория тарифа';

    protected static ?string $pluralModelLabel = 'категории тарифов';

    protected static ?string $navigationLabel = 'Категории тарифов';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TariffCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TariffCategoriesTable::configure($table);
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
            'index' => ListTariffCategories::route('/'),
            'create' => CreateTariffCategory::route('/create'),
            'edit' => EditTariffCategory::route('/{record}/edit'),
        ];
    }
}
