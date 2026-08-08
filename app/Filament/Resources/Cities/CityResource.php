<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\Pages\ListCities;
use App\Filament\Resources\Cities\RelationManagers\RegionsRelationManager;
use App\Filament\Resources\Cities\Schemas\CityForm;
use App\Filament\Resources\Cities\Tables\CitiesTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\City;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'город';

    protected static ?string $pluralModelLabel = 'города';

    protected static ?string $navigationLabel = 'Города';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RegionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canViewAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canCreate(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canEdit(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canDelete(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
