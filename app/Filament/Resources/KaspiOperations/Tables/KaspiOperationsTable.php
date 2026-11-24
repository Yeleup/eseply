<?php

namespace App\Filament\Resources\KaspiOperations\Tables;

use App\Kaspi\KaspiCommand;
use App\Kaspi\KaspiOperationState;
use App\Models\KaspiOperation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The journal table. Every filter and the search compare columns of
 * `kaspi_operations` directly — no `date()` around a column — so the indexes
 * that start with the organization serve them.
 */
class KaspiOperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['client:id,name']))
            ->defaultSort('received_at', 'desc')
            ->searchPlaceholder('Лицевой счёт или номер Kaspi')
            ->columns([
                TextColumn::make('received_at')
                    ->label('Получен')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('command')
                    ->label('Команда')
                    ->badge()
                    ->color(fn (KaspiCommand $state): string => $state === KaspiCommand::Pay ? 'primary' : 'gray'),
                TextColumn::make('txn_id')
                    ->label('Номер Kaspi')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('txn_id', 'like', self::prefix($search))),
                TextColumn::make('account')
                    ->label('Абонент')
                    ->formatStateUsing(fn (KaspiOperation $record): string => $record->client?->name ?? '—')
                    ->description(fn (KaspiOperation $record): string => 'л/с '.$record->account)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('account', 'like', self::prefix($search))),
                TextColumn::make('sum')
                    ->label('Сумма')
                    ->money('KZT')
                    ->placeholder('—'),
                TextColumn::make('txn_date')
                    ->label('Дата Kaspi')
                    ->dateTime('d.m.Y H:i:s')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Состояние · ответ Kaspi')
                    ->badge()
                    ->description(fn (KaspiOperation $record): string => self::stateDescription($record))
                    ->wrap(),
                TextColumn::make('payment_id')
                    ->label('Оплата')
                    ->formatStateUsing(fn (mixed $state): string => '№ '.$state)
                    ->placeholder('—'),
                TextColumn::make('retries_count')
                    ->label('Повторов')
                    ->description(fn (KaspiOperation $record): ?string => $record->last_retry_at?->format('d.m.Y H:i:s'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('command')
                    ->label('Команда')
                    ->options(KaspiCommand::class),
                self::dateRangeFilter('received_at', 'Дата получения'),
                self::dateRangeFilter('txn_date', 'Дата Kaspi'),
                SelectFilter::make('state')
                    ->label('Состояние')
                    ->options(KaspiOperationState::class),
                TernaryFilter::make('retry_mismatch')
                    ->label('Расхождение в повторе'),
            ])
            ->recordUrl(null)
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Операций Kaspi ещё не было')
            ->emptyStateDescription('Запросы Kaspi появятся здесь, как только Kaspi начнёт присылать их на адрес из профиля организации.');
    }

    /**
     * «ответ: 0 · долг 4 250,00 ₸», «ответ: 4 · идёт закрытие месяца», with the
     * mark of a repeat that brought different data.
     */
    public static function stateDescription(KaspiOperation $record): string
    {
        $parts = ['ответ: '.$record->last_result];

        if ($record->state === KaspiOperationState::Found && $record->debt !== null) {
            $parts[] = 'долг '.number_format((float) $record->debt, 2, ',', ' ').' ₸';
        }

        if (filled($record->state_reason)) {
            $parts[] = mb_strtolower(mb_substr((string) $record->state_reason, 0, 1)).mb_substr((string) $record->state_reason, 1);
        }

        if ($record->retry_mismatch) {
            $parts[] = match ($record->state) {
                KaspiOperationState::Posted => 'расхождение в повторе: проведено по первому запросу',
                KaspiOperationState::Pending => 'расхождение в повторе: проведётся по первому запросу',
                default => 'расхождение в повторе: ответ по первому запросу',
            };
        }

        return implode(' · ', $parts);
    }

    private static function prefix(string $search): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
    }

    private static function dateRangeFilter(string $column, string $label): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                DatePicker::make('from')
                    ->label($label.' с')
                    ->native(false),
                DatePicker::make('until')
                    ->label($label.' по')
                    ->native(false),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    self::date($data['from'] ?? null),
                    fn (Builder $query, CarbonImmutable $date): Builder => $query->where($column, '>=', $date->startOfDay()),
                )
                ->when(
                    self::date($data['until'] ?? null),
                    fn (Builder $query, CarbonImmutable $date): Builder => $query->where($column, '<', $date->startOfDay()->addDay()),
                ))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if (($from = self::date($data['from'] ?? null)) instanceof CarbonImmutable) {
                    $indicators[] = Indicator::make($label.': с '.$from->format('d.m.Y'))->removeField('from');
                }

                if (($until = self::date($data['until'] ?? null)) instanceof CarbonImmutable) {
                    $indicators[] = Indicator::make($label.': по '.$until->format('d.m.Y'))->removeField('until');
                }

                return $indicators;
            });
    }

    /**
     * Filter state comes from the browser, so a broken date is ignored.
     */
    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
