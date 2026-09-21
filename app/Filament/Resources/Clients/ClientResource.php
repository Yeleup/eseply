<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\AccrualsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\BalanceAdjustmentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\MetersRelationManager;
use App\Filament\Resources\Clients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'абонент';

    protected static ?string $pluralModelLabel = 'абоненты';

    protected static ?string $navigationLabel = 'Абоненты';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        if (OrganizationMemberAccess::canAccessTenant() && ! OrganizationMemberAccess::canManageTenant()) {
            return [
                MetersRelationManager::class,
            ];
        }

        return [
            MetersRelationManager::class,
            PaymentsRelationManager::class,
            BalanceAdjustmentsRelationManager::class,
            AccrualsRelationManager::class,
            ReceiptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
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
        return OrganizationMemberAccess::canCreateClients();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Client
            && OrganizationMemberAccess::canViewClient($record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Client
            && OrganizationMemberAccess::canManageClient($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Client
            && OrganizationMemberAccess::canDeleteClient($record);
    }

    public static function canDeleteAny(): bool
    {
        return OrganizationMemberAccess::canManageTenant();
    }
}
