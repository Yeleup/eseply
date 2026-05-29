<?php

namespace App\Filament\Resources\Receipts;

use App\Filament\Resources\Receipts\Pages\ListReceipts;
use App\Filament\Resources\Receipts\Pages\ViewReceipt;
use App\Filament\Resources\Receipts\Tables\ReceiptsTable;
use App\Models\Receipt;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ReceiptResource extends Resource
{
    protected static ?string $model = Receipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'квитанция';

    protected static ?string $pluralModelLabel = 'квитанции';

    protected static ?string $navigationLabel = 'Квитанции';

    protected static string|UnitEnum|null $navigationGroup = 'Учёт';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 100;

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Квитанция')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('receipt_number')
                            ->label('Номер'),
                        TextEntry::make('period')
                            ->label('Период'),
                        TextEntry::make('issued_at')
                            ->label('Сформирована')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('account_number')
                            ->label('Лицевой счёт'),
                        TextEntry::make('client_name')
                            ->label('Абонент'),
                        TextEntry::make('utility_service_name')
                            ->label('Услуга')
                            ->placeholder('-'),
                        TextEntry::make('billing_type')
                            ->label('Тип начисления')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'fixed' => 'Фиксированная сумма',
                                'meter' => 'По счётчику',
                                'per_person' => 'На одного человека',
                                default => $state,
                            }),
                        TextEntry::make('volume')
                            ->label('Объём')
                            ->numeric(4)
                            ->placeholder('-'),
                        TextEntry::make('tariff_price')
                            ->label('Тариф')
                            ->money('KZT')
                            ->placeholder('-'),
                    ]),
                Section::make('Сальдо')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('opening_balance')
                            ->label('Начальное сальдо')
                            ->money('KZT'),
                        TextEntry::make('amount')
                            ->label('Начислено')
                            ->money('KZT'),
                        TextEntry::make('paid_amount')
                            ->label('Оплачено')
                            ->money('KZT'),
                        TextEntry::make('closing_balance')
                            ->label('Конечное сальдо')
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
            'view' => ViewReceipt::route('/{record}'),
        ];
    }
}
