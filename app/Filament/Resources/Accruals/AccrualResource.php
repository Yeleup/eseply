<?php

namespace App\Filament\Resources\Accruals;

use App\Filament\Resources\Accruals\Pages\ListAccruals;
use App\Filament\Resources\Accruals\Schemas\AccrualForm;
use App\Filament\Resources\Accruals\Tables\AccrualsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Accrual;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AccrualResource extends Resource
{
    protected static ?string $model = Accrual::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $modelLabel = 'начисление';

    protected static ?string $pluralModelLabel = 'начисления';

    protected static ?string $navigationLabel = 'Начисления';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function form(Schema $schema): Schema
    {
        return AccrualForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccrualsTable::configure($table);
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
            'index' => ListAccruals::route('/'),
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

    public static function canView(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
