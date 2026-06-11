<?php

namespace App\Filament\Resources\BillingPeriods;

use App\Filament\Resources\BillingPeriods\Pages\CreateBillingPeriod;
use App\Filament\Resources\BillingPeriods\Pages\ListBillingPeriods;
use App\Filament\Resources\BillingPeriods\Schemas\BillingPeriodForm;
use App\Filament\Resources\BillingPeriods\Tables\BillingPeriodsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\BillingPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BillingPeriodResource extends Resource
{
    protected static ?string $model = BillingPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'расчётный месяц';

    protected static ?string $pluralModelLabel = 'расчётные месяцы';

    protected static ?string $navigationLabel = 'Расчётные месяцы';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 55;

    protected static ?string $recordTitleAttribute = 'period';

    public static function form(Schema $schema): Schema
    {
        return BillingPeriodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingPeriodsTable::configure($table);
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
            'index' => ListBillingPeriods::route('/'),
            'create' => CreateBillingPeriod::route('/create'),
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

    public static function canView(Model $record): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
