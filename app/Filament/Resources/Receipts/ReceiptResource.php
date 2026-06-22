<?php

namespace App\Filament\Resources\Receipts;

use App\Actions\BuildReceiptMeterReadingLines;
use App\Filament\Resources\Receipts\Pages\ListReceipts;
use App\Filament\Resources\Receipts\Tables\ReceiptsTable;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Receipt;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ReceiptResource extends Resource
{
    protected static ?string $model = Receipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 100;

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function getModelLabel(): string
    {
        return __('filament-receipts.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-receipts.plural_model_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-receipts.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-receipts.navigation_group');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('filament-receipts.sections.receipt'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('receipt_number')
                            ->label(__('filament-receipts.fields.receipt_number')),
                        TextEntry::make('period')
                            ->label(__('filament-receipts.fields.period')),
                        TextEntry::make('issued_at')
                            ->label(__('filament-receipts.fields.issued_at'))
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('account_number')
                            ->label(__('filament-receipts.fields.account_number')),
                        TextEntry::make('client_name')
                            ->label(__('filament-receipts.fields.client_name')),
                        TextEntry::make('utility_service_name')
                            ->label(__('filament-receipts.fields.utility_service_name'))
                            ->placeholder('-'),
                        TextEntry::make('billing_type')
                            ->label(__('filament-receipts.fields.billing_type'))
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'fixed' => __('filament-receipts.billing_types.fixed'),
                                'meter' => __('filament-receipts.billing_types.meter'),
                                'per_person' => __('filament-receipts.billing_types.per_person'),
                                default => $state,
                            }),
                        TextEntry::make('volume')
                            ->label(__('filament-receipts.fields.volume'))
                            ->numeric(4)
                            ->placeholder('-'),
                        TextEntry::make('tariff_price')
                            ->label(__('filament-receipts.fields.tariff_price'))
                            ->money('KZT')
                            ->placeholder('-'),
                    ]),
                Section::make(__('filament-receipts.sections.meters'))
                    ->schema([
                        RepeatableEntry::make('meter_reading_lines')
                            ->hiddenLabel()
                            ->state(fn (Receipt $record): array => app(BuildReceiptMeterReadingLines::class)->handle($record))
                            ->table([
                                TableColumn::make(__('filament-receipts.meter_columns.meter_number')),
                                TableColumn::make(__('filament-receipts.meter_columns.previous_reading')),
                                TableColumn::make(__('filament-receipts.meter_columns.current_reading')),
                                TableColumn::make(__('filament-receipts.meter_columns.consumption')),
                                TableColumn::make(__('filament-receipts.meter_columns.tariff_price')),
                                TableColumn::make(__('filament-receipts.meter_columns.amount')),
                            ])
                            ->schema([
                                TextEntry::make('meter_number'),
                                TextEntry::make('previous_reading'),
                                TextEntry::make('current_reading'),
                                TextEntry::make('consumption'),
                                TextEntry::make('tariff_price'),
                                TextEntry::make('amount'),
                            ])
                            ->contained(false),
                    ]),
                Section::make(__('filament-receipts.sections.calculation'))
                    ->columns(5)
                    ->schema([
                        TextEntry::make('opening_balance')
                            ->label(__('filament-receipts.fields.opening_balance'))
                            ->money('KZT'),
                        TextEntry::make('amount')
                            ->label(__('filament-receipts.fields.amount'))
                            ->money('KZT'),
                        TextEntry::make('paid_amount')
                            ->label(__('filament-receipts.fields.paid_amount'))
                            ->money('KZT'),
                        TextEntry::make('adjustment_amount')
                            ->label(__('filament-receipts.fields.adjustment_amount'))
                            ->money('KZT'),
                        TextEntry::make('closing_balance')
                            ->label(__('filament-receipts.fields.closing_balance'))
                            ->money('KZT'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ReceiptsTable::configure($table);
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
            'index' => ListReceipts::route('/'),
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
