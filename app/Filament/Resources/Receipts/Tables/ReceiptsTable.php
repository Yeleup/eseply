<?php

namespace App\Filament\Resources\Receipts\Tables;

use App\Filament\Support\BillingPeriodOptions;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\Street;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ReceiptsTable
{
    private const PRINTABLE_FILTER_KEYS = [
        'billing_period_id',
        'region_id',
        'street_id',
        'controller_id',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'billingPeriod',
                    'client.region',
                    'client.street',
                ])
                ->orderByBillingPeriodDesc()
                ->latest('issued_at'))
            ->columns([
                TextColumn::make('receipt_number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->label('Период')
                    ->placeholder('-'),
                TextColumn::make('account_number')
                    ->label('Лицевой счёт')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client_name')
                    ->label('Абонент')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utility_service_name')
                    ->label('Услуга')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Оплачено')
                    ->money('KZT')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('adjustment_amount')
                    ->label('Корректировка')
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
                SelectFilter::make('billing_period_id')
                    ->label('Период')
                    ->options(fn (): array => BillingPeriodOptions::all()),
                SelectFilter::make('region_id')
                    ->label('Регион')
                    ->options(fn (): array => self::regionOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::applyClientFilter(
                        $query,
                        'region_id',
                        self::filterValue($data),
                    )),
                SelectFilter::make('street_id')
                    ->label('Улица')
                    ->options(fn (): array => self::streetOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::applyClientFilter(
                        $query,
                        'street_id',
                        self::filterValue($data),
                    )),
                SelectFilter::make('controller_id')
                    ->label('Контроллер')
                    ->options(fn (): array => self::controllerOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::applyControllerFilter(
                        $query,
                        self::filterValue($data),
                    )),
            ])
            ->headerActions([
                Action::make('printFiltered')
                    ->label('Печатать по фильтру')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (HasTable $livewire): string => route('filament.admin.receipts.print-bulk', [
                        'tenant' => Filament::getTenant(),
                        ...self::printFilterParameters($livewire),
                    ]))
                    ->openUrlInNewTab()
                    ->visible(fn (HasTable $livewire): bool => self::hasPrintableFilter($livewire)),
            ])
            ->recordActions([
                ViewAction::make(),
                ViewAction::make('print')
                    ->label('Печать')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (Receipt $record): string => route('filament.admin.receipts.print', [
                        'tenant' => Filament::getTenant(),
                        'receipt' => $record,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkAction::make('printSelected')
                    ->label('Печатать выбранные')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->url(fn (Collection $records): string => route('filament.admin.receipts.print-bulk', [
                        'tenant' => Filament::getTenant(),
                        'receipt_ids' => $records->modelKeys(),
                    ]))
                    ->openUrlInNewTab()
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * @return array<string, int>
     */
    private static function printFilterParameters(HasTable $livewire): array
    {
        $parameters = [];

        foreach (self::PRINTABLE_FILTER_KEYS as $filterKey) {
            $value = self::selectedFilterValue($livewire, $filterKey);

            if ($value > 0) {
                $parameters[$filterKey] = $value;
            }
        }

        return $parameters;
    }

    private static function hasPrintableFilter(HasTable $livewire): bool
    {
        foreach (self::PRINTABLE_FILTER_KEYS as $filterKey) {
            if (self::selectedFilterValue($livewire, $filterKey) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function selectedFilterValue(HasTable $livewire, string $filterKey): int
    {
        return (int) ($livewire->getTableFilterState($filterKey)['value'] ?? 0);
    }

    /**
     * @return array<int, string>
     */
    private static function regionOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return [];
        }

        return Region::query()
            ->whereBelongsTo($tenant)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function streetOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return [];
        }

        return Street::query()
            ->with('region')
            ->whereBelongsTo($tenant)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Street $street): array => [
                $street->id => ($street->region?->name ? "{$street->region->name} / " : '').$street->name,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function controllerOptions(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return [];
        }

        return $tenant->users()
            ->wherePivot('role', OrganizationMemberRole::Controller->value)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => "{$user->name} ({$user->email})",
            ])
            ->all();
    }

    /**
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    private static function applyClientFilter(Builder $query, string $column, int $value): Builder
    {
        if ($value <= 0) {
            return $query;
        }

        return $query->whereHas(
            'client',
            fn (Builder $query): Builder => $query->where($query->qualifyColumn($column), $value),
        );
    }

    /**
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    private static function applyControllerFilter(Builder $query, int $controllerId): Builder
    {
        if ($controllerId <= 0) {
            return $query;
        }

        $tenant = Filament::getTenant();
        $controller = User::query()->whereKey($controllerId)->first();

        if (
            ! $tenant instanceof Organization
            || ! $controller instanceof User
            || ! $controller->isOrganizationController($tenant)
        ) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'client',
            fn (Builder $query): Builder => $query->visibleToOrganizationMember($controller, $tenant),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function filterValue(array $data): int
    {
        return (int) ($data['value'] ?? 0);
    }
}
