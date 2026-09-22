<?php

namespace App\Filament\Support;

use App\Models\City;
use App\Models\Organization;
use App\Models\Region;
use App\Models\Street;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

final class ClientAddressFilter
{
    /**
     * Cascading city, region and street filter for tables whose rows resolve to a client address.
     *
     * @param  string  $regionColumn  Qualified column holding the client region identifier.
     * @param  string  $streetColumn  Qualified column holding the client street identifier.
     * @param  string|null  $clientRelationship  Relationship from the filtered model to its client when the
     *                                           address columns live on the related client, or `null` when
     *                                           they are available on the filtered query itself.
     */
    public static function make(
        Organization $organization,
        string $regionColumn,
        string $streetColumn,
        ?string $clientRelationship = null,
    ): Filter {
        return Filter::make('address')
            ->label('Адрес')
            ->schema([
                Select::make('city_id')
                    ->label('Город')
                    ->options(fn (): array => self::cityOptions($organization))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('region_id', null);
                        $set('street_ids', []);
                    }),
                Select::make('region_id')
                    ->label('Район')
                    ->options(fn (Get $get): array => self::regionOptions(
                        $organization,
                        FilterIdentifiers::one($get('city_id')),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('street_ids', [])),
                Select::make('street_ids')
                    ->label('Улицы')
                    ->multiple()
                    ->options(fn (Get $get): array => self::streetOptions(
                        $organization,
                        FilterIdentifiers::one($get('city_id')),
                        FilterIdentifiers::one($get('region_id')),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->query(fn (Builder $query, array $data): Builder => self::apply(
                $query,
                $organization,
                $regionColumn,
                $streetColumn,
                $clientRelationship,
                $data,
            ))
            ->indicateUsing(function (array $data) use ($organization): array {
                $indicators = [];
                $cityName = self::cityName($organization, FilterIdentifiers::one($data['city_id'] ?? null));
                $regionName = self::regionName($organization, FilterIdentifiers::one($data['region_id'] ?? null));
                $streetNames = self::streetNames($organization, FilterIdentifiers::many($data['street_ids'] ?? null));

                if ($cityName !== null) {
                    $indicators[] = Indicator::make('Город: '.$cityName)
                        ->removeField('city_id');
                }

                if ($regionName !== null) {
                    $indicators[] = Indicator::make('Район: '.$regionName)
                        ->removeField('region_id');
                }

                if ($streetNames !== []) {
                    $indicators[] = Indicator::make('Улицы: '.implode(', ', $streetNames))
                        ->removeField('street_ids');
                }

                return $indicators;
            });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<TModel>
     */
    private static function apply(
        Builder $query,
        Organization $organization,
        string $regionColumn,
        string $streetColumn,
        ?string $clientRelationship,
        array $data,
    ): Builder {
        $cityId = FilterIdentifiers::one($data['city_id'] ?? null);
        $regionId = FilterIdentifiers::one($data['region_id'] ?? null);
        $streetIds = FilterIdentifiers::many($data['street_ids'] ?? null);

        if ($cityId === null && $regionId === null && $streetIds === []) {
            return $query;
        }

        $address = fn (Builder $query): Builder => $query
            ->when(
                $cityId,
                fn (Builder $query, int $cityId): Builder => $query->whereIn(
                    $regionColumn,
                    self::regionIdsOfCity($organization, $cityId),
                ),
            )
            ->when(
                $regionId,
                fn (Builder $query, int $regionId): Builder => $query->where($regionColumn, $regionId),
            )
            ->when(
                $streetIds,
                fn (Builder $query, array $streetIds): Builder => $query->whereIn($streetColumn, $streetIds),
            );

        if ($clientRelationship === null) {
            return $address($query);
        }

        return $query->whereHas($clientRelationship, $address);
    }

    /**
     * @return array<int, string>
     */
    private static function cityOptions(Organization $organization): array
    {
        return $organization->cities()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function regionOptions(Organization $organization, ?int $cityId): array
    {
        if ($cityId !== null) {
            return $organization->regions()
                ->where('city_id', $cityId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return $organization->regions()
            ->with('city')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Region $region): array => [
                $region->id => ($region->city?->name ? "{$region->city->name} / " : '').$region->name,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function streetOptions(Organization $organization, ?int $cityId, ?int $regionId): array
    {
        if ($regionId !== null) {
            return $organization->streets()
                ->where('region_id', $regionId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return $organization->streets()
            ->when(
                $cityId,
                fn (Builder $query, int $selectedCityId): Builder => $query->whereIn(
                    'region_id',
                    self::regionIdsOfCity($organization, $selectedCityId),
                ),
            )
            ->with('region')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Street $street): array => [
                $street->id => ($street->region?->name ? "{$street->region->name} / " : '').$street->name,
            ])
            ->all();
    }

    /**
     * @return Builder<Region>
     */
    private static function regionIdsOfCity(Organization $organization, int $cityId): Builder
    {
        return Region::query()
            ->select('id')
            ->whereBelongsTo($organization)
            ->where('city_id', $cityId);
    }

    private static function cityName(Organization $organization, ?int $cityId): ?string
    {
        if ($cityId === null) {
            return null;
        }

        return City::query()
            ->whereBelongsTo($organization)
            ->whereKey($cityId)
            ->value('name');
    }

    private static function regionName(Organization $organization, ?int $regionId): ?string
    {
        if ($regionId === null) {
            return null;
        }

        return Region::query()
            ->whereBelongsTo($organization)
            ->whereKey($regionId)
            ->value('name');
    }

    /**
     * @param  list<int>  $streetIds
     * @return list<string>
     */
    private static function streetNames(Organization $organization, array $streetIds): array
    {
        if ($streetIds === []) {
            return [];
        }

        return Street::query()
            ->whereBelongsTo($organization)
            ->whereKey($streetIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
