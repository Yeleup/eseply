<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Support\BillingPeriodOptions;
use App\Filament\Support\OrganizationMemberAccess;
use App\Models\Receipt;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrganizationMemberAccess::canManageTenant()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-receipts.navigation_label');
    }

    protected static function getModelLabel(): ?string
    {
        return __('filament-receipts.model_label');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('filament-receipts.plural_model_label');
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->ownerRecord, $this->pageClass ?? static::class), 403);

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('receipt_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('billingPeriod')
                ->orderByBillingPeriodDesc()
                ->latest('issued_at'))
            ->columns([
                TextColumn::make('receipt_number')
                    ->label(__('filament-receipts.fields.receipt_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->label(__('filament-receipts.fields.period'))
                    ->placeholder('-'),
                TextColumn::make('utility_service_name')
                    ->label(__('filament-receipts.fields.utility_service_name'))
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label(__('filament-receipts.fields.amount'))
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label(__('filament-receipts.fields.paid_amount'))
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('adjustment_amount')
                    ->label(__('filament-receipts.fields.adjustment_amount'))
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('closing_balance')
                    ->label(__('filament-receipts.fields.closing_balance'))
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label(__('filament-receipts.fields.issued_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('billing_period_id')
                    ->label(__('filament-receipts.fields.period'))
                    ->options(fn (): array => BillingPeriodOptions::all($this->ownerRecord->organization)),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('filament-receipts.actions.open'))
                    ->url(fn (Receipt $record): string => route('filament.admin.receipts.print', [
                        'tenant' => Filament::getTenant(),
                        'receipt' => $record,
                    ])),
            ]);
    }
}
