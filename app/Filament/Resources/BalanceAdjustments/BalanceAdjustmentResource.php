<?php

namespace App\Filament\Resources\BalanceAdjustments;

use App\Filament\Resources\BalanceAdjustments\Pages\CreateBalanceAdjustment;
use App\Filament\Resources\BalanceAdjustments\Pages\EditBalanceAdjustment;
use App\Filament\Resources\BalanceAdjustments\Pages\ListBalanceAdjustments;
use App\Filament\Resources\BalanceAdjustments\Schemas\BalanceAdjustmentForm;
use App\Filament\Resources\BalanceAdjustments\Tables\BalanceAdjustmentsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BalanceAdjustment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BalanceAdjustmentResource extends Resource
{
    protected static ?string $model = BalanceAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'корректировка сальдо';

    protected static ?string $pluralModelLabel = 'корректировки сальдо';

    protected static ?string $navigationLabel = 'Корректировки сальдо';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 95;

    protected static ?string $recordTitleAttribute = 'period';

    public static function form(Schema $schema): Schema
    {
        return BalanceAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BalanceAdjustmentsTable::configure($table);
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
            'index' => ListBalanceAdjustments::route('/'),
            'create' => CreateBalanceAdjustment::route('/create'),
            'edit' => EditBalanceAdjustment::route('/{record}/edit'),
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
        return $record instanceof BalanceAdjustment
            && ($record->billingPeriod?->isEditable() ?? false)
            && OrganizationMemberAccess::canManageTenant();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof BalanceAdjustment
            && ($record->billingPeriod?->isEditable() ?? false)
            && OrganizationMemberAccess::canManageTenant();
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
