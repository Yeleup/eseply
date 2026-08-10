<?php

namespace App\Filament\Support;

use App\Models\Organization;
use App\Models\User;
use App\OrganizationMemberRole;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ControllerZoneFilter
{
    /**
     * Keeps rows whose client falls into the responsibility zone of at least one selected controller.
     *
     * @param  string  $clientRelationship  Relationship from the filtered model to its client.
     */
    public static function make(Organization $organization, string $clientRelationship = 'client'): SelectFilter
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
    private static function apply(Builder $query, Organization $organization, string $clientRelationship, array $controllerIds): Builder
    {
        if ($controllerIds === []) {
            return $query;
        }

        $controllers = self::controllersOf($organization, $controllerIds);

        if ($controllers->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            $clientRelationship,
            function (Builder $clientQuery) use ($controllers, $organization): void {
                $clientQuery->where(function (Builder $zoneGroupQuery) use ($controllers, $organization): void {
                    foreach ($controllers as $controller) {
                        $zoneGroupQuery->orWhere(
                            fn (Builder $zoneQuery): Builder => $zoneQuery->visibleToOrganizationMember($controller, $organization),
                        );
                    }
                });
            },
        );
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
