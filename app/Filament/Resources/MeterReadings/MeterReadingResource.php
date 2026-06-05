<?php

namespace App\Filament\Resources\MeterReadings;

use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\MeterReadings\Schemas\MeterReadingForm;
use App\Filament\Resources\MeterReadings\Tables\MeterReadingsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\MeterReading;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MeterReadingResource extends Resource
{
    protected static ?string $model = MeterReading::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'показание';

    protected static ?string $pluralModelLabel = 'показания';

    protected static ?string $navigationLabel = 'Показания';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'period';

    public static function form(Schema $schema): Schema
    {
        return MeterReadingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MeterReadingsTable::configure($table);
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
            'index' => ListMeterReadings::route('/'),
            'create' => CreateMeterReading::route('/create'),
            'edit' => EditMeterReading::route('/{record}/edit'),
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
        return OrganizationMemberAccess::canAccessTenant();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof MeterReading
            && OrganizationMemberAccess::canViewMeterReading($record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof MeterReading
            && OrganizationMemberAccess::canUpdateMeterReading($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof MeterReading
            && OrganizationMemberAccess::canDeleteMeterReading($record);
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
