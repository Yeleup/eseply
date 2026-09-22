<?php

namespace App\Filament\Support;

use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ControllerZoneFilter
{
    /**
     * Keeps rows whose client falls into the responsibility zone of at least one selected controller.
     *
     * @param  string|null  $clientRelationship  Relationship from the filtered model to its client,
     *                                           or `null` when the filtered model is the client itself.
     */
    public static function make(Organization $organization, ?string $clientRelationship = 'client'): SelectFilter
    {
        return SelectFilter::make('controller_ids')
            ->label('Контроллеры')
            ->multiple()
            ->searchable()
            ->options(fn (): array => self::controllerOptions($organization))
            ->query(fn (Builder $query, array $data): Builder => self::apply(
                $query,
                $organization,
                $clientRelationship,
                FilterIdentifiers::many($data['values'] ?? null),
            ));
    }

    /**
     * @return array<int, string>
     */
    private static function controllerOptions(Organization $organization): array
    {
        return self::controllersOf($organization)
            ->mapWithKeys(fn (User $controller): array => [
                $controller->id => "{$controller->name} ({$controller->email})",
            ])
            ->all();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $controllerIds
     * @return Builder<TModel>
     */
    private static function apply(Builder $query, Organization $organization, ?string $clientRelationship, array $controllerIds): Builder
    {
        if ($controllerIds === []) {
            return $query;
        }

        $controllers = self::controllersOf($organization, $controllerIds);

        if ($controllers->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $controllerIds = $controllers->modelKeys();
        $regionIds = self::assignedIds('organization_user_regions', 'region_id', $organization, $controllerIds);
        $streetIds = self::assignedIds('organization_user_streets', 'street_id', $organization, $controllerIds);

        if ($regionIds === [] && $streetIds === []) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * The union of the zones of every selected controller, with the same rules as
         * Client::scopeVisibleToOrganizationMember() applies to one controller.
         */
        $zones = function (Builder $clientQuery) use ($organization, $regionIds, $streetIds): void {
            $client = $clientQuery->getModel();

            $clientQuery
                ->where($client->qualifyColumn('organization_id'), $organization->getKey())
                ->where(function (Builder $zoneQuery) use ($client, $regionIds, $streetIds): void {
                    if ($regionIds !== []) {
                        $zoneQuery->whereIn($client->qualifyColumn('region_id'), $regionIds);
                    }

                    if ($streetIds !== []) {
                        $zoneQuery->orWhereIn($client->qualifyColumn('street_id'), $streetIds);
                    }
                });
        };

        if ($clientRelationship === null) {
            return $query->where($zones);
        }

        return $query->whereHas($clientRelationship, $zones);
    }

    /**
     * Zone identifiers assigned to any of the controllers, fetched in one query for all of them.
     *
     * @param  list<int>  $controllerIds
     * @return list<int>
     */
    private static function assignedIds(string $table, string $column, Organization $organization, array $controllerIds): array
    {
        return DB::table($table)
            ->where('organization_id', $organization->getKey())
            ->whereIn('user_id', $controllerIds)
            ->distinct()
            ->pluck($column)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>|null  $controllerIds
     * @return Collection<int, User>
     */
    private static function controllersOf(Organization $organization, ?array $controllerIds = null): Collection
    {
        return $organization->users()
            ->wherePivot('role', OrganizationMemberRole::Controller->value)
            ->when(
                $controllerIds !== null,
                fn (Builder $query): Builder => $query->whereKey($controllerIds ?? []),
            )
            ->orderBy('name')
            ->orderBy('users.id')
            ->get();
    }
}
