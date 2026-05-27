<?php

namespace App\Filament\Resources\UtilityServices;

use App\Filament\Resources\UtilityServices\Pages\CreateUtilityService;
use App\Filament\Resources\UtilityServices\Pages\EditUtilityService;
use App\Filament\Resources\UtilityServices\Pages\ListUtilityServices;
use App\Filament\Resources\UtilityServices\Schemas\UtilityServiceForm;
use App\Filament\Resources\UtilityServices\Tables\UtilityServicesTable;
use App\Models\UtilityService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UtilityServiceResource extends Resource
{
    protected static ?string $model = UtilityService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'услуга';

    protected static ?string $pluralModelLabel = 'услуги';

    protected static ?string $navigationLabel = 'Услуги';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UtilityServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UtilityServicesTable::configure($table);
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
            'index' => ListUtilityServices::route('/'),
            'create' => CreateUtilityService::route('/create'),
            'edit' => EditUtilityService::route('/{record}/edit'),
        ];
    }
}
