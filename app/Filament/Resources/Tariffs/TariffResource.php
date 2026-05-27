<?php

namespace App\Filament\Resources\Tariffs;

use App\Filament\Resources\Tariffs\Pages\CreateTariff;
use App\Filament\Resources\Tariffs\Pages\EditTariff;
use App\Filament\Resources\Tariffs\Pages\ListTariffs;
use App\Filament\Resources\Tariffs\Schemas\TariffForm;
use App\Filament\Resources\Tariffs\Tables\TariffsTable;
use App\Models\Tariff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TariffResource extends Resource
{
    protected static ?string $model = Tariff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'тариф';

    protected static ?string $pluralModelLabel = 'тарифы';

    protected static ?string $navigationLabel = 'Тарифы';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return TariffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TariffsTable::configure($table);
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
            'index' => ListTariffs::route('/'),
            'create' => CreateTariff::route('/create'),
            'edit' => EditTariff::route('/{record}/edit'),
        ];
    }
}
