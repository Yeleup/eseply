<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Organization;
use App\OrganizationMemberRole;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Controllers of an organization whose responsibility zone covers a client.
 *
 * A client is attached to a controller when its region is assigned to the controller
 * (`organization_user_regions`) or its street is (`organization_user_streets`), with the same
 * rules as Client::scopeVisibleToOrganizationMember() applies to one controller. The zones of
 * every controller of the organization are loaded once (one query per zone table), so any number
 * of clients is matched without further queries.
 */
final class ClientControllers
{
    /**
     * @param  array<int, array<int, string>>  $controllerNamesByRegion  Controller names keyed by region id, then by user id.
     * @param  array<int, array<int, string>>  $controllerNamesByStreet  Controller names keyed by street id, then by user id.
     */
    private function __construct(
        private readonly array $controllerNamesByRegion,
        private readonly array $controllerNamesByStreet,
    ) {}

    public static function forOrganization(Organization|int|string $organization): self
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return new self(
            self::controllerNamesByZone('organization_user_regions', 'region_id', $organizationId),
            self::controllerNamesByZone('organization_user_streets', 'street_id', $organizationId),
        );
    }

    /**
     * Names of the client's controllers, each once, in alphabetical order.
     *
     * @return list<string>
     */
    public function namesFor(Client $client): array
    {
        $names = ($this->controllerNamesByRegion[(int) $client->region_id] ?? [])
            + ($this->controllerNamesByStreet[(int) $client->street_id] ?? []);

        $names = array_values($names);

        usort($names, fn (string $first, string $second): int => strnatcmp(mb_strtolower($first), mb_strtolower($second)));

        return $names;
    }

    /**
     * Names of the client's controllers separated by commas, or «-» when there are none.
     */
    public function labelFor(Client $client): string
    {
        $names = $this->namesFor($client);

        return $names === [] ? '-' : implode(', ', $names);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function controllerNamesByZone(string $table, string $column, int|string $organizationId): array
    {
        $names = [];

        DB::table($table)
            ->join('organization_user', function (JoinClause $join) use ($table): void {
                $join
                    ->on('organization_user.organization_id', '=', "{$table}.organization_id")
                    ->on('organization_user.user_id', '=', "{$table}.user_id");
            })
            ->join('users', 'users.id', '=', "{$table}.user_id")
            ->where("{$table}.organization_id", $organizationId)
            ->where('organization_user.role', OrganizationMemberRole::Controller->value)
            ->select(["{$table}.{$column} as zone_id", 'users.id as user_id', 'users.name'])
            ->get()
            ->each(function (object $row) use (&$names): void {
                $names[(int) $row->zone_id][(int) $row->user_id] = (string) $row->name;
            });

        return $names;
    }
}
