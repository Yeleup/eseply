<?php

namespace App\Filament\Resources\Meters;

use App\Filament\Resources\Meters\Pages\CreateMeter;
use App\Filament\Resources\Meters\Pages\EditMeter;
use App\Filament\Resources\Meters\Pages\ListMeters;
use App\Filament\Resources\Meters\RelationManagers\ReadingsRelationManager;
use App\Filament\Resources\Meters\Schemas\MeterForm;
use App\Filament\Resources\Meters\Tables\MetersTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Meter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $modelLabel = 'счётчик';

    protected static ?string $pluralModelLabel = 'счётчики';

    protected static ?string $navigationLabel = 'Счётчики';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return MeterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ReadingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeters::route('/'),
            'create' => CreateMeter::route('/create'),
            'edit' => EditMeter::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    public static function canViewAny(): bool
    {
        return OrganizationMemberAccess::canAccessTenant();
    }

    public static function canCreate(): bool
    {
        return OrganizationMemberAccess::canCreateMeters();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Meter
            && OrganizationMemberAccess::canViewMeter($record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Meter
            && OrganizationMemberAccess::canManageMeter($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Meter
            && OrganizationMemberAccess::canDeleteMeter($record);
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
