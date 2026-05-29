<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Receipts\ReceiptResource;
use App\Models\Receipt;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    protected static ?string $title = 'Квитанции';

    protected static ?string $modelLabel = 'квитанция';

    protected static ?string $pluralModelLabel = 'квитанции';

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('receipt_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->latest('issued_at'))
            ->columns([
                TextColumn::make('receipt_number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->label('Период')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utility_service_name')
                    ->label('Услуга')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Начислено')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('closing_balance')
                    ->label('Конечное сальдо')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label('Сформирована')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label('Период')
                    ->options(fn (): array => $this->ownerRecord
                        ->receipts()
                        ->orderByDesc('period')
                        ->pluck('period', 'period')
                        ->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->url(fn (Receipt $record): string => ReceiptResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
