<?php

namespace App\Filament\Resources\KaspiOperations;

use App\Filament\Resources\KaspiOperations\Pages\ListKaspiOperations;
use App\Filament\Resources\KaspiOperations\Tables\KaspiOperationsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\KaspiOperation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Journal of Kaspi online: one row per Kaspi operation, for disputes, finding
 * errors and the reconciliation with Kaspi's register. Read only.
 */
class KaspiOperationResource extends Resource
{
    protected static ?string $model = KaspiOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $modelLabel = 'операция Kaspi';

    protected static ?string $pluralModelLabel = 'Kaspi онлайн';

    protected static ?string $navigationLabel = 'Kaspi онлайн';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'kaspi-online';

    public static function table(Table $table): Table
    {
        return KaspiOperationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKaspiOperations::route('/'),
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
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
