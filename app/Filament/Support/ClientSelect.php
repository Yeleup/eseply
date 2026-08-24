<?php

namespace App\Filament\Support;

use App\Models\Client;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/**
 * Abonent picker that searches in the database instead of loading every client.
 *
 * A plain `options()` array is rebuilt on every render of the form, so an
 * organization with a few thousand accounts paid for all of them just to pick
 * one. Here the dropdown opens on a bounded first page and every keystroke goes
 * to the database with a limit.
 */
final class ClientSelect
{
    private const int LIMIT = 50;

    public static function make(string $name = 'client_id', string $label = 'Абонент'): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => self::options(null))
            ->getSearchResultsUsing(fn (string $search): array => self::options($search))
            // Keeps the label of the stored value readable on an edit form,
            // where the selected client is usually outside the first page.
            ->getOptionLabelUsing(fn (mixed $value): ?string => self::optionLabel($value))
            ->required()
            ->scopedExists(Client::class, 'id')
            ->native(false);
    }

    /**
     * @return array<int, string>
     */
    private static function options(?string $search): array
    {
        $query = self::query();

        if (! $query instanceof Builder) {
            return [];
        }

        return $query
            ->when(
                filled($search),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('account_number', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                }),
            )
            ->orderBy('account_number')
            ->limit(self::LIMIT)
            ->get()
            ->mapWithKeys(fn (Client $client): array => [$client->id => self::label($client)])
            ->all();
    }

    private static function optionLabel(mixed $value): ?string
    {
        $query = self::query();

        if (blank($value) || ! $query instanceof Builder) {
            return null;
        }

        $client = $query->whereKey($value)->first();

        return $client instanceof Client ? self::label($client) : null;
    }

    private static function label(Client $client): string
    {
        return "{$client->account_number} - {$client->name}";
    }

    /**
     * @return Builder<Client>|null
     */
    private static function query(): ?Builder
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization
            ? Client::query()->whereBelongsTo($tenant)
            : null;
    }
}
