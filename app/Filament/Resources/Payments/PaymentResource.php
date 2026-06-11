<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\EditPayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $modelLabel = 'оплата';

    protected static ?string $pluralModelLabel = 'оплаты';

    protected static ?string $navigationLabel = 'Оплаты';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'period';

    public static function form(Schema $schema): Schema
    {
        return PaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
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
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'edit' => EditPayment::route('/{record}/edit'),
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
        return $record instanceof Payment
            && ($record->billingPeriod?->isEditable() ?? false)
            && OrganizationMemberAccess::canManageTenant();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Payment
            && ($record->billingPeriod?->isEditable() ?? false)
            && OrganizationMemberAccess::canManageTenant();
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
