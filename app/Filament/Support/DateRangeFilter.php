<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

final class DateRangeFilter
{
    public static function make(string $name, string $label, string $column): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                DatePicker::make('start_date')
                    ->label($label.' с')
                    ->native(false),
                DatePicker::make('end_date')
                    ->label($label.' по')
                    ->native(false),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    self::parseDate($data['start_date'] ?? null),
                    fn (Builder $query, CarbonImmutable $date): Builder => $query->whereDate($column, '>=', $date),
                )
                ->when(
                    self::parseDate($data['end_date'] ?? null),
                    fn (Builder $query, CarbonImmutable $date): Builder => $query->whereDate($column, '<=', $date),
                ))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];
                $startDate = self::parseDate($data['start_date'] ?? null);
                $endDate = self::parseDate($data['end_date'] ?? null);

                if ($startDate instanceof CarbonImmutable) {
                    $indicators[] = Indicator::make($label.': с '.$startDate->format('d.m.Y'))
                        ->removeField('start_date');
                }

                if ($endDate instanceof CarbonImmutable) {
                    $indicators[] = Indicator::make($label.': по '.$endDate->format('d.m.Y'))
                        ->removeField('end_date');
                }

                return $indicators;
            });
    }

    /**
     * Livewire filter state is user-controllable raw input, so invalid values are ignored instead of throwing.
     */
    private static function parseDate(mixed $value): ?CarbonImmutable
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
